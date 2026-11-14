<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('volunteer');
$pdo = getConnection();

if (empty($_SESSION['preference_csrf'])) {
    $_SESSION['preference_csrf'] = bin2hex(random_bytes(32));
}
$preferenceNotice = $_SESSION['preference_notice'] ?? null;
unset($_SESSION['preference_notice']);
$view = $_GET['view'] ?? 'all';
if (!is_string($view) || !in_array($view, ['all', 'saved', 'hidden'], true)) {
    $view = 'all';
}

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

$stmt = $pdo->prepare(
    "SELECT s.study_id, s.title, s.topic,
            s.participation_format,
            s.reimbursement_amount,
            s.application_deadline,
            COALESCE(vsp.preference_status, '') AS preference_status
     FROM studies s
     LEFT JOIN volunteer_study_preferences vsp
         ON vsp.study_id = s.study_id AND vsp.volunteer_id = ?
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
       AND (
           (? = 'saved' AND vsp.preference_status = 'saved') OR
           (? = 'hidden' AND vsp.preference_status = 'hidden') OR
           (? = 'all' AND (vsp.preference_status IS NULL OR vsp.preference_status <> 'hidden'))
       )
     ORDER BY s.study_id DESC"
);
$stmt->execute([(int)$user['user_id'], $view, $view, $view]);

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

<form method="get" action="test-studies.php">
    <label for="view">Show:</label>
    <select id="view" name="view">
        <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Available</option>
        <option value="saved" <?= $view === 'saved' ? 'selected' : '' ?>>Saved</option>
        <option value="hidden" <?= $view === 'hidden' ? 'selected' : '' ?>>Hidden</option>
    </select>
    <button type="submit">Apply filter</button>
</form>
<?php if (is_string($preferenceNotice)): ?>
    <p role="status"><?= e($preferenceNotice) ?></p>
<?php endif; ?>
<h3><?= e(ucfirst($view === 'all' ? 'available' : $view)) ?> Test Studies</h3>

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

        
            <form method="post" action="preference-action.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['preference_csrf']) ?>">
                <input type="hidden" name="kind" value="study">
                <input type="hidden" name="item_id" value="<?= (int)$study['study_id'] ?>">
                <input type="hidden" name="return_page" value="test-studies.php">
                <input type="hidden" name="return_query" value="<?= e(http_build_query($_GET)) ?>">
                <button type="submit" name="action" value="<?= $study['preference_status'] === 'saved' ? 'clear' : 'save' ?>">
                    <?= $study['preference_status'] === 'saved' ? 'Remove saved' : 'Save study' ?>
                </button>
                <button type="submit" name="action" value="<?= $study['preference_status'] === 'hidden' ? 'clear' : 'hide' ?>">
                    <?= $study['preference_status'] === 'hidden' ? 'Restore study' : 'Hide study' ?>
                </button>
            </form>

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
