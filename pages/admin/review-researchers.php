<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';

$user = requireRole('admin');
$pdo = getConnection();

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$message = $_SESSION['review_message'] ?? '';
unset($_SESSION['review_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;
    $decision = $_POST['decision'] ?? null;

    $researcherId = filter_input(
        INPUT_POST,
        'researcher_id',
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
        !is_string($decision) ||
        !in_array(
            $decision,
            ['verified', 'rejected'],
            true
        ) ||
        !is_int($researcherId)
    ) {
        http_response_code(400);
        $error = 'Invalid verification request.';

    } else {

        try {
            $stmt = $pdo->prepare(
                "UPDATE academic_profiles ap
                 INNER JOIN users u
                    ON u.user_id = ap.user_id
                 SET
                    ap.verification_status = :status,
                    ap.verified_at =
                        CASE
                            WHEN :approved = 1
                            THEN NOW()
                            ELSE NULL
                        END
                 WHERE ap.user_id = :researcher_id
                   AND ap.verification_status = 'pending'
                   AND u.role = 'academic'"
            );

            $stmt->execute([
                ':status' => $decision,
                ':approved' =>
                    $decision === 'verified' ? 1 : 0,
                ':researcher_id' => $researcherId
            ]);

            if ($stmt->rowCount() === 1) {

                $_SESSION['review_message'] =
                    $decision === 'verified'
                        ? 'Researcher approved successfully.'
                        : 'Researcher rejected successfully.';

                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                header('Location: review-researchers.php');
                exit;

            } else {
                $error =
                    'Researcher not found or already reviewed.';
            }

        } catch (Throwable $exception) {
            error_log(
                'Researcher verification error: ' .
                $exception->getMessage()
            );

            $error = 'Unable to update researcher status.';
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Retrieve pending academics only.
$stmt = $pdo->query(
    "SELECT
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        ap.institution,
        ap.department,
        ap.research_interests,
        ap.verification_status
     FROM academic_profiles ap
     INNER JOIN users u
         ON u.user_id = ap.user_id
     WHERE u.role = 'academic'
       AND ap.verification_status = 'pending'
     ORDER BY u.user_id ASC"
);

$researchers = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Researchers | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/study-confirmation.css">
    <link rel="stylesheet" href="../../assets/css/review-researchers.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="../../index.php" aria-label="Research Recruitment homepage">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="admin-dashboard.php">Dashboard</a>
            <a href="review-researchers.php" aria-current="page">Researcher verification</a>
            <form action="../../actions/account/logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button class="dashboard-logout__button" type="submit">Log out</button>
            </form>
        </nav>
    </div>
</header>
<main class="page-shell finish-page">
    <section class="finish-hero" aria-labelledby="verify-title">
        <div>
            <span class="eyebrow">Administrator workspace</span>
            <h1 id="verify-title">Researcher verification</h1>
            <p>Welcome, <?= e($user['first_name']) ?>. Review academic researcher accounts before granting access to publication and recruitment features.</p>
        </div>
        <a class="btn btn--primary" href="admin-dashboard.php">Admin dashboard</a>
    </section>

    <?php if ($message !== ''): ?>
        <div class="feedback feedback--success finish-feedback" role="status"><?= e($message) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="feedback feedback--error finish-feedback" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <section class="finish-overview" aria-labelledby="verify-overview-title">
        <div class="finish-section-heading">
            <div>
                <span class="eyebrow">Verification overview</span>
                <h2 id="verify-overview-title">Pending account reviews</h2>
            </div>
            <div class="finish-count"><strong><?= count($researchers) ?></strong><span>Awaiting review</span></div>
        </div>
        <p class="finish-info">Institution and department information is self-reported. Verify these details independently before approving a researcher account.</p>
    </section>

    <section class="finish-section" aria-labelledby="pending-title">
        <div class="finish-section-heading">
            <div>
                <span class="eyebrow">Pending researchers</span>
                <h2 id="pending-title">Review researcher details</h2>
            </div>
            <p>Only pending accounts appear here.</p>
        </div>

        <?php if (empty($researchers)): ?>
            <div class="finish-empty">
                <h3>All caught up!</h3>
                <p>No researcher accounts are currently awaiting verification.</p>
                <a class="btn btn--primary" href="admin-dashboard.php">Return to dashboard</a>
            </div>
        <?php else: ?>
            <div class="finish-researcher-grid">
                <?php foreach ($researchers as $researcher): ?>
                    <article class="finish-researcher-card">
                        <div class="finish-card-top">
                            <span class="finish-chip">Researcher #<?= (int)$researcher['user_id'] ?></span>
                            <span class="finish-pill finish-pill--pending">Pending verification</span>
                        </div>
                        <h3><?= e($researcher['first_name']) ?> <?= e($researcher['last_name']) ?></h3>
                        <dl class="finish-details">
                            <div><dt>Email address</dt><dd><?= e($researcher['email']) ?></dd></div>
                            <div><dt>Institution</dt><dd><?= e($researcher['institution'] ?: 'Not provided') ?></dd></div>
                            <div><dt>Department</dt><dd><?= e($researcher['department'] ?: 'Not provided') ?></dd></div>
                            <div><dt>Research interests</dt><dd><?= e($researcher['research_interests'] ?: 'Not provided') ?></dd></div>
                        </dl>
                        <form class="finish-review-actions" method="post" action="review-researchers.php">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="researcher_id" value="<?= (int)$researcher['user_id'] ?>">
                            <button class="btn btn--primary" type="submit" name="decision" value="verified">Approve researcher</button>
                            <button class="btn finish-btn--danger-outline" type="submit" name="decision" value="rejected">Reject researcher</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
