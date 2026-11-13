<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $pdo = getConnection();

    $stmt = $pdo->prepare(
        "SELECT user_id, first_name, last_name, email, role
         FROM users
         WHERE user_id = :user_id
         LIMIT 1"
    );

    $stmt->execute([
        ':user_id' => $_SESSION['user_id']
    ]);

    $user = $stmt->fetch();

    return $user ?: null;
}

function requireLogin(): array
{
    $user = currentUser();

    if ($user === null) {
        header('Location: login.php');
        exit;
    }

    return $user;
}

function requireRole(string $requiredRole): array
{
    $user = requireLogin();

    if ($user['role'] !== $requiredRole) {
        http_response_code(403);
        exit('Access denied. You do not have permission to view this page.');
    }

    return $user;
}
