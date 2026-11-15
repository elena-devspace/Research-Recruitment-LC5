<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// The homepage is public; account information is shown only to signed-in users.
$user = isset($_SESSION['user_id']) ? currentUser() : null;
$role = (string) ($user['role'] ?? '');

$dashboardRoutes = [
    'volunteer' => 'volunteer-dashboard.php',
    'academic' => 'academic-dashboard.php',
    'admin' => 'admin-dashboard.php'
];

$dashboard = $dashboardRoutes[$role] ?? null;

if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escapeHome($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Guests register first; signed-in users only access pages for their role.
$findStudyLink = $role === 'volunteer'
    ? 'browse-studies.php'
    : ($dashboard !== null ? '#for-volunteers' : 'register.php');

$recruitLink = $role === 'academic'
    ? 'academic-dashboard.php'
    : ($dashboard !== null ? '#for-researchers' : 'register.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Research Recruitment connects university research volunteers with academic researchers. Learn how to find studies, apply, or recruit participants.">
    <title>Home | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/index.css">
</head>
<body class="home-page">

<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="index.php" aria-label="Research Recruitment homepage">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>

        <nav class="site-nav home-nav" aria-label="Main navigation">
            <a href="index.php" aria-current="page">Home</a>
            <a href="#how-it-works">How it works</a>

            <?php if ($dashboard !== null): ?>
                <a href="<?= escapeHome($dashboard) ?>">Dashboard</a>
                <form method="post" action="logout.php" class="home-logout-form">
                    <input type="hidden" name="csrf_token" value="<?= escapeHome($_SESSION['csrf_token']) ?>">
                    <button type="submit" class="home-nav-logout">Log out</button>
                </form>
            <?php else: ?>
                <a href="login.php">Log in</a>
                <a href="register.php" class="nav-action">Sign up</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="home-main">
    <div class="home-container">
        <section class="home-hero" aria-labelledby="home-title">
            <div class="home-hero-content">
                <p class="home-eyebrow">RESEARCH RECRUITMENT</p>
                <h1 id="home-title">Connecting volunteers <span>with researchers.</span></h1>
                <p class="home-hero-intro">
                    Find research studies you care about, or recruit volunteers for
                    your own university research. Everything starts here.
                </p>

                <?php if ($user !== null): ?>
                    <p class="home-welcome">Welcome back, <strong><?= escapeHome($user['first_name']) ?></strong>!</p>
                <?php endif; ?>

                <div class="home-hero-actions">
                    <a class="home-btn home-btn-primary" href="<?= escapeHome($findStudyLink) ?>">
                        Find a study <span aria-hidden="true">→</span>
                    </a>
                    <a class="home-btn home-btn-secondary" href="<?= escapeHome($recruitLink) ?>">
                        Recruit volunteers <span aria-hidden="true">→</span>
                    </a>
                </div>

                <?php if ($dashboard === null): ?>
                    <p class="home-hero-hint">Already have an account? <a href="login.php">Log in</a> to continue.</p>
                <?php else: ?>
                    <p class="home-hero-hint">Your <a href="<?= escapeHome($dashboard) ?>">dashboard</a> is ready when you are.</p>
                <?php endif; ?>
            </div>

            <div class="home-hero-feature" aria-label="Two ways to use Research Recruitment">
                <div class="home-mini-header">
                    <span class="home-mini-dot"></span>
                    <span class="home-mini-dot"></span>
                    <span class="home-mini-dot"></span>
                    <strong>Research Recruitment</strong>
                </div>
                <div class="home-mini-body">
                    <p class="home-mini-title">Choose your path</p>
                    <div class="home-mini-option">
                        <span class="home-mini-number">01</span>
                        <div><strong>I'm a volunteer</strong><small>Discover opportunities and apply.</small></div>
                        <span class="home-mini-arrow" aria-hidden="true">↗</span>
                    </div>
                    <div class="home-mini-option">
                        <span class="home-mini-number">02</span>
                        <div><strong>I'm a researcher</strong><small>Find participants for a study.</small></div>
                        <span class="home-mini-arrow" aria-hidden="true">↗</span>
                    </div>
                    <div class="home-mini-note">A place for research to come together.</div>
                </div>
            </div>
        </section>

        <section class="home-steps-section" id="how-it-works" aria-labelledby="steps-title">
            <div class="home-section-heading">
                <p class="home-eyebrow">HOW IT WORKS</p>
                <h2 id="steps-title">Getting started is simple.</h2>
                <p>Three steps to start your journey as a research volunteer.</p>
            </div>

            <div class="home-steps" id="for-volunteers">
                <article class="home-step">
                    <span class="home-step-number">01</span>
                    <h3>Sign up</h3>
                    <p>Create a volunteer account and set your interests and availability.</p>
                </article>
                <span class="home-step-connector" aria-hidden="true">→</span>
                <article class="home-step">
                    <span class="home-step-number">02</span>
                    <h3>Browse studies</h3>
                    <p>Explore available studies and find opportunities that suit you.</p>
                </article>
                <span class="home-step-connector" aria-hidden="true">→</span>
                <article class="home-step">
                    <span class="home-step-number">03</span>
                    <h3>Apply</h3>
                    <p>Answer any eligibility questions, apply and track your application status.</p>
                </article>
            </div>
        </section>

        <section class="home-researcher-section" id="for-researchers" aria-labelledby="researcher-title">
            <div class="home-researcher-copy">
                <p class="home-eyebrow">FOR RESEARCHERS</p>
                <h2 id="researcher-title">Your next study starts here.</h2>
                <p>
                    Academic researchers can create studies, discover volunteers who
                    have opted into searches and manage participation in one place.
                </p>
                <p class="home-researcher-note">
                    Researcher accounts must be verified before they can publish
                    studies or invite volunteers.
                </p>
                <a class="home-btn home-btn-primary" href="<?= escapeHome($recruitLink) ?>">
                    Explore researcher tools <span aria-hidden="true">→</span>
                </a>
            </div>

            <div class="home-researcher-flow" aria-label="Researcher workflow">
                <div><span>1</span><p><strong>Create your study</strong><small>Set out your research and participant requirements.</small></p></div>
                <div><span>2</span><p><strong>Find volunteers</strong><small>Search opted-in profiles and send invitations.</small></p></div>
                <div><span>3</span><p><strong>Manage recruitment</strong><small>Review applications and track responses.</small></p></div>
            </div>
        </section>

        <?php if ($dashboard === null): ?>
            <section class="home-final-cta" aria-labelledby="final-cta-title">
                <div>
                    <p class="home-eyebrow">READY TO BEGIN?</p>
                    <h2 id="final-cta-title">Be part of the next research project.</h2>
                    <p>Join as a volunteer or register as an academic researcher.</p>
                </div>
                <a class="home-btn home-btn-primary" href="register.php">Create an account <span aria-hidden="true">→</span></a>
            </section>
        <?php endif; ?>
    </div>
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <span>© <?= date('Y') ?> Research Recruitment · KV6014 university project</span>
        <div class="site-footer__items" aria-label="Additional information">
            <span>Privacy Policy</span>
            <span>Contact</span>
            <span>Terms</span>
        </div>
    </div>
</footer>

</body>
</html>
