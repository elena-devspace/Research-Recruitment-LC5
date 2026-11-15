<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/recruitment-lock.php';
require_once __DIR__ . '/study-capacity.php';

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

$capacityByStudy = [];
$availableStudyCount = 0;

foreach ($studies as $study) {
    $studyId = (int) $study['study_id'];
    $capacityByStudy[$studyId] = getStudyCapacity($pdo, $studyId);
    $remaining = $capacityByStudy[$studyId]['remaining'];

    if ($remaining === null || $remaining > 0) {
        $availableStudyCount++;
    }
}

$allowedStudyIds = array_map(
    'intval',
    array_column($studies, 'study_id')
);

if (empty($_SESSION['request_csrf'])) {
    $_SESSION['request_csrf'] = bin2hex(
        random_bytes(32)
    );
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

                    $capacity = getStudyCapacity($pdo, $studyId, true);

                    if ($capacity === null) {
                        throw new RuntimeException('Study unavailable.');
                    }

                    if (
                        $capacity['remaining'] !== null &&
                        $capacity['remaining'] < 1
                    ) {
                        throw new DomainException('study_full');
                    }

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
                $exception instanceof DomainException &&
                $exception->getMessage() === 'study_full'
            ) {
                $error = 'This research study is full. No further invitations can be sent.';
            } elseif (
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invite Volunteer | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/send-request.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="academic-dashboard.php">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="academic-dashboard.php">Dashboard</a>
            <a href="my-studies.php">My studies</a>
            <a href="review-applications.php">Applications</a>
            <a href="find-volunteers.php" aria-current="page">Find volunteers</a>
            <a href="my-sent-requests.php">Invitations</a>
            <a href="profile.php">My profile</a>
            <form action="logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= escapeInvite($_SESSION['csrf_token']) ?>">
                <button class="dashboard-logout__button" type="submit">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell invite-page">
    <a class="invite-back" href="find-volunteers.php">← Back to find volunteers</a>

    <section class="invite-hero" aria-labelledby="invite-title">
        <div>
            <span class="eyebrow">Your research workspace</span>
            <h1 id="invite-title">Invite a research volunteer</h1>
            <p>Welcome back, <?= escapeInvite($user['first_name'] ?? '') ?>! Choose one of your open studies and send a private invitation to a suitable volunteer.</p>
        </div>
        <a class="btn btn--primary invite-hero__action" href="my-sent-requests.php">Sent invitations</a>
    </section>

    <?php if ($success): ?>
        <div class="feedback feedback--success invite-feedback" role="status">
            Participation request sent successfully! The volunteer can respond from their invitations page.
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="feedback feedback--error invite-feedback" role="alert">
            <?= escapeInvite($error) ?>
        </div>
    <?php endif; ?>

    <div class="invite-section-title">
        <span class="eyebrow">Research invitation</span>
        <h2>Prepare your invitation</h2>
        <p>Only verified researchers can invite volunteers who have opted into discovery.</p>
    </div>

    <div class="invite-layout">
        <section class="invite-card invite-card--form" aria-labelledby="invite-form-title">
            <div class="invite-card__heading">
                <span class="invite-step">01</span>
                <div>
                    <h3 id="invite-form-title">Send a research invitation</h3>
                    <p>Choose a study, add an optional message and send your request.</p>
                </div>
            </div>

            <?php if (empty($studies)): ?>
                <div class="invite-empty" role="status">
                    <strong>No open studies available</strong>
                    <p>Create and publish a research study before inviting volunteers.</p>
                    <a class="btn btn--primary" href="my-studies.php">Go to my studies</a>
                </div>
            <?php else: ?>
                <?php if ($availableStudyCount === 0): ?>
                    <div class="invite-empty" role="status">
                        <strong>All your open studies are full</strong>
                        <p>No further invitations can be sent until a study has capacity.</p>
                    </div>
                <?php endif; ?>

                <form method="post" action="send-request.php?volunteer_id=<?= (int) $volunteerId ?>" class="invite-form">
                    <input type="hidden" name="csrf_token" value="<?= escapeInvite($_SESSION['request_csrf']) ?>">

                    <div class="invite-field">
                        <label for="study_id">Choose research study <span aria-hidden="true">*</span></label>
                        <select id="study_id" name="study_id" required <?= $availableStudyCount === 0 ? 'disabled' : '' ?>>
                            <option value="">Select an open study</option>
                            <?php foreach ($studies as $study): ?>
                                <?php
                                $remaining = $capacityByStudy[(int) $study['study_id']]['remaining'];
                                $isFull = $remaining !== null && $remaining < 1;
                                ?>
                                <option
                                    value="<?= (int) $study['study_id'] ?>"
                                    <?= $selectedStudyId === (int) $study['study_id'] ? 'selected' : '' ?>
                                    <?= $isFull ? 'disabled' : '' ?>
                                ><?= escapeInvite($study['title']) ?><?= (int) $study['is_test'] === 1 ? ' (Fictional test study)' : '' ?><?= $isFull ? ' — Full (0 spaces)' : ($remaining === null ? ' — No capacity limit' : ' — ' . (int) $remaining . ' spaces left') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="invite-field__help">Full studies are unavailable. Invitations still require the study to remain open and have capacity.</p>
                    </div>

                    <div class="invite-field">
                        <label for="message">Invitation message <span class="invite-optional">(optional)</span></label>
                        <textarea id="message" name="message" rows="7" maxlength="1000" placeholder="Tell the volunteer why their profile could be a good fit for your research…"><?= escapeInvite($message) ?></textarea>
                        <p class="invite-field__help">Maximum 1,000 bytes. Your message is visible to the invited volunteer.</p>
                    </div>

                    <div class="invite-submit">
                        <p>Your invitation will appear in the volunteer's invitation history.</p>
                        <button class="btn btn--primary invite-submit__button" type="submit" <?= $availableStudyCount === 0 ? 'disabled' : '' ?>>Send invitation</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <aside class="invite-card invite-card--details" aria-label="Volunteer and privacy details">
            <div class="invite-card__heading">
                <span class="invite-step">02</span>
                <div>
                    <h3>Volunteer details</h3>
                    <p>Privacy is protected during recruitment.</p>
                </div>
            </div>

            <div class="invite-volunteer">
                <div class="invite-volunteer__avatar" aria-hidden="true">#<?= (int) $volunteerId ?></div>
                <div>
                    <h4>Volunteer #<?= (int) $volunteerId ?></h4>
                    <p>Research volunteer · ID <?= (int) $volunteerId ?></p>
                </div>
            </div>

            <div class="invite-detail-box">
                <span>Profile visibility</span>
                <strong>Opted into researcher searches</strong>
            </div>
            <div class="invite-detail-box">
                <span>Volunteer identity</span>
                <strong>Hidden until participation is accepted</strong>
            </div>

            <div class="invite-privacy-note">
                <strong>Volunteer privacy</strong>
                <p>Names and contact details are not available at this stage. Send an invitation and wait for the volunteer to accept before contacting them.</p>
            </div>

            <a class="btn invite-btn--light invite-details__link" href="find-volunteers.php">Return to volunteer search</a>
        </aside>
    </div>

    <aside class="invite-shortcuts" aria-label="Research navigation">
        <div>
            <h2>Keep your recruitment organised</h2>
            <p>Track invitations, manage studies or return to your dashboard.</p>
        </div>
        <div class="invite-shortcuts__links">
            <a class="btn invite-btn--light" href="my-sent-requests.php">Sent invitations</a>
            <a class="btn invite-btn--light" href="my-studies.php">My studies</a>
            <a class="btn invite-btn--light" href="academic-dashboard.php">Dashboard</a>
        </div>
    </aside>
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <span>© 2026 Research Recruitment · KV6014 university project</span>
        <div class="site-footer__items" aria-label="Site information">
            <span>Privacy Policy</span><span>Contact</span><span>Terms</span>
        </div>
    </div>
</footer>
</body>
</html>
