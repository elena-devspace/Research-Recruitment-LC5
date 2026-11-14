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

function validDate(string $value): bool {
    if ($value === '') {
        return true;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false
        && $date->format('Y-m-d') === $value;
}

// Identify the study being edited.
$studyId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (!$studyId) {
    http_response_code(400);
    exit('Invalid study ID.');
}

// Check ownership directly in the database.
$stmt = $pdo->prepare(
    'SELECT * FROM studies
     WHERE study_id = ?
       AND researcher_id = ?
     LIMIT 1'
);

$stmt->execute([$studyId, $user['user_id']]);
$study = $stmt->fetch();

if (!$study) {
    http_response_code(404);
    exit('Study not found or access denied.');
}

// Only drafts can be edited for now.
if ($study['status'] !== 'draft') {
    http_response_code(403);
    exit('Only draft studies can currently be edited.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$updated = ($_SESSION['study_updated'] ?? null) === $studyId;
unset($_SESSION['study_updated']);

$errors = [];

$fields = [
    'title',
    'topic',
    'aim',
    'description',
    'participation_tasks',
    'eligibility_requirements',
    'participant_capacity',
    'participation_format',
    'location',
    'start_date',
    'end_date',
    'application_deadline',
    'reimbursement_amount',
    'reimbursement_details'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Invalid request. Refresh the page.';
    }

    // Read submitted values.
    foreach ($fields as $field) {
        $value = $_POST[$field] ?? '';

        $study[$field] = is_string($value)
            ? trim($value)
            : '';
    }

    $study['anonymous_participation_allowed'] =
        ($_POST['anonymous_participation_allowed'] ?? null) === '1'
            ? 1 : 0;

    // Required information.
    $required = [
        'title',
        'topic',
        'aim',
        'description',
        'participation_tasks',
        'eligibility_requirements'
    ];

    foreach ($required as $field) {
        if ($study[$field] === '') {
            $errors[] = 'Required: ' . str_replace('_', ' ', $field);
        }
    }

    // Maximum field lengths.
    $limits = [
        'title' => 255,
        'topic' => 150,
        'aim' => 10000,
        'description' => 10000,
        'participation_tasks' => 10000,
        'eligibility_requirements' => 10000,
        'location' => 150,
        'reimbursement_details' => 255
    ];

    foreach ($limits as $field => $limit) {
        if (mb_strlen($study[$field]) > $limit) {
            $errors[] = str_replace('_', ' ', $field)
                . ' is too long.';
        }
    }

    // Participation format.
    if (!in_array(
        $study['participation_format'],
        ['online', 'in_person', 'hybrid'],
        true
    )) {
        $errors[] = 'Invalid participation format.';
    }

    if (
        in_array(
            $study['participation_format'],
            ['in_person', 'hybrid'],
            true
        ) &&
        $study['location'] === ''
    ) {
        $errors[] = 'Location is required for in-person studies.';
    }

    // Date validation.
    foreach ([
        'start_date',
        'end_date',
        'application_deadline'
    ] as $field) {
        if (!validDate($study[$field])) {
            $errors[] = 'Invalid date: ' . $field;
        }
    }

    if (
        $study['start_date'] !== '' &&
        $study['end_date'] !== '' &&
        $study['start_date'] > $study['end_date']
    ) {
        $errors[] = 'End date must not be before start date.';
    }

    if (
        $study['application_deadline'] !== '' &&
        $study['end_date'] !== '' &&
        $study['application_deadline'] > $study['end_date']
    ) {
        $errors[] = 'Application deadline must not be after end date.';
    }

    // Reimbursement validation.
    if (!preg_match(
        '/^\d{1,8}(\.\d{1,2})?$/D',
        $study['reimbursement_amount']
    )) {
        $errors[] = 'Invalid reimbursement amount.';
    }

    if ($study['participant_capacity'] !== '' && (
        !ctype_digit($study['participant_capacity']) ||
        (int)$study['participant_capacity'] < 1 ||
        (int)$study['participant_capacity'] > 10000
    )) {
        $errors[] = 'Enter a participant capacity between 1 and 10000.';
    }

    if (empty($errors)) {
        try {

            $sql = "UPDATE studies SET
                title = :title,
                topic = :topic,
                aim = :aim,
                description = :description,
                participation_tasks = :participation_tasks,
                eligibility_requirements = :eligibility_requirements,
                participant_capacity = :participant_capacity,
                participation_format = :participation_format,
                location = :location,
                start_date = :start_date,
                end_date = :end_date,
                application_deadline = :application_deadline,
                reimbursement_amount = :reimbursement_amount,
                reimbursement_details = :reimbursement_details,
                anonymous_participation_allowed = :anonymous
                WHERE study_id = :study_id
                  AND researcher_id = :researcher_id
                  AND status = 'draft'";

            $stmt = $pdo->prepare($sql);

            $params = [];

            foreach ($fields as $field) {
                $value = $study[$field];

                if (
                    in_array($field, [
                        'location',
                        'participant_capacity',
                        'start_date',
                        'end_date',
                        'application_deadline',
                        'reimbursement_details'
                    ], true)
                    && $value === ''
                ) {
                    $value = null;
                }

                $params[':' . $field] = $value;
            }

            $params[':anonymous'] =
                (int)$study['anonymous_participation_allowed'];

            $params[':study_id'] = $studyId;
            $params[':researcher_id'] = $user['user_id'];

            $stmt->execute($params);

            $_SESSION['study_updated'] = $studyId;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: edit-study.php?id=' . $studyId);
            exit;

        } catch (Throwable $exception) {
            error_log('Study update failed: ' . $exception->getMessage());
            $errors[] = 'Unable to update study.';
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
    <title>Edit Research Study</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Edit Research Study #<?= (int)$studyId ?></h2>

<p>Researcher: <?= e($user['first_name']) ?></p>
<p>Status: <?= e($study['status']) ?></p>

<?php if ($updated): ?>
    <p role="status">Study updated successfully!</p>
<?php endif; ?>

<?php if ($errors): ?>
    <div role="alert">
        <h3>Please correct the following:</h3>
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post"
      action="edit-study.php?id=<?= (int)$studyId ?>">

    <input type="hidden"
           name="csrf_token"
           value="<?= e($_SESSION['csrf_token']) ?>">

    <?php
    $textFields = [
        'title' => ['Study Title', 255],
        'topic' => ['Research Topic', 150],
        'location' => ['Location', 150],
        'reimbursement_details' => ['Reimbursement Details', 255]
    ];

    foreach ($textFields as $field => [$label, $max]):
    ?>
        <p>
            <label for="<?= e($field) ?>">
                <?= e($label) ?>:
            </label>

            <input type="text"
                   id="<?= e($field) ?>"
                   name="<?= e($field) ?>"
                   maxlength="<?= $max ?>"
                   value="<?= e($study[$field]) ?>">
        </p>
    <?php endforeach; ?>

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

            <textarea id="<?= e($field) ?>"
                      name="<?= e($field) ?>"
                      rows="4"
                      maxlength="10000"
                      required><?= e($study[$field]) ?></textarea>
        </p>
    <?php endforeach; ?>

    <p>
        <label for="participant_capacity">Participant capacity (optional):</label>
        <input type="number"
               id="participant_capacity"
               name="participant_capacity"
               min="1"
               max="10000"
               step="1"
               value="<?= e($study['participant_capacity'] ?? '') ?>">
    </p>

    <p>
        <label for="participation_format">
            Participation Format:
        </label>

        <select id="participation_format"
                name="participation_format"
                required>

            <?php
            $formats = [
                'online' => 'Online',
                'in_person' => 'In Person',
                'hybrid' => 'Hybrid'
            ];

            foreach ($formats as $value => $label):
            ?>
                <option value="<?= e($value) ?>"
                    <?= $study['participation_format'] === $value
                        ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>

        </select>
    </p>

    <?php
    $dates = [
        'start_date' => 'Start Date',
        'end_date' => 'End Date',
        'application_deadline' => 'Application Deadline'
    ];

    foreach ($dates as $field => $label):
    ?>
        <p>
            <label for="<?= e($field) ?>">
                <?= e($label) ?>:
            </label>

            <input type="date"
                   id="<?= e($field) ?>"
                   name="<?= e($field) ?>"
                   value="<?= e($study[$field]) ?>">
        </p>
    <?php endforeach; ?>

    <p>
        <label for="reimbursement_amount">
            Reimbursement (£):
        </label>

        <input type="number"
               id="reimbursement_amount"
               name="reimbursement_amount"
               min="0"
               max="99999999.99"
               step="0.01"
               value="<?= e($study['reimbursement_amount']) ?>"
               required>
    </p>

    <p>
        <label>
            <input type="checkbox"
                   name="anonymous_participation_allowed"
                   value="1"
                   <?= $study['anonymous_participation_allowed']
                       ? 'checked' : '' ?>>

            Anonymous participation allowed
        </label>
    </p>

    <button type="submit">Save Changes</button>

</form>

<hr>

<p>
    <a href="my-studies.php">Back to My Studies</a>
</p>

</body>
</html>
