<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('volunteer');
$pdo = getConnection();

// Temporary development-only restriction.
$allowedTestUsers = [1, 6, 7];

if (!in_array((int)$user['user_id'], $allowedTestUsers, true)) {
    http_response_code(403);
    exit('Access denied.');
}

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$stmt = $pdo->query(
    "SELECT s.study_id, s.title, s.topic,
            s.participation_format,
            s.reimbursement_amount,
            s.application_deadline
     FROM studies s
     INNER JOIN users u
         ON u.user_id = s.researcher_id
     INNER JOIN academic_profiles ap
         ON ap.user_id = u.user_id
     WHERE s.status = 'open'
       AND s.is_test = 1
       AND u.role = 'academic'
       AND ap.verification_status = 'verified'
       AND s.application_deadline >= CURDATE()
       AND s.end_date >= CURDATE()
     ORDER BY s.study_id DESC"
);

$studies = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Test Research Applications</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Volunteer Application Testing</h2>

<p>Welcome, <?= e($user['first_name']) ?>!</p>

<p>
    TEST ENVIRONMENT ONLY.
    These studies are fictional.
    No real recruitment or payment takes place.
</p>

<h3>Available Test Studies</h3>

<?php if (!$studies): ?>

    <p>No test studies available.</p>

<?php else: ?>

    <?php foreach ($studies as $study): ?>

        <h3><?= e($study['title']) ?></h3>

        <p>Topic: <?= e($study['topic']) ?></p>

        <p>
            Participation:
            <?= e($study['participation_format']) ?>
        </p>

        <p>
            Reimbursement (fictional):
            £<?= number_format(
                (float)$study['reimbursement_amount'],
                2
            ) ?>
        </p>

        <p>
            <a href="apply-study.php?id=<?= (int)$study['study_id'] ?>">
                Apply to Test Study
            </a>
        </p>

        <hr>

    <?php endforeach; ?>

<?php endif; ?>

<p>
    <a href="volunteer-dashboard.php">
        Back to Volunteer Dashboard
    </a>
</p>

</body>
</html>
