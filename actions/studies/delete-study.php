<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';

$user = requireRole('academic');
$pdo = getConnection();

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Validate the study ID.
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

// Find a draft owned by the current researcher.
$stmt = $pdo->prepare(
    "SELECT study_id, title, status
     FROM studies
     WHERE study_id = ?
       AND researcher_id = ?
       AND status = 'draft'
     LIMIT 1"
);

$stmt->execute([
    $studyId,
    $user['user_id']
]);

$study = $stmt->fetch();

if (!$study) {
    http_response_code(404);
    exit('Draft not found or access denied.');
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
        $error = 'Invalid request. Please refresh the page.';
    } else {
        try {
            $delete = $pdo->prepare(
                "DELETE FROM studies
                 WHERE study_id = ?
                   AND researcher_id = ?
                   AND status = 'draft'"
            );

            $delete->execute([
                $studyId,
                $user['user_id']
            ]);

            if ($delete->rowCount() === 1) {
                $_SESSION['deleted_study_title'] =
                    $study['title'];

                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                header('Location: ../../pages/researcher/my-studies.php');
                exit;
            }

            $error = 'The draft could not be deleted.';

        } catch (Throwable $exception) {
            error_log(
                'Study deletion failed: ' .
                $exception->getMessage()
            );

            $error = 'Unable to delete study.';
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
    <title>Delete Research Study</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Delete Research Study</h2>

<?php if ($error !== ''): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<p>
    Are you sure you want to permanently delete
    the following draft?
</p>

<h3><?= e($study['title']) ?></h3>

<p>Study ID: <?= (int)$study['study_id'] ?></p>
<p>Status: <?= e($study['status']) ?></p>

<p>
    This action cannot be undone.
</p>

<form
    method="post"
    action="delete-study.php?id=<?= (int)$studyId ?>"
>
    <input
        type="hidden"
        name="csrf_token"
        value="<?= e($_SESSION['csrf_token']) ?>"
    >

    <button type="submit">
        Yes, Permanently Delete Draft
    </button>
</form>

<p>
    <a href="../../pages/researcher/my-studies.php">
        Cancel — Keep My Study
    </a>
</p>

</body>
</html>
