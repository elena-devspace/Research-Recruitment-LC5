<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$firstName = '';
$lastName = '';
$email = '';
$role = 'volunteer';

$success = isset($_GET['success'])
    && $_GET['success'] === '1'
    && !empty($_SESSION['registration_success']);

unset($_SESSION['registration_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $role = (string)($_POST['role'] ?? '');

    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = 'Invalid request. Please refresh the page.';
    }

    if ($firstName === '' || mb_strlen($firstName) > 100) {
        $errors[] = 'Enter a valid first name (maximum 100 characters).';
    }

    if ($lastName === '' || mb_strlen($lastName) > 100) {
        $errors[] = 'Enter a valid last name (maximum 100 characters).';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 255) {
        $errors[] = 'Enter a valid email address.';
    }

    if (!in_array($role, ['volunteer', 'academic'], true)) {
        $errors[] = 'Select a valid account type.';
    }

    if (mb_strlen($password) < 12 || strlen($password) > 72) {
        $errors[] = 'Password must contain at least 12 characters and be no longer than 72 bytes.';
    }

    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $pdo = getConnection();

            $statement = $pdo->prepare(
                "INSERT INTO users
                (first_name, last_name, email, password_hash, role)
                VALUES
                (:first_name, :last_name, :email, :password_hash, :role)"
            );

            $statement->execute([
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':email' => $email,
                ':password_hash' => password_hash(
                    $password,
                    PASSWORD_DEFAULT
                ),
                ':role' => $role
            ]);

            $_SESSION['registration_success'] = true;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: register.php?success=1');
            exit;

        } catch (PDOException $exception) {

            if ($exception->getCode() === '23000') {
                $errors[] = 'This email address may already be registered.';
            } else {
                error_log('Registration failed: ' . $exception->getMessage());
                $errors[] = 'Registration failed. Please try again later.';
            }
        }
    }
}

function escape(string $value): string {
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create an Account | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="login.php">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav" aria-label="Main navigation">
            <a href="login.php">Log in</a>
            <a href="register.php" class="nav-action" aria-current="page">Sign up</a>
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

    <section class="auth-card" aria-labelledby="register-heading">
        <?php if ($success): ?>
            <div class="feedback feedback--success" role="status">
                <h2 id="register-heading">Account created!</h2>
                <p>You can now sign in with your new account.</p>
            </div>
            <a class="btn btn--primary btn--block" href="login.php">Go to Login</a>
        <?php else: ?>
            <h2 id="register-heading">Create an account</h2>
            <p class="auth-subtitle">Join as a volunteer or an academic researcher.</p>

            <?php if (!empty($errors)): ?>
                <div class="feedback feedback--error" role="alert">
                    <h3>Please check the following</h3>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= escape($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="register.php">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <div class="form-grid">
                    <div class="field field--full">
                        <span class="field-label" id="role-label">I am a...</span>
                        <div class="role-grid" role="group" aria-labelledby="role-label">
                            <label class="role-choice">
                                <input type="radio" name="role" value="volunteer" <?= $role === 'volunteer' ? 'checked' : '' ?> required>
                                Volunteer
                            </label>
                            <label class="role-choice">
                                <input type="radio" name="role" value="academic" <?= $role === 'academic' ? 'checked' : '' ?> required>
                                Academic Researcher
                            </label>
                        </div>
                    </div>
                    <div class="field">
                        <label for="first_name">First name</label>
                        <input id="first_name" name="first_name" type="text" maxlength="100" value="<?= escape($firstName) ?>" autocomplete="given-name" required>
                    </div>
                    <div class="field">
                        <label for="last_name">Last name</label>
                        <input id="last_name" name="last_name" type="text" maxlength="100" value="<?= escape($lastName) ?>" autocomplete="family-name" required>
                    </div>
                    <div class="field field--full">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" maxlength="255" value="<?= escape($email) ?>" autocomplete="email" required>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
                        <span class="form-hint">At least 12 characters.</span>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm password</label>
                        <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="field field--full">
                        <button class="btn btn--primary btn--block" type="submit">Create Account</button>
                    </div>
                </div>
            </form>
            <p class="auth-footer-text">Already have an account? <a href="login.php">Log in</a></p>
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
