<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/db.php';

session_start();

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$dashboardRoutes = [
    'volunteer' => '../volunteer/volunteer-dashboard.php',
    'academic' => '../researcher/academic-dashboard.php',
    'admin' => '../admin/admin-dashboard.php'
];

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SESSION['user_id'])) {
    $submittedEmail = $_POST['email'] ?? '';
    $email = is_string($submittedEmail) ? strtolower(trim($submittedEmail)) : '';
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid request. Please refresh the page.';
    } elseif (!is_string($password)) {
        $error = 'Invalid email or password.';
    } else {
        try {
            $pdo = getConnection();
            $stmt = $pdo->prepare(
                'SELECT user_id, first_name, email, password_hash, role
                 FROM users
                 WHERE email = :email
                 LIMIT 1'
            );
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (
                $user &&
                isset($dashboardRoutes[(string)$user['role']]) &&
                password_verify($password, (string)$user['password_hash'])
            ) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['first_name'] = (string)$user['first_name'];
                $_SESSION['role'] = (string)$user['role'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: ' . $dashboardRoutes[$user['role']]);
                exit;
            }

            $error = 'Invalid email or password.';
        } catch (Throwable $exception) {
            error_log('Login error: ' . $exception->getMessage());
            $error = 'Unable to log in right now.';
        }
    }
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$isLoggedIn = isset($_SESSION['user_id']);
$dashboard = $dashboardRoutes[(string)($_SESSION['role'] ?? '')] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="../../index.php" aria-label="Research Recruitment homepage">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav" aria-label="Main navigation">
            <a href="login.php" aria-current="page">Log in</a>
            <a href="register.php" class="nav-action">Sign up</a>
        </nav>
    </div>
</header>

<main class="page-shell auth-layout">
    <section class="auth-intro" aria-label="Welcome">
        <div class="eyebrow">Research Recruitment</div>
        <h1>Make research happen together.</h1>
        <p>Discover university studies, volunteer for research, or recruit participants for your next project.</p>
        <ul class="auth-points">
            <li>Explore research opportunities</li>
            <li>Connect researchers and volunteers</li>
            <li>Manage your participation in one place</li>
        </ul>
    </section>

    <section class="auth-card" aria-labelledby="login-heading">
        <?php if ($isLoggedIn): ?>
            <h2 id="login-heading">You're signed in!</h2>
            <p class="auth-subtitle">Welcome back, <?= escape((string)($_SESSION['first_name'] ?? '')) ?>.</p>
            <?php if ($dashboard !== null): ?>
                <a class="btn btn--primary btn--block" href="<?= escape($dashboard) ?>">Go to Dashboard</a>
            <?php endif; ?>
            <form method="post" action="../../actions/account/logout.php">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <p class="auth-footer-text"><button type="submit" class="btn">Log out</button></p>
            </form>
        <?php else: ?>
            <h2 id="login-heading">Welcome back</h2>
            <p class="auth-subtitle">Log in to continue your research journey.</p>

            <?php if ($error !== ''): ?>
                <div class="feedback feedback--error" role="alert">
                    <?= escape($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <div class="form-grid">
                    <div class="field field--full">
                        <label for="email">Email address</label>
                        <input type="email" name="email" id="email" maxlength="255" value="<?= escape($email) ?>" autocomplete="email" required>
                    </div>
                    <div class="field field--full">
                        <label for="password">Password</label>
                        <input type="password" name="password" id="password" autocomplete="current-password" required>
                    </div>
                    <div class="field field--full">
                        <button type="submit" class="btn btn--primary btn--block">Log In</button>
                    </div>
                </div>
            </form>
            <p class="auth-footer-text">Don't have an account? <a href="register.php">Sign up</a></p>
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
