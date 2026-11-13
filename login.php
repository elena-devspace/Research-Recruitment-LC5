<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
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
                password_verify($password, $user['password_hash'])
            ) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int)$user['user_id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                header('Location: login.php');
                exit;
            }

            $error = 'Invalid email or password.';

        } catch (Throwable $exception) {
            error_log('Login error: ' . $exception->getMessage());
            $error = 'Unable to log in right now.';
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
    <title>Login - Research Recruitment</title>
</head>
<body>

<h1>Research Recruitment</h1>

<?php if (isset($_SESSION['user_id'])): ?>

    <h2>Login Successful!</h2>

    <p>
        Welcome,
        <?= escape((string)$_SESSION['first_name']) ?>!
    </p>

    <p>
        Account type:
        <?= escape((string)$_SESSION['role']) ?>
    </p>

    <form action="logout.php" method="post">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape($_SESSION['csrf_token']) ?>"
        >
        <button type="submit">Logout</button>
    </form>

<?php else: ?>

    <h2>Login</h2>

    <?php if ($error !== ''): ?>
        <p role="alert"><?= escape($error) ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape($_SESSION['csrf_token']) ?>"
        >

        <p>
            <label for="email">Email:</label>
            <input
                type="email"
                name="email"
                id="email"
                value="<?= escape($email) ?>"
                autocomplete="email"
                required
            >
        </p>

        <p>
            <label for="password">Password:</label>
            <input
                type="password"
                name="password"
                id="password"
                autocomplete="current-password"
                required
            >
        </p>

        <button type="submit">Login</button>

    </form>

    <p>
        Don't have an account?
        <a href="register.php">Register here</a>
    </p>

<?php endif; ?>

</body>
</html>
