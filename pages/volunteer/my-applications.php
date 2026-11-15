<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';

$user = requireRole('volunteer');
$pdo = getConnection();

$volunteerId = (int)$user['user_id'];

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$notice = $_SESSION['my_applications_notice'] ?? '';
unset($_SESSION['my_applications_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;

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

    } elseif (!is_int($applicationId)) {
        http_response_code(400);
        $error = 'Invalid application ID.';

    } else {

        try {

            $stmt = $pdo->prepare(
                "UPDATE study_applications
                 SET status = 'withdrawn'
                 WHERE application_id = ?
                   AND volunteer_id = ?
                   AND status IN ('pending', 'accepted')"
            );

            $stmt->execute([
                $applicationId,
                $volunteerId
            ]);

            if ($stmt->rowCount() === 1) {

                $_SESSION['my_applications_notice'] =
                    'Application withdrawn successfully.';

                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                header('Location: my-applications.php');
                exit;

            } else {

                $error =
                    'Application cannot be withdrawn or was not found.';
            }

        } catch (Throwable $exception) {

            error_log(
                'Withdrawal error: ' .
                $exception->getMessage()
            );

            $error = 'Unable to withdraw application.';
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT
        a.application_id,
        a.study_id,
        a.message,
        a.status,
        a.applied_at,
        a.reviewed_at,

        s.title AS study_title,
        s.topic,
        s.status AS study_status,
        s.is_test,

        r.first_name AS researcher_first_name,
        r.last_name AS researcher_last_name

     FROM study_applications a

     INNER JOIN studies s
         ON s.study_id = a.study_id

     INNER JOIN users r
         ON r.user_id = s.researcher_id

     WHERE a.volunteer_id = ?

     ORDER BY
         a.applied_at DESC,
         a.application_id DESC"
);

$stmt->execute([$volunteerId]);

$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);


$statusCounts = [
    'pending' => 0,
    'accepted' => 0,
    'rejected' => 0,
    'withdrawn' => 0,
];

foreach ($applications as $application) {
    $status = (string)$application['status'];
    if (array_key_exists($status, $statusCounts)) {
        $statusCounts[$status]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Applications | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/my-applications.css?v=withdraw-confirmation-1">
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
            <a href="browse-studies.php">Studies</a>
            <a href="my-applications.php" aria-current="page">Applications</a>
            <a href="my-invitations.php">Invitations</a>
            <a href="../shared/profile.php">My profile</a>
            <form action="../../actions/account/logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell applications-page">
    <section class="applications-hero" aria-labelledby="applications-page-title">
        <div class="applications-hero__copy">
            <span class="eyebrow">Your research journey</span>
            <h1 id="applications-page-title">Track your applications</h1>
            <p>Welcome back, <?= e($user['first_name']) ?>! Review your application decisions, messages and participation history in one place.</p>
        </div>
        <a class="btn btn--primary" href="browse-studies.php">Explore research studies</a>
    </section>

    <?php if ($notice !== ''): ?>
        <div class="feedback feedback--success applications-feedback" role="status"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="feedback feedback--error applications-feedback" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="applications-overview" aria-labelledby="applications-overview-heading">
        <div class="applications-section-header">
            <div>
                <span class="eyebrow">Application overview</span>
                <h2 id="applications-overview-heading">Your progress at a glance</h2>
            </div>
            <p><?= count($applications) ?> total application<?= count($applications) === 1 ? '' : 's' ?></p>
        </div>
        <div class="applications-stats" aria-label="Application statuses">
            <div class="applications-stat">
                <span class="applications-stat__label">Pending</span>
                <strong><?= $statusCounts['pending'] ?></strong>
                <span class="applications-stat__description">Awaiting a decision</span>
            </div>
            <div class="applications-stat">
                <span class="applications-stat__label">Accepted</span>
                <strong><?= $statusCounts['accepted'] ?></strong>
                <span class="applications-stat__description">Approved by researcher</span>
            </div>
            <div class="applications-stat">
                <span class="applications-stat__label">Rejected</span>
                <strong><?= $statusCounts['rejected'] ?></strong>
                <span class="applications-stat__description">Not selected</span>
            </div>
            <div class="applications-stat">
                <span class="applications-stat__label">Withdrawn</span>
                <strong><?= $statusCounts['withdrawn'] ?></strong>
                <span class="applications-stat__description">Withdrawn by you</span>
            </div>
        </div>
    </section>

    <section class="applications-list" aria-labelledby="applications-list-heading">
        <div class="applications-section-header">
            <div>
                <span class="eyebrow">Application history</span>
                <h2 id="applications-list-heading">My research applications</h2>
            </div>
            <p>Newest applications appear first.</p>
        </div>

        <?php if (empty($applications)): ?>
            <div class="applications-empty">
                <div class="applications-empty__icon" aria-hidden="true">01</div>
                <h3>No applications yet</h3>
                <p>You have not applied to any research studies yet. Find a study that interests you to get started.</p>
                <a class="btn btn--primary" href="browse-studies.php">Browse research studies</a>
            </div>
        <?php else: ?>
            <div class="applications-grid">
                <?php foreach ($applications as $application): ?>
                    <?php
                        $applicationStatus = (string)$application['status'];
                        $statusClass = in_array($applicationStatus, ['pending', 'accepted', 'rejected', 'withdrawn'], true)
                            ? $applicationStatus
                            : 'unknown';
                    ?>
                    <article class="application-card" aria-labelledby="application-title-<?= (int)$application['application_id'] ?>">
                        <div class="application-card__badges">
                            <span class="application-card__topic"><?= e($application['topic']) ?></span>
                            <?php if ((int)$application['is_test'] === 1): ?>
                                <span class="application-card__tag">Fictional test study</span>
                            <?php endif; ?>
                            <span class="application-card__status application-card__status--<?= $statusClass ?>">
                                <?= e(ucfirst($applicationStatus)) ?>
                            </span>
                        </div>
                        <h3 id="application-title-<?= (int)$application['application_id'] ?>"><?= e($application['study_title']) ?></h3>
                        <p class="application-card__researcher">By <?= e(trim($application['researcher_first_name'] . ' ' . $application['researcher_last_name'])) ?></p>

                        <dl class="application-card__details">
                            <div>
                                <dt>Application ID</dt>
                                <dd>#<?= (int)$application['application_id'] ?></dd>
                            </div>
                            <div>
                                <dt>Study status</dt>
                                <dd><?= e(ucfirst($application['study_status'])) ?></dd>
                            </div>
                            <div>
                                <dt>Applied at</dt>
                                <dd><?= e($application['applied_at']) ?></dd>
                            </div>
                            <?php if ($application['reviewed_at']): ?>
                                <div>
                                    <dt>Reviewed at</dt>
                                    <dd><?= e($application['reviewed_at']) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>

                        <div class="application-card__decision application-card__decision--<?= $statusClass ?>">
                            <?php if ($applicationStatus === 'pending'): ?>
                                Your application is awaiting review.
                            <?php elseif ($applicationStatus === 'accepted'): ?>
                                Your application has been accepted by the researcher.
                            <?php elseif ($applicationStatus === 'rejected'): ?>
                                Your application was not accepted.
                            <?php elseif ($applicationStatus === 'withdrawn'): ?>
                                You have withdrawn this application.
                            <?php else: ?>
                                Check back for your latest application status.
                            <?php endif; ?>
                        </div>

                        <div class="application-card__message">
                            <h4>Your application message</h4>
                            <p><?= nl2br(e($application['message'] ?: 'No message provided.')) ?></p>
                        </div>

                        <?php if (in_array($applicationStatus, ['pending', 'accepted'], true)): ?>
                            <form
                                method="post"
                                action="my-applications.php"
                                class="application-card__actions"
                                data-withdraw-application
                                onsubmit="return confirm('Are you sure you want to withdraw this application?');"
                            >
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="application_id" value="<?= (int)$application['application_id'] ?>">
                                <button type="submit" class="application-card__withdraw">Withdraw application</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="applications-shortcuts" aria-label="More volunteer actions">
        <div>
            <h2>Continue your research journey</h2>
            <p>Explore other studies, check your invitations or return to your dashboard.</p>
        </div>
        <div class="applications-shortcuts__links">
            <a class="btn applications-btn--light" href="browse-studies.php">Browse studies</a>
            <a class="btn applications-btn--light" href="my-invitations.php">My invitations</a>
            <a class="btn applications-btn--light" href="volunteer-dashboard.php">Dashboard</a>
        </div>
    </section>
</main>

<dialog id="withdraw-application-dialog" aria-labelledby="withdraw-dialog-title" aria-describedby="withdraw-dialog-description">
    <div class="withdraw-modal__head">
        <span class="withdraw-modal__icon" aria-hidden="true">!</span>
        <div class="withdraw-modal__heading">
            <span class="withdraw-modal__eyebrow">Confirm application action</span>
            <h2 id="withdraw-dialog-title">Withdraw this application?</h2>
        </div>
        <button type="button" class="withdraw-modal__close" id="withdraw-dialog-close" aria-label="Close confirmation">&times;</button>
    </div>
    <div class="withdraw-modal__body">
        <p class="withdraw-modal__label">Selected study</p>
        <p class="withdraw-modal__study" id="withdraw-dialog-study"></p>
        <p class="withdraw-modal__description" id="withdraw-dialog-description">Your application will be marked as withdrawn and you will no longer be considered for this study through this application.</p>
    </div>
    <div class="withdraw-modal__actions">
        <button class="btn withdraw-modal__cancel" type="button" id="withdraw-dialog-cancel" autofocus>Cancel</button>
        <button class="btn withdraw-modal__confirm" type="button" id="withdraw-dialog-confirm">Yes, withdraw application</button>
    </div>
</dialog>

<script>
(() => {
    const dialog = document.getElementById('withdraw-application-dialog');
    if (!dialog || typeof dialog.showModal !== 'function' || typeof HTMLFormElement.prototype.requestSubmit !== 'function') return;

    const studyName = document.getElementById('withdraw-dialog-study');
    const cancelButton = document.getElementById('withdraw-dialog-cancel');
    const closeButton = document.getElementById('withdraw-dialog-close');
    const confirmButton = document.getElementById('withdraw-dialog-confirm');
    let selectedForm = null;
    let confirmedForm = null;

    document.querySelectorAll('form[data-withdraw-application]').forEach(form => {
        form.removeAttribute('onsubmit');
        form.addEventListener('submit', event => {
            if (confirmedForm === form) return;
            event.preventDefault();
            if (dialog.open) return;

            const card = form.closest('.application-card');
            studyName.textContent = card?.querySelector('h3')?.textContent?.trim() || 'Selected study';
            selectedForm = form;
            dialog.showModal();
        });
    });

    confirmButton.addEventListener('click', () => {
        if (!selectedForm) return;
        confirmedForm = selectedForm;
        selectedForm = null;
        try {
            confirmedForm.requestSubmit();
        } finally {
            confirmedForm = null;
            dialog.close();
        }
    });

    cancelButton.addEventListener('click', () => dialog.close());
    closeButton.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', () => {
        if (!dialog.open) selectedForm = null;
    });
})();
</script>

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
