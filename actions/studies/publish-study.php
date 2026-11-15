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

// Retrieve only a study belonging to this academic.
$stmt = $pdo->prepare(
    "SELECT s.*, ap.verification_status
     FROM studies s
     INNER JOIN academic_profiles ap
         ON ap.user_id = s.researcher_id
     WHERE s.study_id = ?
       AND s.researcher_id = ?
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

if ($study['verification_status'] !== 'verified') {
    http_response_code(403);
    exit('Your researcher account must be verified before publishing.');
}

if ($study['status'] !== 'draft') {
    http_response_code(403);
    exit('Only draft studies can be published.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check the draft is ready for publication.
$issues = [];

$required = [
    'title',
    'topic',
    'aim',
    'description',
    'participation_tasks',
    'eligibility_requirements',
    'participation_format'
];

foreach ($required as $field) {
    if (trim((string)($study[$field] ?? '')) === '') {
        $issues[] = 'Missing information: ' .
            str_replace('_', ' ', $field);
    }
}

foreach ([
    'start_date',
    'end_date',
    'application_deadline'
] as $field) {
    if (empty($study[$field])) {
        $issues[] = 'A valid ' .
            str_replace('_', ' ', $field) .
            ' is required before publication.';
    }
}

if (
    !empty($study['start_date']) &&
    !empty($study['end_date']) &&
    $study['start_date'] > $study['end_date']
) {
    $issues[] = 'The study dates are inconsistent.';
}

if (
    !empty($study['application_deadline']) &&
    !empty($study['end_date']) &&
    $study['application_deadline'] > $study['end_date']
) {
    $issues[] = 'The application deadline is after the end date.';
}

if (
    !empty($study['application_deadline']) &&
    $study['application_deadline'] < date('Y-m-d')
) {
    $issues[] = 'The application deadline has passed.';
}

if (
    in_array(
        $study['participation_format'],
        ['in_person', 'hybrid'],
        true
    ) &&
    trim((string)$study['location']) === ''
) {
    $issues[] = 'A study location is required.';
}

if ((float)$study['reimbursement_amount'] < 0) {
    $issues[] = 'Reimbursement cannot be negative.';
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

    } elseif (!empty($issues)) {
        $error = 'The study is not ready for publication.';

    } else {
        try {
            // Recheck ownership, draft status and verification
            // when performing the actual database update.
            $update = $pdo->prepare(
                "UPDATE studies s
                 INNER JOIN academic_profiles ap
                     ON ap.user_id = s.researcher_id
                 SET s.status = 'open'
                 WHERE s.study_id = ?
                   AND s.researcher_id = ?
                   AND s.status = 'draft'
                   AND ap.verification_status = 'verified'"
            );

            $update->execute([
                $studyId,
                $user['user_id']
            ]);

            if ($update->rowCount() === 1) {
                $_SESSION['published_study_id'] = $studyId;

                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                header('Location: ../../pages/researcher/my-studies.php');
                exit;
            }

            $error = 'Study could not be published.';

        } catch (Throwable $exception) {
            error_log(
                'Study publication failed: ' .
                $exception->getMessage()
            );

            $error = 'Unable to publish study.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Publish Research Study</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Publish Research Study</h2>

<h3><?= e($study['title']) ?></h3>

<p>Study ID: <?= (int)$studyId ?></p>
<p>Current status: <?= e($study['status']) ?></p>
<p>Researcher verification: Verified</p>

<?php if ($error !== ''): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<?php if (!empty($issues)): ?>

    <h3>Publication Requirements</h3>
    <p>This study cannot be published yet:</p>

    <ul>
        <?php foreach ($issues as $issue): ?>
            <li><?= e($issue) ?></li>
        <?php endforeach; ?>
    </ul>

    <p>
        <a href="../../pages/researcher/edit-study.php?id=<?= (int)$studyId ?>">
            Edit Study
        </a>
    </p>

<?php else: ?>

    <p>The study has passed the basic publication checks.</p>

    <p>
        Once published, the study will have an
        open status and can be included in study searches.
    </p>

    <p>
        Confirm that the study information is accurate.
        Fictional studies must be clearly identified
        as test data.
    </p>

    <form method="post"
          action="publish-study.php?id=<?= (int)$studyId ?>">

        <input type="hidden"
               name="csrf_token"
               value="<?= e($_SESSION['csrf_token']) ?>">

        <button type="submit">
            Confirm Publication
        </button>
    </form>

<?php endif; ?>

<p>
    <a href="../../pages/researcher/my-studies.php">
        Cancel — Back to My Studies
    </a>
</p>

</body>
</html>
