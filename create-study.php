<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('academic');
$pdo = getConnection();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(string $value): string {
    return htmlspecialchars(
        $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function validDate(string $value): bool {
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $value
    );

    return $date !== false
        && $date->format('Y-m-d') === $value;
}

$fields = [
    'title',
    'topic',
    'aim',
    'description',
    'participation_tasks',
    'eligibility_requirements',
    'participation_format',
    'location',
    'start_date',
    'end_date',
    'application_deadline',
    'reimbursement_amount',
    'reimbursement_details'
];

$data = array_fill_keys($fields, '');
$data['reimbursement_amount'] = '0.00';

$errors = [];
$createdId = $_SESSION['study_created'] ?? null;
unset($_SESSION['study_created']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Invalid request. Refresh the page.';
    }

    foreach ($fields as $field) {
        $value = $_POST[$field] ?? '';
        $data[$field] = is_string($value)
            ? trim($value)
            : '';
    }

    $required = [
        'title',
        'topic',
        'aim',
        'description',
        'participation_tasks',
        'eligibility_requirements'
    ];

    foreach ($required as $field) {
        if ($data[$field] === '') {
            $errors[] = 'Please complete: ' .
                str_replace('_', ' ', $field);
        }
    }

    $limits = [
        'title' => 255,
        'topic' => 150,
        'location' => 150,
        'reimbursement_details' => 255,
        'aim' => 10000,
        'description' => 10000,
        'participation_tasks' => 10000,
        'eligibility_requirements' => 10000
    ];

    foreach ($limits as $field => $limit) {
        if (mb_strlen($data[$field]) > $limit) {
            $errors[] = str_replace('_', ' ', $field)
                . ' is too long.';
        }
    }

    $formats = ['online', 'in_person', 'hybrid'];

    if (!in_array(
        $data['participation_format'],
        $formats,
        true
    )) {
        $errors[] = 'Select a valid participation format.';
    }

    if (
        in_array(
            $data['participation_format'],
            ['in_person', 'hybrid'],
            true
        ) &&
        $data['location'] === ''
    ) {
        $errors[] = 'Location is required for in-person studies.';
    }

    foreach ([
        'start_date',
        'end_date',
        'application_deadline'
    ] as $field) {
        if (!validDate($data[$field])) {
            $errors[] = 'Invalid date: ' . $field;
        }
    }

    if (
        $data['start_date'] !== '' &&
        $data['end_date'] !== '' &&
        $data['start_date'] > $data['end_date']
    ) {
        $errors[] = 'End date must be after start date.';
    }

    if (
        $data['application_deadline'] !== '' &&
        $data['end_date'] !== '' &&
        $data['application_deadline'] > $data['end_date']
    ) {
        $errors[] = 'Application deadline cannot be after the end date.';
    }

    if (!preg_match(
        '/^\d{1,8}(\.\d{1,2})?$/D',
        $data['reimbursement_amount']
    )) {
        $errors[] = 'Enter a valid reimbursement amount.';
    }

    $anonymous = (
        ($_POST['anonymous_participation_allowed'] ?? '')
        === '1'
    ) ? 1 : 0;

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO studies (
                    researcher_id,
                    title,
                    topic,
                    aim,
                    description,
                    participation_tasks,
                    eligibility_requirements,
                    participation_format,
                    location,
                    start_date,
                    end_date,
                    application_deadline,
                    reimbursement_amount,
                    reimbursement_details,
                    anonymous_participation_allowed,
                    status
                ) VALUES (
                    :researcher_id,
                    :title,
                    :topic,
                    :aim,
                    :description,
                    :tasks,
                    :eligibility,
                    :format,
                    :location,
                    :start_date,
                    :end_date,
                    :deadline,
                    :amount,
                    :details,
                    :anonymous,
                    'draft'
                )"
            );

            $stmt->execute([
                'researcher_id' => $user['user_id'],
                'title' => $data['title'],
                'topic' => $data['topic'],
                'aim' => $data['aim'],
                'description' => $data['description'],
                'tasks' => $data['participation_tasks'],
                'eligibility' => $data['eligibility_requirements'],
                'format' => $data['participation_format'],
                'location' => $data['location'] ?: null,
                'start_date' => $data['start_date'] ?: null,
                'end_date' => $data['end_date'] ?: null,
                'deadline' => $data['application_deadline'] ?: null,
                'amount' => $data['reimbursement_amount'],
                'details' => $data['reimbursement_details'] ?: null,
                'anonymous' => $anonymous
            ]);

            $_SESSION['study_created'] =
                (int)$pdo->lastInsertId();

            $_SESSION['csrf_token'] =
                bin2hex(random_bytes(32));

            header('Location: create-study.php');
            exit;

        } catch (Throwable $exception) {
            error_log(
                'Study creation error: ' .
                $exception->getMessage()
            );

            $errors[] = 'Unable to create study.';
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
    <title>Create Research Study</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Create Research Study</h2>

<p>Researcher:
    <?= e($user['first_name']) ?>
    <?= e($user['last_name']) ?>
</p>

<p>New studies are saved as drafts.</p>

<?php if ($createdId !== null): ?>
    <p role="status">
        Study #<?= (int)$createdId ?>
        created successfully as a draft!
    </p>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div role="alert">
        <h3>Please correct the following:</h3>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="create-study.php">

<input type="hidden"
       name="csrf_token"
       value="<?= e($_SESSION['csrf_token']) ?>">

<p>
    <label for="title">Study Title:</label>
    <input type="text" id="title" name="title"
           maxlength="255"
           value="<?= e($data['title']) ?>" required>
</p>

<p>
    <label for="topic">Research Topic:</label>
    <input type="text" id="topic" name="topic"
           maxlength="150"
           value="<?= e($data['topic']) ?>" required>
</p>

<?php
$textareas = [
    'aim' => 'Research Aim',
    'description' => 'Study Description',
    'participation_tasks' => 'Participant Tasks',
    'eligibility_requirements' => 'Eligibility Requirements'
];

foreach ($textareas as $field => $label):
?>
    <p>
        <label for="<?= e($field) ?>">
            <?= e($label) ?>:
        </label>
        <textarea
            id="<?= e($field) ?>"
            name="<?= e($field) ?>"
            rows="4"
            maxlength="10000"
            required><?= e($data[$field]) ?></textarea>
    </p>
<?php endforeach; ?>

<p>
    <label for="participation_format">
        Participation Format:
    </label>

    <select id="participation_format"
            name="participation_format" required>
        <option value="">Select format</option>

        <?php
        $formatLabels = [
            'online' => 'Online',
            'in_person' => 'In Person',
            'hybrid' => 'Hybrid'
        ];

        foreach ($formatLabels as $value => $label):
        ?>
            <option value="<?= e($value) ?>"
                <?= $data['participation_format'] === $value
                    ? 'selected' : '' ?>>
                <?= e($label) ?>
            </option>
        <?php endforeach; ?>
    </select>
</p>

<p>
    <label for="location">Location:</label>
    <input type="text" id="location" name="location"
           maxlength="150"
           value="<?= e($data['location']) ?>">
</p>

<?php
$dateLabels = [
    'start_date' => 'Start Date',
    'end_date' => 'End Date',
    'application_deadline' => 'Application Deadline'
];

foreach ($dateLabels as $field => $label):
?>
    <p>
        <label for="<?= e($field) ?>">
            <?= e($label) ?>:
        </label>
        <input type="date"
               id="<?= e($field) ?>"
               name="<?= e($field) ?>"
               value="<?= e($data[$field]) ?>">
    </p>
<?php endforeach; ?>

<p>
    <label for="reimbursement_amount">
        Reimbursement Amount (£):
    </label>
    <input type="number"
           id="reimbursement_amount"
           name="reimbursement_amount"
           min="0"
           step="0.01"
           value="<?= e($data['reimbursement_amount']) ?>"
           required>
</p>

<p>
    <label for="reimbursement_details">
        Reimbursement Details:
    </label>
    <input type="text"
           id="reimbursement_details"
           name="reimbursement_details"
           maxlength="255"
           value="<?= e($data['reimbursement_details']) ?>">
</p>

<p>
    <label>
        <input type="checkbox"
               name="anonymous_participation_allowed"
               value="1"
               <?= (
                   ($_POST['anonymous_participation_allowed'] ?? '')
                   === '1'
               ) ? 'checked' : '' ?>>
        Anonymous participation allowed
    </label>
</p>

<button type="submit">Save Study as Draft</button>

</form>

<hr>

<p>
    <a href="academic-dashboard.php">
        Back to Academic Dashboard
    </a>
</p>

</body>
</html>
