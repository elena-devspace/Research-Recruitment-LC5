<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/recruitment-lock.php';

requireRole('academic');

$user = currentUser();
$pdo = getConnection();

$researcherId = (int) $user['user_id'];

function escapeInvite($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$verifyStmt = $pdo->prepare("
    SELECT verification_status
    FROM academic_profiles
    WHERE user_id = ?
    LIMIT 1
");

$verifyStmt->execute([$researcherId]);

if ($verifyStmt->fetchColumn() !== 'verified') {
    http_response_code(403);
    exit(
        'Access denied. Researcher verification required.'
    );
}

$volunteerId = filter_input(
    INPUT_GET,
    'volunteer_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($volunteerId)) {
    http_response_code(400);
    exit('Invalid volunteer ID.');
}

$volunteerStmt = $pdo->prepare("
    SELECT
        u.user_id
    FROM users u
    INNER JOIN volunteer_profiles vp
        ON vp.user_id = u.user_id
    WHERE u.user_id = ?
      AND u.role = 'volunteer'
      AND vp.visible_to_researchers = 1
    LIMIT 1
");

$volunteerStmt->execute([$volunteerId]);

$volunteer = $volunteerStmt->fetch(PDO::FETCH_ASSOC);

if (!$volunteer) {
    http_response_code(404);
    exit(
        'Volunteer unavailable or not visible to researchers.'
    );
}

$studyStmt = $pdo->prepare("
    SELECT
        study_id,
        title,
        is_test
    FROM studies
    WHERE researcher_id = ?
      AND status = 'open'
    ORDER BY study_id DESC
");

$studyStmt->execute([$researcherId]);

$studies = $studyStmt->fetchAll(PDO::FETCH_ASSOC);

$allowedStudyIds = array_map(
    'intval',
    array_column($studies, 'study_id')
);

if (empty($_SESSION['request_csrf'])) {
    $_SESSION['request_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$error = '';
$message = '';
$selectedStudyId = 0;

$success = (
    ($_SESSION['invitation_sent_to'] ?? null) === $volunteerId
);

unset($_SESSION['invitation_sent_to']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION['request_csrf'],
            $token
        )
    ) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $studyId = filter_input(
        INPUT_POST,
        'study_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $selectedStudyId = is_int($studyId)
        ? $studyId
        : 0;

    $rawMessage = $_POST['message'] ?? '';

    $message = is_string($rawMessage)
        ? trim($rawMessage)
        : '';

    if (
        !is_int($studyId) ||
        !in_array(
            $studyId,
            $allowedStudyIds,
            true
        )
    ) {

        $error = 'Please select a valid research study.';

    } elseif (strlen($message) > 1000) {

        $error = 'Your message must be under 1000 bytes.';

    } else {

        try {

            $conflict = runRecruitmentWrite(
                $pdo,
                $studyId,
                $volunteerId,

                function () use (
                    $pdo,
                    $studyId,
                    $researcherId,
                    $volunteerId,
                    $message
                ): void {

                    $insert = $pdo->prepare("
                        INSERT INTO participation_requests
                        (
                            study_id,
                            researcher_id,
                            volunteer_id,
                            message,
                            status,
                            requested_at
                        )
                        SELECT
                            s.study_id,
                            s.researcher_id,
                            v.user_id,
                            ?,
                            'pending',
                            NOW()
                        FROM studies s

                        INNER JOIN users r
                            ON r.user_id = s.researcher_id

                        INNER JOIN academic_profiles ap
                            ON ap.user_id = r.user_id

                        INNER JOIN users v
                            ON v.user_id = ?

                        INNER JOIN volunteer_profiles vp
                            ON vp.user_id = v.user_id

                        WHERE s.study_id = ?
                          AND s.researcher_id = ?
                          AND s.status = 'open'
                          AND r.role = 'academic'
                          AND ap.verification_status = 'verified'
                          AND v.role = 'volunteer'
                          AND vp.visible_to_researchers = 1
                    ");

                    $insert->execute([
                        $message !== ''
                            ? $message
                            : null,
                        $volunteerId,
                        $studyId,
                        $researcherId
                    ]);

                    if ($insert->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Study or volunteer unavailable.'
                        );
                    }
                }
            );

            if ($conflict === 'application') {

                $error =
                    'This volunteer has already applied ' .
                    'to this study. An invitation cannot be sent.';

            } elseif ($conflict === 'invitation') {

                $error =
                    'An invitation has already been sent ' .
                    'to this volunteer for this study.';

            } elseif ($conflict === 'study_missing') {

                $error = 'This research study is unavailable.';

            } else {

                $_SESSION['invitation_sent_to'] = $volunteerId;

                header(
                    'Location: send-request.php?volunteer_id=' .
                    $volunteerId
                );

                exit;
            }

        } catch (Throwable $exception) {

            error_log(
                'Invitation error: ' .
                $exception->getMessage()
            );

            if (
                $exception instanceof PDOException &&
                $exception->getCode() === '23000'
            ) {

                $error =
                    'A participation record already exists ' .
                    'for this volunteer and study.';

            } else {

                $error =
                    'Unable to send the invitation. ' .
                    'Please try again.';
            }
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Invite Volunteer - Research Recruitment
    </title>
</head>
<body>

<h1>Research Recruitment</h1>

<h2>Invite Volunteer</h2>

<h3>Volunteer #<?= (int) $volunteerId ?></h3>
<p>Personal contact details are private until this volunteer accepts an invitation.</p>

<p>
    Volunteer ID:
    <?= (int) $volunteerId ?>
</p>

<?php if ($success): ?>

    <p role="status">
        Participation request sent successfully!
    </p>

<?php endif; ?>

<?php if ($error !== ''): ?>

    <p role="alert">
        <?= escapeInvite($error) ?>
    </p>

<?php endif; ?>

<?php if (empty($studies)): ?>

    <p>
        You have no open research studies
        available for invitations.
    </p>

<?php else: ?>

    <h3>Send Research Invitation</h3>

    <form
        method="POST"
        action="send-request.php?volunteer_id=<?= (int) $volunteerId ?>"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= escapeInvite($_SESSION['request_csrf']) ?>"
        >

        <p>
            <label for="study_id">
                Choose Research Study:
            </label>

            <select
                id="study_id"
                name="study_id"
                required
            >

                <option value="">
                    Select study
                </option>

                <?php foreach ($studies as $study): ?>

                    <option
                        value="<?= (int) $study['study_id'] ?>"
                        <?= $selectedStudyId === (int) $study['study_id']
                            ? 'selected'
                            : '' ?>
                    >

                        <?= escapeInvite($study['title']) ?>

                        <?= (int) $study['is_test'] === 1
                            ? ' (Fictional Test Study)'
                            : '' ?>

                    </option>

                <?php endforeach; ?>

            </select>
        </p>

        <p>
            <label for="message">
                Invitation Message:
            </label>
        </p>

        <textarea
            id="message"
            name="message"
            rows="5"
            cols="55"
            maxlength="1000"
        ><?= escapeInvite($message) ?></textarea>

        <p>
            <button type="submit">
                Send Invitation
            </button>
        </p>

    </form>

<?php endif; ?>

<p>
    <a href="find-volunteers.php">
        Back to Find Volunteers
    </a>
</p>

<p>
    <a href="my-sent-requests.php">
        View My Sent Invitations
    </a>
</p>

<p>
    <a href="academic-dashboard.php">
        Back to Academic Dashboard
    </a>
</p>

</body>
</html>
