<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('academic');

$name = htmlspecialchars(
    $user['first_name'],
    ENT_QUOTES,
    'UTF-8'
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Academic Dashboard</title>
</head>
<body>

<h1>Academic Dashboard</h1>

<p>Welcome, <?= $name ?>!</p>

<p>You are logged in as an academic researcher.</p>

<p>
    <a href="review-applications.php">
        Review Volunteer Applications
    </a>
</p>

<p>
    <a href="my-sent-requests.php">
        View Sent Invitations
    </a>
</p>

</body>
</html>
