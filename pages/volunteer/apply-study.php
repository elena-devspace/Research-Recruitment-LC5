<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';
require_once __DIR__ . '/../../app/recruitment/recruitment-lock.php';
$user = requireRole('volunteer');
$pdo = getConnection();

$volunteerId = (int) $user['user_id'];
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
        s.aim,
        s.description,
        s.participation_tasks,
        s.eligibility_requirements,
        s.participation_format,
        s.location,
        s.start_date,
        s.end_date,
        s.reimbursement_amount,
        s.reimbursement_details,
        s.participant_capacity,
        s.anonymous_participation_allowed,
        s.is_test,
        s.application_deadline,
        u.first_name AS researcher_first_name,
        u.last_name AS researcher_last_name,
        ap.institution
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
    LIMIT 1
");

$studyStmt->execute([$studyId]);

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Research Study | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/apply-study.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="../../index.php" aria-label="Research Recruitment homepage">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="volunteer-dashboard.php">Dashboard</a>
            <a href="browse-studies.php" aria-current="page">Studies</a>
            <a href="my-applications.php">Applications</a>
            <a href="my-invitations.php">Invitations</a>
            <a href="../shared/profile.php">My profile</a>
            <form action="../../actions/account/logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell apply-page">
    <a class="apply-back" href="browse-studies.php">← Back to research studies</a>

    <section class="apply-hero" aria-labelledby="apply-page-title">
        <div>
            <span class="eyebrow">Research opportunities</span>
            <h1 id="apply-page-title">Apply for a research study</h1>
            <p>Review the study details, check eligibility and manage your participation.</p>
        </div>
        <a class="btn apply-btn--outline" href="my-applications.php">My applications</a>
    </section>

    <div class="apply-layout">
        <article class="apply-panel apply-info" aria-labelledby="apply-study-title">
            <div class="apply-tags">
                <span class="apply-tag"><?= e($study['topic']) ?></span>
                <span class="apply-tag apply-tag--light"><?= e(ucwords(str_replace('_', ' ', $study['participation_format']))) ?></span>
                <?php if ((int)$study['is_test'] === 1): ?>
                    <span class="apply-tag apply-tag--light">Fictional test study</span>
                <?php endif; ?>
            </div>
            <h2 id="apply-study-title"><?= e($study['title']) ?></h2>
            <p class="apply-info__intro">
                By <?= e(trim($study['researcher_first_name'] . ' ' . $study['researcher_last_name'])) ?>
                <?php if (!empty($study['institution'])): ?> · <?= e($study['institution']) ?><?php endif; ?>
            </p>

            <div class="apply-deadline">
                <span>Application deadline</span>
                <strong><?= e($study['application_deadline']) ?></strong>
            </div>

            <section class="apply-overview" aria-labelledby="apply-overview-heading">
                <h3 id="apply-overview-heading">Study at a glance</h3>
                <dl class="apply-overview__grid">
                    <div class="apply-overview__item">
                        <dt>Reimbursement</dt>
                        <dd>£<?= number_format((float)$study['reimbursement_amount'], 2) ?></dd>
                    </div>
                    <div class="apply-overview__item">
                        <dt>Participation format</dt>
                        <dd><?= e(ucwords(str_replace('_', ' ', $study['participation_format']))) ?></dd>
                    </div>
                    <div class="apply-overview__item">
                        <dt>Location</dt>
                        <dd><?= e($study['participation_format'] === 'online' ? 'Online' : ($study['location'] ?: 'To be confirmed')) ?></dd>
                    </div>
                    <div class="apply-overview__item">
                        <dt>Participant capacity</dt>
                        <dd><?= $study['participant_capacity'] !== null ? e($study['participant_capacity']) . ' participants' : 'Not specified' ?></dd>
                    </div>
                    <div class="apply-overview__item">
                        <dt>Study starts</dt>
                        <dd><?= e($study['start_date'] ?: 'To be confirmed') ?></dd>
                    </div>
                    <div class="apply-overview__item">
                        <dt>Study ends</dt>
                        <dd><?= e($study['end_date'] ?: 'To be confirmed') ?></dd>
                    </div>
                    <div class="apply-overview__item apply-overview__item--wide">
                        <dt>Anonymous participation</dt>
                        <dd><?= (int)$study['anonymous_participation_allowed'] === 1 ? 'Allowed' : 'Not allowed' ?></dd>
                    </div>
                </dl>
            </section>

            <section class="apply-info__section">
                <h3>Reimbursement details</h3>
                <p><?= !empty($study['reimbursement_details']) ? nl2br(e($study['reimbursement_details'])) : 'No additional reimbursement details provided.' ?></p>
            </section>

            <?php if (!empty($study['aim'])): ?>
                <section class="apply-info__section">
                    <h3>Research aim</h3>
                    <p><?= nl2br(e($study['aim'])) ?></p>
                </section>
            <?php endif; ?>

            <section class="apply-info__section">
                <h3>Study description</h3>
                <p><?= nl2br(e($study['description'])) ?></p>
            </section>

            <section class="apply-info__section">
                <h3>Participant tasks</h3>
                <p><?= nl2br(e($study['participation_tasks'])) ?></p>
            </section>

            <section class="apply-info__section">
                <h3>Eligibility requirements</h3>
                <p><?= nl2br(e($study['eligibility_requirements'])) ?></p>
            </section>
        </article>

        <section class="apply-panel apply-form-panel" aria-labelledby="apply-form-heading">
            <span class="eyebrow">Your participation</span>
            <?php if ($existing): ?>
                <h2 id="apply-form-heading">Your application</h2>
            <?php elseif ($invitation): ?>
                <h2 id="apply-form-heading">Your research invitation</h2>
            <?php else: ?>
                <h2 id="apply-form-heading">Submit your application</h2>
                <p class="apply-form-panel__intro">Answer any required questions and include a message if you'd like.</p>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="feedback feedback--success" role="status">Application submitted successfully!</div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="feedback feedback--error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($existing): ?>
                <div class="apply-status">
                    <span class="apply-status__label">Current application status</span>
                    <strong class="apply-status__pill"><?= e(ucfirst($existing['status'])) ?></strong>
                </div>
                <?php if ($existing['status'] === 'pending'): ?>
                    <p class="apply-status__text">Your application has been submitted and is awaiting researcher review.</p>
                <?php elseif ($existing['status'] === 'accepted'): ?>
                    <p class="apply-status__text">Your application has been accepted by the researcher.</p>
                <?php elseif ($existing['status'] === 'rejected'): ?>
                    <p class="apply-status__text">Your application was not accepted by the researcher.</p>
                <?php elseif ($existing['status'] === 'withdrawn'): ?>
                    <p class="apply-status__text">You have withdrawn your application for this study.</p>
                <?php else: ?>
                    <p class="apply-status__text">You have already submitted an application for this study.</p>
                <?php endif; ?>
                <p class="apply-small-note">A duplicate application cannot be submitted.</p>
                <a class="btn btn--primary btn--block" href="my-applications.php">View my applications</a>
            <?php endif; ?>

            <?php if ($invitation): ?>
                <div class="apply-status">
                    <span class="apply-status__label">Current invitation status</span>
                    <strong class="apply-status__pill"><?= e(ucfirst($invitation['status'])) ?></strong>
                </div>
                <?php if ($invitation['status'] === 'pending'): ?>
                    <p class="apply-status__text">You have already received an invitation to this study. Please respond to your invitation instead of submitting a new application.</p>
                <?php elseif ($invitation['status'] === 'accepted'): ?>
                    <p class="apply-status__text">You have already accepted an invitation to participate in this study. A separate application is not necessary.</p>
                <?php elseif ($invitation['status'] === 'declined'): ?>
                    <p class="apply-status__text">You previously declined an invitation to this study. A new application cannot be submitted for a study with an existing invitation.</p>
                <?php else: ?>
                    <p class="apply-status__text">An invitation already exists for this study. A separate application cannot be submitted.</p>
                <?php endif; ?>
                <a class="btn btn--primary btn--block" href="my-invitations.php">View my research invitations</a>
            <?php endif; ?>

            <?php if (!$existing && !$invitation): ?>
                <form method="post" action="apply-study.php?id=<?= (int)$studyId ?>" class="apply-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

                    <?php if ($questions): ?>
                        <fieldset class="apply-screening">
                            <legend>Eligibility screening</legend>
                            <p class="apply-small-note">All questions are required.</p>
                            <?php foreach ($questions as $index => $question): ?>
                                <?php $questionId = (int)$question['question_id']; ?>
                                <fieldset class="apply-question">
                                    <legend><span class="apply-question__number"><?= (int)$index + 1 ?></span><?= e($question['question_text']) ?></legend>
                                    <div class="apply-answers">
                                        <label class="apply-answer">
                                            <input type="radio" name="eligibility_answers[<?= $questionId ?>]" value="yes" <?= ($submittedAnswers[$questionId] ?? '') === 'yes' ? 'checked' : '' ?> required>
                                            <span>Yes</span>
                                        </label>
                                        <label class="apply-answer">
                                            <input type="radio" name="eligibility_answers[<?= $questionId ?>]" value="no" <?= ($submittedAnswers[$questionId] ?? '') === 'no' ? 'checked' : '' ?> required>
                                            <span>No</span>
                                        </label>
                                    </div>
                                </fieldset>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endif; ?>

                    <div class="field apply-message-field">
                        <label for="message">Message to the researcher <span class="apply-optional">(optional)</span></label>
                        <textarea id="message" name="message" rows="5" maxlength="1000" placeholder="Tell the researcher why this study interests you..."><?= e($message) ?></textarea>
                        <span class="form-hint">Up to 1,000 characters (maximum 1,000 bytes).</span>
                    </div>

                    <button class="btn btn--primary btn--block apply-submit" type="submit">Submit application</button>
                </form>
            <?php endif; ?>

            <div class="apply-related">
                <p>Looking for something else?</p>
                <a href="my-applications.php">My applications</a>
                <a href="my-invitations.php">My invitations</a>
                <a href="browse-studies.php">Browse studies</a>
            </div>
        </section>
    </div>
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <span>© 2026 Research Recruitment · KV6014 university project</span>
        <div class="site-footer__items" aria-label="Additional information">
            <span>Privacy Policy</span><span>Contact</span><span>Terms</span>
        </div>
    </div>
</footer>
</body>
</html>
