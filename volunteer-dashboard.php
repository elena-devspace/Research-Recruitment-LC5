<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('volunteer');

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
    <title>Volunteer Dashboard</title>
</head>
<body>

<h1>Volunteer Dashboard</h1>

<p>Welcome, <?= $name ?>!</p>

<p>You are logged in as a volunteer.</p>

<h2>Volunteer Services</h2>

<p>
    <a href="browse-studies.php">
        Browse Research Studies
    </a>
</p>

<p>
    <a href="profile.php">
        Manage My Profile
    </a>
</p>


<p>
    <a href="my-applications.php">
        My Applications
    </a>
</p>


</body>
</html>
