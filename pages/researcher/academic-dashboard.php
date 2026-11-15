<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';

$user = requireRole('academic');
$name = htmlspecialchars((string)($user['first_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Dashboard | Research Recruitment</title>
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
            <a href="academic-dashboard.php" aria-current="page">Dashboard</a>
            <a href="my-studies.php">My studies</a>
            <a href="review-applications.php">Applications</a>
            <a href="find-volunteers.php">Find volunteers</a>
            <a href="my-sent-requests.php">Invitations</a>
            <a href="../shared/profile.php">My profile</a>
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
            <span class="eyebrow">Academic researcher dashboard</span>
            <h1 id="dashboard-title">Welcome back, <?= $name ?>!</h1>
            <p>Create and manage research studies, find suitable volunteers and keep your recruitment organised in one place.</p>
            <div class="dashboard-hero__actions">
                <a class="btn btn--primary" href="create-study.php">Create a research study</a>
                <a class="btn dashboard-btn--outline" href="my-studies.php">My research studies</a>
            </div>
        </div>
        <aside class="dashboard-hero__aside" aria-label="Your research workflow">
            <span class="dashboard-hero__aside-label">Your research workflow</span>
            <div class="dashboard-hero__step"><span>01</span><p>Create and publish a study</p></div>
            <div class="dashboard-hero__step"><span>02</span><p>Find and invite suitable volunteers</p></div>
            <div class="dashboard-hero__step"><span>03</span><p>Review applications and track invitations</p></div>
        </aside>
    </section>

    <section class="dashboard-section" aria-labelledby="services-title">
        <div class="dashboard-section__header">
            <div>
                <span class="eyebrow">Your workspace</span>
                <h2 id="services-title">What would you like to manage?</h2>
            </div>
            <p>Choose a section to get started.</p>
        </div>

        <div class="dashboard-card-grid">
            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">01</div>
                <h3>My research studies</h3>
                <p>View your studies, manage drafts and keep track of published research opportunities.</p>
                <a class="dashboard-card__link" href="my-studies.php">Manage studies <span aria-hidden="true">→</span></a>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">02</div>
                <h3>Review applications</h3>
                <p>Read volunteer applications, review eligibility responses and manage decisions.</p>
                <a class="dashboard-card__link" href="review-applications.php">Review applications <span aria-hidden="true">→</span></a>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">03</div>
                <h3>Find volunteers</h3>
                <p>Search volunteer profiles, compare study matches and discover suitable participants.</p>
                <a class="dashboard-card__link" href="find-volunteers.php">Find volunteers <span aria-hidden="true">→</span></a>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">04</div>
                <h3>Sent invitations</h3>
                <p>See the invitations you've sent and check which volunteers have responded.</p>
                <a class="dashboard-card__link" href="my-sent-requests.php">View invitations <span aria-hidden="true">→</span></a>
            </article>
        </div>
    </section>

    <section class="dashboard-shortcuts" aria-labelledby="shortcuts-title">
        <div>
            <h2 id="shortcuts-title">Keep track of volunteers</h2>
            <p>Return to the volunteer profiles you've saved or hidden.</p>
        </div>
        <div class="dashboard-shortcuts__links">
            <a class="btn dashboard-btn--light" href="find-volunteers.php?view=saved">Saved volunteers</a>
            <a class="btn dashboard-btn--light" href="find-volunteers.php?view=hidden">Hidden volunteers</a>
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
