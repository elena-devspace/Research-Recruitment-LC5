<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/recruitment-lock.php';
$user = requireRole('volunteer');
$pdo = getConnection();

$volunteerId = (int) $user['user_id'];
$testAccountIds = [1, 6, 7];

$isTestAccount = in_array(
    $volunteerId,
    $testAccountIds,
    true
);
function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
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
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}
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

$eligibilityQuestions = $pdo->prepare(
    'SELECT question_id, question_text, required_answer
     FROM study_eligibility_questions
     WHERE study_id = ?
     ORDER BY position'
);
$eligibilityQuestions->execute([$studyId]);
$questions = $eligibilityQuestions->fetchAll(PDO::FETCH_ASSOC);

function validateScreeningAnswers(array $questions, $submitted): array
{
    if (!$questions) {
        return [[], null];
    }

    if (!is_array($submitted)) {
        return [[], 'Please answer every eligibility question.'];
    }

    $answers = [];

    foreach ($questions as $question) {
        $id = (int)$question['question_id'];
        $answer = $submitted[$id] ?? null;

        if (!is_string($answer) || !in_array($answer, ['yes', 'no'], true)) {
            return [[], 'Please answer every eligibility question.'];
        }

        if ($answer !== $question['required_answer']) {
            return [[], 'Your answers do not meet this study’s eligibility requirements.'];
        }

        $answers[$id] = $answer;
    }

    if (count($submitted) !== count($questions)) {
        return [[], 'Invalid eligibility responses. Please refresh the page.'];
    }

    return [$answers, null];
}

$error = '';
$message = '';
$submittedAnswers = [];
$success = (
    ($_SESSION['application_success_id'] ?? null) === $studyId
);

unset($_SESSION['application_success_id']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;
    $rawMessage = $_POST['message'] ?? '';

    $message = is_string($rawMessage)
        ? trim($rawMessage)
        : '';

    $rawAnswers = $_POST['eligibility_answers'] ?? null;
    $submittedAnswers = is_array($rawAnswers) ? $rawAnswers : [];
    [, $screeningError] = validateScreeningAnswers(
        $questions,
        $rawAnswers
    );
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
        $error =
            'You have already applied to this study.';

    } elseif ($invitation) {
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

    } elseif ($screeningError !== null) {

        $error = $screeningError;

    } else {

        try {

            $conflict = runRecruitmentWrite(
                $pdo,
                $studyId,
                $volunteerId,

                function () use (
                    $pdo,
                    $studyId,
                    $volunteerId,
                    $message,
                    $isTestAccount,
                    $rawAnswers
                ): void {

                    $currentQuestions = $pdo->prepare(
                        'SELECT question_id, required_answer
                         FROM study_eligibility_questions
                         WHERE study_id = ?
                         ORDER BY position'
                    );
                    $currentQuestions->execute([$studyId]);
                    [$answers, $validationError] = validateScreeningAnswers(
                        $currentQuestions->fetchAll(PDO::FETCH_ASSOC),
                        $rawAnswers
                    );

                    if ($validationError !== null) {
                        throw new DomainException('Eligibility questions changed. Refresh the page.');
                    }

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

                    if ($answers) {
                        $applicationId = (int)$pdo->lastInsertId();
                        $saveAnswer = $pdo->prepare(
                            'INSERT INTO study_application_answers
                             (application_id, question_id, answer)
                             VALUES (?, ?, ?)'
                        );

                        foreach ($answers as $questionId => $answer) {
                            $saveAnswer->execute([
                                $applicationId,
                                $questionId,
                                $answer
                            ]);
                        }
                    }
                }
            );
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
                $_SESSION['application_success_id'] = $studyId;

                header(
                    'Location: apply-study.php?id=' . $studyId
                );

                exit;
            }

        } catch (Throwable $exception) {

            if ($exception instanceof DomainException) {
                $error = $exception->getMessage();
            } elseif (
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
$applicationCheck->execute([
    $studyId,
    $volunteerId
]);

$existing = $applicationCheck->fetch(
    PDO::FETCH_ASSOC
);
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

        <?php if ($questions): ?>
            <fieldset>
                <legend>Eligibility screening (all questions required)</legend>
                <?php foreach ($questions as $question): ?>
                    <?php $questionId = (int)$question['question_id']; ?>
                    <p><?= e($question['question_text']) ?></p>
                    <label>
                        <input type="radio"
                               name="eligibility_answers[<?= $questionId ?>]"
                               value="yes"
                               <?= ($submittedAnswers[$questionId] ?? '') === 'yes' ? 'checked' : '' ?>
                               required>
                        Yes
                    </label>
                    <label>
                        <input type="radio"
                               name="eligibility_answers[<?= $questionId ?>]"
                               value="no"
                               <?= ($submittedAnswers[$questionId] ?? '') === 'no' ? 'checked' : '' ?>
                               required>
                        No
                    </label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>

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
