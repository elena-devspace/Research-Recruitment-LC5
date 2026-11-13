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
    <title>Register - KV6014 Research Recruitment</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Create an Account</h2>

<?php if ($success): ?>

    <p>Account created successfully!</p>
    <p>You can now proceed to login once the login page is available.</p>

<?php else: ?>

    <?php if (!empty($errors)): ?>
        <div role="alert">
            <h3>Please correct the following:</h3>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= escape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="register.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= escape($_SESSION['csrf_token']) ?>"
        >

        <p>
            <label for="first_name">First Name:</label>
            <input
                type="text"
                id="first_name"
                name="first_name"
                maxlength="100"
                value="<?= escape($firstName) ?>"
                autocomplete="given-name"
                required
            >
        </p>

        <p>
            <label for="last_name">Last Name:</label>
            <input
                type="text"
                id="last_name"
                name="last_name"
                maxlength="100"
                value="<?= escape($lastName) ?>"
                autocomplete="family-name"
                required
            >
        </p>

        <p>
            <label for="email">Email Address:</label>
            <input
                type="email"
                id="email"
                name="email"
                maxlength="255"
                value="<?= escape($email) ?>"
                autocomplete="email"
                required
            >
        </p>

        <p>
            <label for="password">Password:</label>
            <input
                type="password"
                id="password"
                name="password"
                minlength="12"
                autocomplete="new-password"
                required
            >
        </p>

        <p>
            <label for="confirm_password">Confirm Password:</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                autocomplete="new-password"
                required
            >
        </p>

        <p>
            <label for="role">Account Type:</label>
            <select id="role" name="role" required>
                <option value="volunteer"
                    <?= $role === 'volunteer' ? 'selected' : '' ?>>
                    Volunteer
                </option>
                <option value="academic"
                    <?= $role === 'academic' ? 'selected' : '' ?>>
                    Academic Researcher
                </option>
            </select>
        </p>

        <button type="submit">Create Account</button>

    </form>

<?php endif; ?>

</body>
</html>
