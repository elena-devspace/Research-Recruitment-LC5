<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('volunteer');
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
    <title>Volunteer Dashboard | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="volunteer-dashboard.php">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="volunteer-dashboard.php" aria-current="page">Dashboard</a>
            <a href="browse-studies.php">Studies</a>
            <a href="my-applications.php">Applications</a>
            <a href="my-invitations.php">Invitations</a>
            <a href="profile.php">My profile</a>
            <form action="logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell dashboard-page">
    <section class="dashboard-hero" aria-labelledby="dashboard-title">
        <div class="dashboard-hero__copy">
            <span class="eyebrow">Volunteer dashboard</span>
            <h1 id="dashboard-title">Welcome back, <?= $name ?>!</h1>
            <p>Find research studies that interest you, keep track of applications and manage your participation in one place.</p>
            <div class="dashboard-hero__actions">
                <a class="btn btn--primary" href="browse-studies.php">Explore research studies</a>
                <a class="btn dashboard-btn--outline" href="my-applications.php">My applications</a>
            </div>
        </div>
        <aside class="dashboard-hero__aside" aria-label="Getting started">
            <span class="dashboard-hero__aside-label">Your research journey</span>
            <div class="dashboard-hero__step"><span>01</span><p>Explore available studies</p></div>
            <div class="dashboard-hero__step"><span>02</span><p>Review requirements and apply</p></div>
            <div class="dashboard-hero__step"><span>03</span><p>Track applications and invitations</p></div>
        </aside>
    </section>

    <section class="dashboard-section" aria-labelledby="services-title">
        <div class="dashboard-section__header">
            <div>
                <span class="eyebrow">Your workspace</span>
                <h2 id="services-title">What would you like to do?</h2>
            </div>
            <p>Choose a section to get started.</p>
        </div>

        <div class="dashboard-card-grid">
            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">01</div>
                <h3>Browse studies</h3>
                <p>Discover current research opportunities and find a study that suits you.</p>
                <a class="dashboard-card__link" href="browse-studies.php">Browse studies <span aria-hidden="true">→</span></a>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">02</div>
                <h3>My applications</h3>
                <p>Check the progress of your applications and review your participation history.</p>
                <a class="dashboard-card__link" href="my-applications.php">View applications <span aria-hidden="true">→</span></a>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">03</div>
                <h3>Research invitations</h3>
                <p>See invitations from researchers and respond to those awaiting your decision.</p>
                <a class="dashboard-card__link" href="my-invitations.php">View invitations <span aria-hidden="true">→</span></a>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card__icon" aria-hidden="true">04</div>
                <h3>My profile</h3>
                <p>Update your interests, availability, preferences and researcher visibility.</p>
                <a class="dashboard-card__link" href="profile.php">Manage profile <span aria-hidden="true">→</span></a>
            </article>
        </div>
    </section>

    <section class="dashboard-shortcuts" aria-labelledby="shortcuts-title">
        <div>
            <h2 id="shortcuts-title">Pick up where you left off</h2>
            <p>Quickly revisit the studies you've saved or hidden.</p>
        </div>
        <div class="dashboard-shortcuts__links">
            <a class="btn dashboard-btn--light" href="browse-studies.php?view=saved">Saved studies</a>
            <a class="btn dashboard-btn--light" href="browse-studies.php?view=hidden">Hidden studies</a>
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
