<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';
require_once __DIR__ . '/../../app/recruitment/study-capacity.php';

$user = requireRole('academic');
$pdo = getConnection();

$researcherId = (int)$user['user_id'];

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$verification = $pdo->prepare(
    "SELECT verification_status
     FROM academic_profiles
     WHERE user_id = ?
     LIMIT 1"
);

$verification->execute([$researcherId]);

if ($verification->fetchColumn() !== 'verified') {
    http_response_code(403);
    exit('Researcher verification required.');
}

if (!in_array(
    $_SERVER['REQUEST_METHOD'],
    ['GET', 'POST'],
    true
)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$notice = $_SESSION['application_review_notice'] ?? '';
unset($_SESSION['application_review_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;
    $decision = $_POST['decision'] ?? null;

    $applicationId = filter_input(
        INPUT_POST,
        'application_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        $error = 'Invalid request. Refresh the page.';

    } elseif (
        !is_int($applicationId) ||
        !is_string($decision) ||
        !in_array(
            $decision,
            ['accepted', 'rejected'],
            true
        )
    ) {
        http_response_code(400);
        $error = 'Invalid application decision.';

    } else {

        try {
            $lookup = $pdo->prepare(
                'SELECT a.study_id
                 FROM study_applications a
                 INNER JOIN studies s ON s.study_id = a.study_id
                 WHERE a.application_id = ? AND s.researcher_id = ?'
            );
            $lookup->execute([$applicationId, $researcherId]);
            $studyId = $lookup->fetchColumn();

            if ($studyId === false) {
                $error = 'Application not found or access denied.';
            } else {
                $pdo->beginTransaction();
                $capacity = getStudyCapacity($pdo, (int)$studyId, true);

                $stateStmt = $pdo->prepare(
                    'SELECT status FROM study_applications
                     WHERE application_id = ? AND study_id = ? FOR UPDATE'
                );
                $stateStmt->execute([$applicationId, (int)$studyId]);
                $status = $stateStmt->fetchColumn();

                if ($status !== 'pending') {
                    $error = 'This application has already been reviewed.';
                } elseif (
                    $decision === 'accepted' &&
                    $capacity['remaining'] !== null &&
                    $capacity['remaining'] < 1
                ) {
                    $error = 'This study is full. No more applications can be accepted.';
                } else {
                    $update = $pdo->prepare(
                        "UPDATE study_applications
                         SET status = ?, reviewed_at = NOW()
                         WHERE application_id = ? AND study_id = ? AND status = 'pending'"
                    );
                    $update->execute([$decision, $applicationId, (int)$studyId]);

                    if ($update->rowCount() === 1) {
                        $pdo->commit();
                        $_SESSION['application_review_notice'] = $decision === 'accepted'
                            ? 'Application accepted successfully!'
                            : 'Application rejected successfully!';
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        header('Location: review-applications.php');
                        exit;
                    }

                    $error = 'This application could not be updated.';
                }

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Application review error: ' . $exception->getMessage());
            $error = 'Unable to review application.';
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT
        a.application_id,
        a.study_id,
        a.volunteer_id,
        a.message,
        a.status,
        a.applied_at,
        a.reviewed_at,

        s.title AS study_title,
        s.is_test,

        CASE WHEN a.status = 'accepted' THEN v.first_name END AS first_name,
        CASE WHEN a.status = 'accepted' THEN v.last_name END AS last_name,
        CASE WHEN a.status = 'accepted' THEN COALESCE(vp.preferred_contact_method, 'email') END AS contact_method,
        CASE WHEN a.status = 'accepted' AND COALESCE(vp.preferred_contact_method, 'email') = 'email'
            THEN v.email END AS email,
        CASE WHEN a.status = 'accepted' AND vp.preferred_contact_method = 'phone'
            THEN vp.phone_number END AS phone_number

     FROM study_applications a

     INNER JOIN studies s
         ON s.study_id = a.study_id

     INNER JOIN users v
         ON v.user_id = a.volunteer_id
     LEFT JOIN volunteer_profiles vp
         ON vp.user_id = v.user_id

     WHERE s.researcher_id = ?

     ORDER BY
         (a.status = 'pending') DESC,
         a.applied_at DESC,
         a.application_id DESC"
);

$stmt->execute([$researcherId]);

$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
$capacityByStudy = [];
foreach ($applications as $application) {
    $studyId = (int)$application['study_id'];
    if (!isset($capacityByStudy[$studyId])) {
        $capacityByStudy[$studyId] = getStudyCapacity($pdo, $studyId);
    }
}

$screeningStmt = $pdo->prepare(
    'SELECT aa.application_id, q.question_text, q.required_answer, aa.answer
     FROM study_application_answers aa
     INNER JOIN study_eligibility_questions q ON q.question_id = aa.question_id
     INNER JOIN study_applications a ON a.application_id = aa.application_id
     INNER JOIN studies s ON s.study_id = a.study_id
     WHERE s.researcher_id = ?
     ORDER BY aa.application_id, q.position'
);
$screeningStmt->execute([$researcherId]);
$screeningByApplication = [];

foreach ($screeningStmt->fetchAll(PDO::FETCH_ASSOC) as $response) {
    $screeningByApplication[(int)$response['application_id']][] = $response;
}

$pendingCount = 0;

foreach ($applications as $application) {
    if ($application['status'] === 'pending') {
        $pendingCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Applications | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/review-applications.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="../../index.php" aria-label="Research Recruitment homepage">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="academic-dashboard.php">Dashboard</a>
            <a href="my-studies.php">My studies</a>
            <a href="review-applications.php" aria-current="page">Applications</a>
            <a href="find-volunteers.php">Find volunteers</a>
            <a href="my-sent-requests.php">Invitations</a>
            <a href="../shared/profile.php">My profile</a>
            <form action="../../actions/account/logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button class="dashboard-logout__button" type="submit">Log out</button>
            </form>
        </nav>
    </div>
</header>

<?php
$reviewCounts = ['pending' => 0, 'accepted' => 0, 'rejected' => 0, 'withdrawn' => 0];
foreach ($applications as $application) {
    if (isset($reviewCounts[$application['status']])) {
        $reviewCounts[$application['status']]++;
    }
}
?>

<main class="page-shell review-page">
    <section class="review-hero" aria-labelledby="review-title">
        <div class="review-hero__copy">
            <span class="eyebrow">Your research workspace</span>
            <h1 id="review-title">Review volunteer applications</h1>
            <p>Welcome back, <?= e($user['first_name']) ?>! Review eligibility answers, respond to applications and keep recruitment moving.</p>
        </div>
        <a class="btn btn--primary review-hero__action" href="my-studies.php">My research studies</a>
    </section>

    <?php if ($notice !== ''): ?>
        <div class="feedback feedback--success review-feedback" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="feedback feedback--error review-feedback" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="review-overview" aria-labelledby="review-overview-heading">
        <div class="review-section-heading">
            <div>
                <span class="eyebrow">Application overview</span>
                <h2 id="review-overview-heading">Your recruitment at a glance</h2>
            </div>
            <p><?= count($applications) ?> total application<?= count($applications) === 1 ? '' : 's' ?></p>
        </div>
        <div class="review-summary-grid">
            <div class="review-summary-card">
                <span>Pending</span>
                <strong><?= (int)$reviewCounts['pending'] ?></strong>
                <p>Awaiting your decision</p>
            </div>
            <div class="review-summary-card">
                <span>Accepted</span>
                <strong><?= (int)$reviewCounts['accepted'] ?></strong>
                <p>Approved for participation</p>
            </div>
            <div class="review-summary-card">
                <span>Rejected</span>
                <strong><?= (int)$reviewCounts['rejected'] ?></strong>
                <p>Not selected</p>
            </div>
            <div class="review-summary-card">
                <span>Withdrawn</span>
                <strong><?= (int)$reviewCounts['withdrawn'] ?></strong>
                <p>Withdrawn by volunteers</p>
            </div>
        </div>
    </section>

    <section class="review-history" aria-labelledby="review-history-heading">
        <div class="review-section-heading">
            <div>
                <span class="eyebrow">Application history</span>
                <h2 id="review-history-heading">Applications to your studies</h2>
            </div>
            <p>Pending applications appear first.</p>
        </div>

        <?php if (empty($applications)): ?>
            <div class="review-empty">
                <h3>No applications received yet</h3>
                <p>When volunteers apply to your research studies, their applications and screening answers will appear here.</p>
                <a class="btn btn--primary" href="my-studies.php">View my studies</a>
            </div>
        <?php else: ?>
            <div class="review-card-grid">
                <?php foreach ($applications as $application): ?>
                    <?php
                        $applicationId = (int)$application['application_id'];
                        $studyId = (int)$application['study_id'];
                        $status = (string)$application['status'];
                        $capacity = $capacityByStudy[$studyId];
                        $responses = $screeningByApplication[$applicationId] ?? [];
                    ?>
                    <article class="review-card" aria-labelledby="application-heading-<?= $applicationId ?>">
                        <div class="review-card__badges">
                            <?php if ($application['is_test']): ?>
                                <span class="review-card__test">Fictional test study</span>
                            <?php endif; ?>
                            <span class="review-card__status review-card__status--<?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
                        </div>
                        <h3 id="application-heading-<?= $applicationId ?>"><?= e($application['study_title']) ?></h3>
                        <p class="review-card__subtitle">Application #<?= $applicationId ?> · Volunteer #<?= (int)$application['volunteer_id'] ?></p>

                        <dl class="review-card__metadata">
                            <div><dt>Study ID</dt><dd>#<?= $studyId ?></dd></div>
                            <div><dt>Spaces remaining</dt><dd><?= $capacity['remaining'] === null ? 'No limit set' : (int)$capacity['remaining'] . ' of ' . (int)$capacity['capacity'] ?></dd></div>
                            <div><dt>Applied at</dt><dd><?= e($application['applied_at']) ?></dd></div>
                            <?php if ($application['reviewed_at']): ?>
                                <div><dt>Reviewed at</dt><dd><?= e($application['reviewed_at']) ?></dd></div>
                            <?php endif; ?>
                        </dl>

                        <?php if ($status === 'accepted'): ?>
                            <div class="review-card__privacy review-card__privacy--accepted">
                                <strong>Volunteer contact details</strong>
                                <p><?= e(trim(($application['first_name'] ?? '') . ' ' . ($application['last_name'] ?? ''))) ?></p>
                                <p>Preferred contact: <?= e(ucfirst((string)($application['contact_method'] ?? 'email'))) ?></p>
                                <?php if ($application['contact_method'] === 'phone'): ?>
                                    <p>Phone: <?= e($application['phone_number'] ?: 'Not supplied') ?></p>
                                <?php else: ?>
                                    <p>Email: <?= e($application['email']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="review-card__privacy">Volunteer identity and contact details remain private unless their application is accepted.</p>
                        <?php endif; ?>

                        <section class="review-card__section">
                            <h4>Application message</h4>
                            <p><?= nl2br(e($application['message'] ?: 'No message provided.')) ?></p>
                        </section>

                        <section class="review-card__section review-card__screening">
                            <h4>Eligibility screening responses</h4>
                            <?php if ($responses): ?>
                                <ol class="review-card__screening-list">
                                    <?php foreach ($responses as $response): ?>
                                        <?php $matchesRequired = strtolower((string)$response['answer']) === strtolower((string)$response['required_answer']); ?>
                                        <li>
                                            <p class="review-card__question"><?= e($response['question_text']) ?></p>
                                            <div class="review-card__answers">
                                                <span>Answer: <strong><?= e(ucfirst($response['answer'])) ?></strong></span>
                                                <span>Required: <strong><?= e(ucfirst($response['required_answer'])) ?></strong></span>
                                            </div>
                                            <span class="review-card__match <?= $matchesRequired ? 'review-card__match--yes' : 'review-card__match--no' ?>">
                                                <?= $matchesRequired ? 'Matches required answer' : 'Does not match required answer' ?>
                                            </span>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            <?php else: ?>
                                <p>No screening responses recorded for this application.</p>
                            <?php endif; ?>
                            <p class="review-card__screening-note">Screening answers support review but do not automatically confirm eligibility.</p>
                        </section>

                        <div class="review-card__actions">
                            <?php if ($status === 'pending'): ?>
                                <h4>Review application</h4>
                                <form method="post" action="review-applications.php" class="review-decision-form">
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="application_id" value="<?= $applicationId ?>">
                                    <?php if ($capacity['remaining'] === null || $capacity['remaining'] > 0): ?>
                                        <button class="btn btn--primary review-decision-form__accept" type="submit" name="decision" value="accepted">Accept application</button>
                                    <?php else: ?>
                                        <p class="review-card__capacity-warning" role="status">This study is full. No more applications can be accepted.</p>
                                    <?php endif; ?>
                                    <button class="btn review-decision-form__reject" type="submit" name="decision" value="rejected">Reject application</button>
                                </form>
                            <?php else: ?>
                                <p class="review-card__processed review-card__processed--<?= e($status) ?>">
                                    <?php if ($status === 'accepted'): ?>This application has been accepted.
                                    <?php elseif ($status === 'rejected'): ?>This application has been rejected.
                                    <?php elseif ($status === 'withdrawn'): ?>This application was withdrawn by the volunteer.
                                    <?php else: ?>This application has already been processed.
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="review-shortcuts" aria-label="Researcher navigation">
        <div>
            <h2>Keep your recruitment organised</h2>
            <p>Manage your studies, find volunteers or return to your dashboard.</p>
        </div>
        <div class="review-shortcuts__links">
            <a class="btn review-btn--light" href="my-studies.php">My studies</a>
            <a class="btn review-btn--light" href="find-volunteers.php">Find volunteers</a>
            <a class="btn review-btn--light" href="academic-dashboard.php">Dashboard</a>
        </div>
    </section>
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
