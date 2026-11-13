<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Only administrators may access this page.
$user = requireRole('admin');

$pdo = getConnection();

// Count registered accounts.
$totalUsers = (int)$pdo
    ->query("SELECT COUNT(*) FROM users")
    ->fetchColumn();

// Count researchers awaiting approval.
$pendingResearchers = (int)$pdo
    ->query(
        "SELECT COUNT(*)
         FROM academic_profiles ap
         INNER JOIN users u ON u.user_id = ap.user_id
         WHERE u.role = 'academic'
           AND ap.verification_status = 'pending'"
    )
    ->fetchColumn();

// Count research studies.
$totalStudies = (int)$pdo
    ->query("SELECT COUNT(*) FROM studies")
    ->fetchColumn();

function e(string $value): string {
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
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Administrator Dashboard</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Administrator Dashboard</h2>

<p>
    Welcome, <?= e($user['first_name']) ?>!
</p>

<p>
    You are logged in as an administrator.
</p>

<h3>Platform Overview</h3>

<p>
    Total Registered Users:
    <?= $totalUsers ?>
</p>

<p>
    Total Research Studies:
    <?= $totalStudies ?>
</p>

<p>
    Researchers Awaiting Verification:
    <?= $pendingResearchers ?>
</p>

<h3>Administration</h3>

<p>
    <a href="review-researchers.php">
        Review Pending Researchers
    </a>
</p>

</body>
</html>
