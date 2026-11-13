<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/recruitment-lock.php';

// Only volunteers can apply for studies.
$user = requireRole('volunteer');
$pdo = getConnection();

$volunteerId = (int) $user['user_id'];

// Fictional volunteer accounts authorised for testing.
$testAccountIds = [1, 6, 7];

$isTestAccount = in_array(
    $volunteerId,
    $testAccountIds,
    true
);

// Escape displayed database values.
function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Validate the study ID.
$studyId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($studyId)) {
    http_response_code(400);
    exit('Invalid study ID.');
}

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}

// Find an available research study.
// Fictional studies are restricted to test accounts.
$studyStmt = $pdo->prepare("
    SELECT
        s.study_id,
        s.title,
        s.topic,
        s.description,
        s.participation_tasks,
        s.eligibility_requirements,
        s.participation_format,
        s.is_test,
        s.application_deadline
    FROM studies s

    INNER JOIN users u
        ON u.user_id = s.researcher_id

    INNER JOIN academic_profiles ap
        ON ap.user_id = u.user_id

    WHERE s.study_id = ?
      AND s.status = 'open'
      AND u.role = 'academic'
      AND ap.verification_status = 'verified'
      AND s.application_deadline >= CURDATE()
      AND s.end_date >= CURDATE()
      AND (
          s.is_test = 0
          OR (s.is_test = 1 AND ? = 1)
      )
    LIMIT 1
");

$studyStmt->execute([
    $studyId,
    $isTestAccount ? 1 : 0
]);

$study = $studyStmt->fetch(PDO::FETCH_ASSOC);

if (!$study) {
    http_response_code(404);
    exit('Study unavailable or access denied.');
}

// Check for an existing volunteer application.
$applicationCheck = $pdo->prepare("
    SELECT application_id, status
    FROM study_applications
    WHERE study_id = ?
      AND volunteer_id = ?
    LIMIT 1
");

$applicationCheck->execute([
    $studyId,
    $volunteerId
]);

$existing = $applicationCheck->fetch(
    PDO::FETCH_ASSOC
);

// Check for an existing research invitation.
$invitationCheck = $pdo->prepare("
    SELECT request_id, status
    FROM participation_requests
    WHERE study_id = ?
      AND volunteer_id = ?
    ORDER BY request_id DESC
    LIMIT 1
");

$invitationCheck->execute([
    $studyId,
    $volunteerId
]);

$invitation = $invitationCheck->fetch(
    PDO::FETCH_ASSOC
);

$error = '';
$message = '';

// Display a one-time success message after redirect.
$success = (
    ($_SESSION['application_success_id'] ?? null) === $studyId
);

unset($_SESSION['application_success_id']);

// Handle application submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;
    $rawMessage = $_POST['message'] ?? '';

    $message = is_string($rawMessage)
        ? trim($rawMessage)
        : '';

    // Validate CSRF protection.
    if (
        !is_string($token) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $token
        )
    ) {

        http_response_code(403);
        $error = 'Invalid request. Refresh the page.';

    } elseif ($existing) {

        // Prevent duplicate applications.
        $error =
            'You have already applied to this study.';

    } elseif ($invitation) {

        // Any existing invitation blocks new applications.
        $error =
            'You have already received an invitation ' .
            'for this study. A separate application ' .
            'cannot be submitted.';

    } elseif (!is_string($rawMessage)) {

        $error = 'Invalid application message.';

    } elseif (strlen($message) > 1000) {

        $error =
            'Your application message must be ' .
            'under 1000 bytes.';

    } else {

        try {

            /*
             * Shared database transaction protection.
             *
             * Locks the study record and checks for:
             *
             * 1. Existing applications.
             * 2. Existing invitations.
             *
             * Prevents simultaneous application and
             * invitation submissions from both succeeding
             * when both pages use the shared lock.
             */
            $conflict = runRecruitmentWrite(
                $pdo,
                $studyId,
                $volunteerId,

                function () use (
                    $pdo,
                    $studyId,
                    $volunteerId,
                    $message,
                    $isTestAccount
                ): void {

                    /*
                     * Recheck study availability
                     * during database insertion.
                     */
                    $insert = $pdo->prepare("
                        INSERT INTO study_applications
                        (
                            study_id,
                            volunteer_id,
                            message
                        )

                        SELECT
                            s.study_id,
                            ?,
                            ?

                        FROM studies s

                        INNER JOIN users u
                            ON u.user_id = s.researcher_id

                        INNER JOIN academic_profiles ap
                            ON ap.user_id = u.user_id

                        WHERE s.study_id = ?
                          AND s.status = 'open'
                          AND u.role = 'academic'
                          AND ap.verification_status = 'verified'
                          AND s.application_deadline >= CURDATE()
                          AND s.end_date >= CURDATE()

                          AND (
                              s.is_test = 0
                              OR (
                                  s.is_test = 1
                                  AND ? = 1
                              )
                          )

                          AND NOT EXISTS (
                              SELECT 1
                              FROM participation_requests pr
                              WHERE pr.study_id = s.study_id
                                AND pr.volunteer_id = ?
                          )
                    ");

                    $insert->execute([
                        $volunteerId,
                        $message !== ''
                            ? $message
                            : null,
                        $studyId,
                        $isTestAccount ? 1 : 0,
                        $volunteerId
                    ]);

                    if ($insert->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Study unavailable or invitation exists.'
                        );
                    }
                }
            );

            // Handle existing participation records.
            if ($conflict === 'application') {

                $error =
                    'You have already applied to this study.';

            } elseif ($conflict === 'invitation') {

                $error =
                    'You already have an invitation ' .
                    'for this study. A separate ' .
                    'application cannot be submitted.';

            } elseif ($conflict === 'study_missing') {

                $error = 'This research study is unavailable.';

            } else {

                // Application inserted successfully.
                $_SESSION['application_success_id'] = $studyId;

                header(
                    'Location: apply-study.php?id=' . $studyId
                );

                exit;
            }

        } catch (Throwable $exception) {

            // Handle database constraint violations.
            if (
                $exception instanceof PDOException &&
                $exception->getCode() === '23000'
            ) {

                $error =
                    'A participation record already exists ' .
                    'for this study.';

            } else {

                error_log(
                    'Application error: ' .
                    $exception->getMessage()
                );

                $error =
                    'Unable to submit application. ' .
                    'Please try again.';
            }
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Refresh application status after submission.
$applicationCheck->execute([
    $studyId,
    $volunteerId
]);

$existing = $applicationCheck->fetch(
    PDO::FETCH_ASSOC
);

// Refresh invitation status after submission.
$invitationCheck->execute([
    $studyId,
    $volunteerId
]);

$invitation = $invitationCheck->fetch(
    PDO::FETCH_ASSOC
);
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
        Apply for Research Study
    </title>
</head>
<body>

<h1>Research Recruitment</h1>

<h2>Apply for Research Study</h2>

<h3>
    <?= e($study['title']) ?>
</h3>

<?php if ((int) $study['is_test'] === 1): ?>

    <p>
        <strong>FICTIONAL TEST STUDY</strong>
        — No real participation or payment.
    </p>

<?php endif; ?>

<p>
    Topic:
    <?= e($study['topic']) ?>
</p>

<h4>Description</h4>

<p>
    <?= nl2br(e($study['description'])) ?>
</p>

<h4>Participant Tasks</h4>

<p>
    <?= nl2br(e($study['participation_tasks'])) ?>
</p>

<h4>Eligibility Requirements</h4>

<p>
    <?= nl2br(e($study['eligibility_requirements'])) ?>
</p>

<h4>Application Deadline</h4>

<p>
    <?= e($study['application_deadline']) ?>
</p>

<hr>

<?php if ($success): ?>

    <p role="status">
        Application submitted successfully!
    </p>

<?php endif; ?>

<?php if ($error !== ''): ?>

    <p role="alert">
        <?= e($error) ?>
    </p>

<?php endif; ?>

<?php if ($existing): ?>

    <h3>Your Application</h3>

    <p>
        Current status:
        <strong>
            <?= e(ucfirst($existing['status'])) ?>
        </strong>
    </p>

    <?php if ($existing['status'] === 'pending'): ?>

        <p>
            Your application has been submitted
            and is awaiting researcher review.
        </p>

    <?php elseif ($existing['status'] === 'accepted'): ?>

        <p>
            Your application has been accepted
            by the researcher.
        </p>

    <?php elseif ($existing['status'] === 'rejected'): ?>

        <p>
            Your application was not accepted
            by the researcher.
        </p>

    <?php elseif ($existing['status'] === 'withdrawn'): ?>

        <p>
            You have withdrawn your application
            for this study.
        </p>

    <?php else: ?>

        <p>
            You have already submitted an application
            for this study.
        </p>

    <?php endif; ?>

    <p>
        A duplicate application cannot be submitted.
    </p>

    <p>
        <a href="my-applications.php">
            View My Applications
        </a>
    </p>

<?php endif; ?>

<?php if ($invitation): ?>

    <h3>Your Research Invitation</h3>

    <p>
        Invitation status:
        <strong>
            <?= e(ucfirst($invitation['status'])) ?>
        </strong>
    </p>

    <?php if ($invitation['status'] === 'pending'): ?>

        <p>
            You have already received an invitation
            to participate in this study.
        </p>

        <p>
            Please respond to your invitation
            instead of submitting a new application.
        </p>

    <?php elseif ($invitation['status'] === 'accepted'): ?>

        <p>
            You have already accepted an invitation
            to participate in this study.
        </p>

        <p>
            A separate application is not necessary.
        </p>

    <?php elseif ($invitation['status'] === 'declined'): ?>

        <p>
            You previously declined an invitation
            to this study.
        </p>

        <p>
            A new application cannot be submitted
            for a study with an existing invitation.
        </p>

    <?php else: ?>

        <p>
            An invitation already exists for this study.
            A separate application cannot be submitted.
        </p>

    <?php endif; ?>

    <p>
        <a href="my-invitations.php">
            View My Research Invitations
        </a>
    </p>

<?php endif; ?>

<?php if (!$existing && !$invitation): ?>

    <h3>Submit Your Application</h3>

    <form
        method="POST"
        action="apply-study.php?id=<?= (int) $studyId ?>"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <p>
            <label for="message">
                Optional Message to Researcher:
            </label>
        </p>

        <textarea
            id="message"
            name="message"
            rows="5"
            cols="50"
            maxlength="1000"
        ><?= e($message) ?></textarea>

        <p>
            <button type="submit">
                Submit Application
            </button>
        </p>

    </form>

<?php endif; ?>

<hr>

<p>
    <a href="my-applications.php">
        View My Applications
    </a>
</p>

<p>
    <a href="my-invitations.php">
        View My Research Invitations
    </a>
</p>

<p>
    <a href="volunteer-dashboard.php">
        Back to Volunteer Dashboard
    </a>
</p>

</body>
</html>
