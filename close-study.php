<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('academic');
$pdo = getConnection();

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$studyId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!is_int($studyId)) {
    http_response_code(400);
    exit('Invalid study ID.');
}

// Only retrieve studies owned by the logged-in academic.
$stmt = $pdo->prepare(
    "SELECT study_id, title, status
     FROM studies
     WHERE study_id = ?
       AND researcher_id = ?
     LIMIT 1"
);

$stmt->execute([
    $studyId,
    $user['user_id']
]);

$study = $stmt->fetch();

if (!$study) {
    http_response_code(404);
    exit('Study not found or access denied.');
}

if ($study['status'] !== 'open') {
    http_response_code(403);
    exit('Only open studies can be closed.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        $error = 'Invalid request. Refresh the page.';
    } else {
        try {
            $update = $pdo->prepare(
                "UPDATE studies
                 SET status = 'closed'
                 WHERE study_id = ?
                   AND researcher_id = ?
                   AND status = 'open'"
            );

            $update->execute([
                $studyId,
                $user['user_id']
            ]);

            if ($update->rowCount() === 1) {

                $_SESSION['closed_study_id'] = $studyId;
                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                header('Location: my-studies.php');
                exit;
            }

            $error = 'Study could not be closed.';

        } catch (Throwable $exception) {
            error_log(
                'Study closure failed: ' .
                $exception->getMessage()
            );

            $error = 'Unable to close study.';
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Close Research Study</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Close Research Study</h2>

<h3><?= e($study['title']) ?></h3>

<p>Study ID: <?= (int)$studyId ?></p>
<p>Current status: <?= e($study['status']) ?></p>

<?php if ($error !== ''): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<p>
    Are you sure you want to close this research study?
</p>

<p>
    Once closed, the study will no longer accept
    new applications when recruitment is implemented.
</p>

<p>
    Closing a study does not delete its information.
</p>

<form method="post"
      action="close-study.php?id=<?= (int)$studyId ?>">

    <input type="hidden"
           name="csrf_token"
           value="<?= e($_SESSION['csrf_token']) ?>">

    <button type="submit">
        Yes, Close Study
    </button>

</form>

<p>
    <a href="my-studies.php">
        Cancel — Keep Study Open
    </a>
</p>

</body>
</html>
