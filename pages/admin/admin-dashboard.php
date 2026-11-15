<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';

$user = requireRole('admin');
$pdo = getConnection();

$totalUsers = (int)$pdo
    ->query('SELECT COUNT(*) FROM users')
    ->fetchColumn();

$pendingResearchers = (int)$pdo
    ->query(
        "SELECT COUNT(*)
         FROM academic_profiles ap
         INNER JOIN users u ON u.user_id = ap.user_id
         WHERE u.role = 'academic'
           AND ap.verification_status = 'pending'"
    )
    ->fetchColumn();

$totalStudies = (int)$pdo
    ->query('SELECT COUNT(*) FROM studies')
    ->fetchColumn();

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$name = e((string)($user['first_name'] ?? ''));

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = e($_SESSION['csrf_token']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="../../index.php" aria-label="Research Recruitment homepage">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="admin-dashboard.php" aria-current="page">Dashboard</a>
            <a href="review-researchers.php">Researcher verification</a>
            <form action="../../actions/account/logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell dashboard-page">
    <section class="dashboard-hero" aria-labelledby="dashboard-title">
        <div class="dashboard-hero__copy">
            <span class="eyebrow">Administrator dashboard</span>
            <h1 id="dashboard-title">Welcome back, <?= $name ?>!</h1>
            <p>Monitor your research recruitment platform and review researcher accounts awaiting verification.</p>
            <div class="dashboard-hero__actions">
                <a class="btn btn--primary" href="review-researchers.php">Review researchers</a>
                <a class="btn dashboard-btn--outline" href="#platform-overview">Platform overview</a>
            </div>
        </div>
        <aside class="dashboard-hero__aside" aria-label="Your administration workflow">
            <span class="dashboard-hero__aside-label">Your administration workflow</span>
            <div class="dashboard-hero__step"><span>01</span><p>Monitor registered accounts and studies</p></div>
            <div class="dashboard-hero__step"><span>02</span><p>Review pending researcher information</p></div>
            <div class="dashboard-hero__step"><span>03</span><p>Approve or reject verification requests</p></div>
        </aside>
    </section>

    <section class="dashboard-section" id="platform-overview" aria-labelledby="overview-title">
        <div class="dashboard-section__header">
            <div>
                <span class="eyebrow">Platform overview</span>
                <h2 id="overview-title">Your administration workspace</h2>
            </div>
            <p>Live totals from the research recruitment database.</p>
        </div>

        <div class="dashboard-card-grid">
            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">01</div>
                <h3><?= number_format($totalUsers) ?> registered users</h3>
                <p>All volunteer, academic researcher and administrator accounts on the platform.</p>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">02</div>
                <h3><?= number_format($totalStudies) ?> research studies</h3>
                <p>Total studies in the database, including drafts, open studies and closed studies.</p>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">03</div>
                <h3><?= number_format($pendingResearchers) ?> awaiting verification</h3>
                <p>Academic researcher accounts that still need an administrator's decision.</p>
                <a class="dashboard-card__link" href="review-researchers.php">Review pending researchers <span aria-hidden="true">→</span></a>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">04</div>
                <h3>Researcher verification</h3>
                <p>Inspect researcher details and approve or reject pending verification requests.</p>
                <a class="dashboard-card__link" href="review-researchers.php">Open verification page <span aria-hidden="true">→</span></a>
            </article>
        </div>
    </section>

    <section class="dashboard-shortcuts" aria-labelledby="admin-shortcuts-title">
        <div>
            <h2 id="admin-shortcuts-title">Keep researcher accounts up to date</h2>
            <p><?= number_format($pendingResearchers) ?> <?= $pendingResearchers === 1 ? 'researcher is' : 'researchers are' ?> currently awaiting verification.</p>
        </div>
        <div class="dashboard-shortcuts__links">
            <a class="btn dashboard-btn--light" href="review-researchers.php">Review researchers</a>
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
