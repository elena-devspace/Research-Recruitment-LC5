<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/study-eligibility.php';
require_once __DIR__ . '/postcode-distance.php';

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
$eligibilityRows = eligibilityFormRows(loadStudyEligibilityQuestions($pdo, (int)$studyId));

$fields = [
    'title',
    'topic',
    'aim',
    'description',
    'participation_tasks',
    'eligibility_requirements',
    'participant_capacity',
    'required_availability',
    'minimum_age',
    'maximum_age',
    'participation_format',
    'location',
    'study_postcode',
    'max_distance_miles',
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


    if (!in_array($study['required_availability'], ['', 'weekdays', 'weekends', 'both'], true)) {
        $errors[] = 'Select valid required availability.';
    }
    foreach (['minimum_age', 'maximum_age'] as $ageField) {
        $ageValue = $study[$ageField];
        if ($ageValue !== '' && (!ctype_digit($ageValue) || (int)$ageValue < 18 || (int)$ageValue > 120)) {
            $errors[] = 'Study age limits must be between 18 and 120.';
        }
    }
    if ($study['minimum_age'] !== '' && $study['maximum_age'] !== ''
        && ctype_digit($study['minimum_age']) && ctype_digit($study['maximum_age'])
        && (int)$study['minimum_age'] > (int)$study['maximum_age']) {
        $errors[] = 'Maximum age cannot be lower than minimum age.';
    }


    $postcode = normaliseUkPostcode($study['study_postcode']);
    $study['study_postcode'] = $postcode;
    if ($postcode !== '' && !validUkPostcodeFormat($postcode)) {
        $errors[] = 'Enter a valid UK-format study postcode.';
    }
    $radius = $study['max_distance_miles'];
    if ($radius !== '' && (!ctype_digit($radius)
        || (int)$radius < 1 || (int)$radius > 100)) {
        $errors[] = 'The search radius must be between 1 and 100 miles.';
    }
    if ($radius !== '' && $postcode === '') {
        $errors[] = 'A study postcode is required when setting a search radius.';
    }
    if ($study['participation_format'] === 'online'
        && ($postcode !== '' || $radius !== '')) {
        $errors[] = 'Postcode distance matching is for in-person or hybrid studies.';
    }

    [$eligibilityRows, $eligibilityErrors] = submittedEligibilityRows();
    $errors = array_merge($errors, $eligibilityErrors);

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare(
                "SELECT study_id FROM studies
                 WHERE study_id = ? AND researcher_id = ? AND status = 'draft'
                 FOR UPDATE"
            );
            $lock->execute([$studyId, $user['user_id']]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Draft is no longer editable.');
            }

            $sql = "UPDATE studies SET
                title = :title,
                topic = :topic,
                aim = :aim,
                description = :description,
                participation_tasks = :participation_tasks,
                eligibility_requirements = :eligibility_requirements,
                participant_capacity = :participant_capacity,
                required_availability = :required_availability,
                minimum_age = :minimum_age,
                maximum_age = :maximum_age,
                participation_format = :participation_format,
                location = :location,
                study_postcode = :study_postcode,
                max_distance_miles = :max_distance_miles,
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
                        'study_postcode',
                        'max_distance_miles',
                        'participant_capacity',
                        'required_availability',
                        'minimum_age',
                        'maximum_age',
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
            replaceStudyEligibilityQuestions($pdo, (int)$studyId, $eligibilityRows);
            $pdo->commit();

            $_SESSION['study_updated'] = $studyId;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header('Location: edit-study.php?id=' . $studyId);
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Research Study | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/edit-study.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="academic-dashboard.php">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="academic-dashboard.php">Dashboard</a>
            <a href="my-studies.php" aria-current="page">My studies</a>
            <a href="review-applications.php">Applications</a>
            <a href="find-volunteers.php">Find volunteers</a>
            <a href="my-sent-requests.php">Invitations</a>
            <a href="profile.php">My profile</a>
            <form action="logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button class="dashboard-logout__button" type="submit">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell edit-page">
    <a class="edit-back" href="my-studies.php">&larr; Back to my research studies</a>

    <section class="edit-hero" aria-labelledby="edit-title">
        <div>
            <span class="eyebrow">Your research workspace</span>
            <h1 id="edit-title">Edit research study #<?= (int)$studyId ?></h1>
            <p>Welcome back, <?= e($user['first_name']) ?>! Review and update your saved draft before publishing.</p>
        </div>
        <span class="edit-hero__pill">Draft study</span>
    </section>

    <?php if ($updated): ?>
        <div class="feedback feedback--success edit-feedback" role="status">
            <strong>Study #<?= (int)$studyId ?> updated successfully!</strong>
            <p>Your changes have been saved. This study remains a draft until you publish it.</p>
            <a href="my-studies.php">View my research studies</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="feedback feedback--error edit-feedback" role="alert">
            <h2>Please correct the following</h2>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="edit-heading">
        <div>
            <span class="eyebrow">Edit draft</span>
            <h2>Update your research details</h2>
        </div>
        <p>Required fields are marked with an asterisk (*).</p>
    </div>

    <form method="post" action="edit-study.php?id=<?= (int)$studyId ?>" class="edit-form">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <section class="edit-card" aria-labelledby="edit-basics-title">
            <div class="edit-card__heading">
                <span class="edit-card__number">01</span>
                <div><h3 id="edit-basics-title">Study basics</h3><p>Start with a clear title and research topic.</p></div>
            </div>
            <div class="edit-fields edit-fields--two">
                <div class="edit-field">
                    <label for="title">Study title <span aria-hidden="true">*</span></label>
                    <input type="text" id="title" name="title" maxlength="255" required
                           placeholder="e.g. Student Study Habits" value="<?= e($study['title']) ?>">
                </div>
                <div class="edit-field">
                    <label for="topic">Research topic <span aria-hidden="true">*</span></label>
                    <input type="text" id="topic" name="topic" maxlength="150" required
                           placeholder="e.g. Education, Health or Technology" value="<?= e($study['topic']) ?>">
                </div>
            </div>
        </section>

        <section class="edit-card" aria-labelledby="edit-details-title">
            <div class="edit-card__heading">
                <span class="edit-card__number">02</span>
                <div><h3 id="edit-details-title">Research information</h3><p>Explain the study and what participation involves.</p></div>
            </div>
            <div class="edit-fields edit-fields--two">
                <?php
                $textareas = [
                    'aim' => ['Research aim', 'What do you want to investigate?'],
                    'description' => ['Study description', 'Describe the purpose of your study.'],
                    'participation_tasks' => ['Participant tasks', 'What will participants be asked to do?'],
                    'eligibility_requirements' => ['Eligibility requirements', 'Who can take part in your study?']
                ];
                foreach ($textareas as $field => $fieldInfo):
                ?>
                    <div class="edit-field">
                        <label for="<?= e($field) ?>"><?= e($fieldInfo[0]) ?> <span aria-hidden="true">*</span></label>
                        <textarea id="<?= e($field) ?>" name="<?= e($field) ?>" rows="5"
                                  maxlength="10000" placeholder="<?= e($fieldInfo[1]) ?>" required><?= e($study[$field]) ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="edit-card" aria-labelledby="edit-screening-title">
            <div class="edit-card__heading">
                <span class="edit-card__number">03</span>
                <div><h3 id="edit-screening-title">Eligibility screening</h3><p>Optional Yes/No questions for prospective participants.</p></div>
            </div>
            <fieldset class="edit-fieldset">
                <legend>Screening questions (optional)</legend>
                <p class="edit-help">Add up to five questions and select the answer required to qualify. Leave unused questions blank.</p>
                <div class="edit-screening-list">
                    <?php foreach ($eligibilityRows as $index => $row): ?>
                        <div class="edit-screening-row">
                            <span class="edit-screening-row__number"><?= $index + 1 ?></span>
                            <div class="edit-field">
                                <label for="eligibility_question_<?= $index ?>">Question <?= $index + 1 ?></label>
                                <input type="text" id="eligibility_question_<?= $index ?>"
                                       name="eligibility_questions[<?= $index ?>]" maxlength="255"
                                       placeholder="e.g. Are you aged 18 or over?"
                                       value="<?= e($row['question_text']) ?>">
                            </div>
                            <div class="edit-field">
                                <label for="eligibility_answer_<?= $index ?>">Required answer</label>
                                <select id="eligibility_answer_<?= $index ?>" name="eligibility_answers[<?= $index ?>]">
                                    <option value="yes" <?= $row['required_answer'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                                    <option value="no" <?= $row['required_answer'] === 'no' ? 'selected' : '' ?>>No</option>
                                </select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </section>

        <section class="edit-card" aria-labelledby="edit-participants-title">
            <div class="edit-card__heading">
                <span class="edit-card__number">04</span>
                <div><h3 id="edit-participants-title">Participant requirements</h3><p>Set capacity and preferences used for volunteer matching.</p></div>
            </div>
            <div class="edit-fields edit-fields--two">
                <div class="edit-field">
                    <label for="participant_capacity">Participant capacity <span class="edit-optional">(optional)</span></label>
                    <input type="number" id="participant_capacity" name="participant_capacity" min="1" max="10000" step="1"
                           placeholder="Leave blank for no limit" value="<?= e($study['participant_capacity']) ?>">
                    <p class="edit-help">Up to 10,000 participants. Leave blank if there is no limit.</p>
                </div>
                <div class="edit-field">
                    <label for="required_availability">Required availability <span class="edit-optional">(optional)</span></label>
                    <select id="required_availability" name="required_availability">
                        <?php foreach (['' => 'Not specified', 'weekdays' => 'Weekdays', 'weekends' => 'Weekends', 'both' => 'Both weekdays and weekends'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $study['required_availability'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="edit-field">
                    <label for="minimum_age">Minimum age <span class="edit-optional">(optional)</span></label>
                    <input type="number" id="minimum_age" name="minimum_age" min="18" max="120" step="1"
                           placeholder="18 to 120" value="<?= e($study['minimum_age']) ?>">
                </div>
                <div class="edit-field">
                    <label for="maximum_age">Maximum age <span class="edit-optional">(optional)</span></label>
                    <input type="number" id="maximum_age" name="maximum_age" min="18" max="120" step="1"
                           placeholder="18 to 120" value="<?= e($study['maximum_age']) ?>">
                </div>
            </div>
            <p class="edit-note">Age matching uses volunteer-selected age brackets, not exact ages. These matches are preliminary and do not confirm eligibility.</p>
        </section>

        <section class="edit-card" aria-labelledby="edit-format-title">
            <div class="edit-card__heading">
                <span class="edit-card__number">05</span>
                <div><h3 id="edit-format-title">Format and location</h3><p>Tell volunteers how and where the study takes place.</p></div>
            </div>
            <div class="edit-fields edit-fields--two">
                <div class="edit-field">
                    <label for="participation_format">Participation format <span aria-hidden="true">*</span></label>
                    <select id="participation_format" name="participation_format" required>
                        <option value="">Select format</option>
                        <?php foreach (['online' => 'Online', 'in_person' => 'In Person', 'hybrid' => 'Hybrid'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $study['participation_format'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="edit-field">
                    <label for="location">Location <span class="edit-optional">(required for in-person or hybrid)</span></label>
                    <input type="text" id="location" name="location" maxlength="150"
                           placeholder="e.g. Newcastle upon Tyne" value="<?= e($study['location']) ?>">
                </div>
            </div>
            <fieldset class="edit-fieldset edit-distance">
                <legend>Postcode distance matching (optional)</legend>
                <p class="edit-help">For in-person or hybrid studies only. Distances are approximate straight-line miles, not travel distances.</p>
                <div class="edit-fields edit-fields--two">
                    <div class="edit-field">
                        <label for="study_postcode">Study postcode</label>
                        <input type="text" id="study_postcode" name="study_postcode" maxlength="10"
                               placeholder="e.g. NE1 8ST" autocomplete="postal-code" value="<?= e($study['study_postcode']) ?>">
                    </div>
                    <div class="edit-field">
                        <label for="max_distance_miles">Maximum search radius (miles)</label>
                        <input type="number" id="max_distance_miles" name="max_distance_miles" min="1" max="100" step="1"
                               placeholder="1 to 100" value="<?= e($study['max_distance_miles']) ?>">
                    </div>
                </div>
                <p class="edit-help">A study postcode is required if you set a radius. Do not enter postcode matching details for online studies.</p>
            </fieldset>
        </section>

        <section class="edit-card" aria-labelledby="edit-schedule-title">
            <div class="edit-card__heading">
                <span class="edit-card__number">06</span>
                <div><h3 id="edit-schedule-title">Study dates and reimbursement</h3><p>Choose the study timeline and any participant reimbursement.</p></div>
            </div>
            <div class="edit-fields edit-fields--three">
                <?php foreach (['start_date' => 'Start date', 'end_date' => 'End date', 'application_deadline' => 'Application deadline'] as $field => $label): ?>
                    <div class="edit-field">
                        <label for="<?= e($field) ?>"><?= e($label) ?> <span class="edit-optional">(optional)</span></label>
                        <input type="date" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($study[$field]) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="edit-fields edit-fields--two edit-reimbursement">
                <div class="edit-field">
                    <label for="reimbursement_amount">Reimbursement amount (£) <span aria-hidden="true">*</span></label>
                    <input type="number" id="reimbursement_amount" name="reimbursement_amount" min="0" max="99999999.99" step="0.01" required
                           value="<?= e($study['reimbursement_amount']) ?>">
                </div>
                <div class="edit-field">
                    <label for="reimbursement_details">Reimbursement details <span class="edit-optional">(optional)</span></label>
                    <input type="text" id="reimbursement_details" name="reimbursement_details" maxlength="255"
                           placeholder="e.g. Travel costs covered" value="<?= e($study['reimbursement_details']) ?>">
                </div>
            </div>
        </section>

        <section class="edit-card" aria-labelledby="edit-privacy-title">
            <div class="edit-card__heading">
                <span class="edit-card__number">07</span>
                <div><h3 id="edit-privacy-title">Participation settings</h3><p>Specify whether anonymous participation is allowed.</p></div>
            </div>
            <label class="edit-checkbox" for="anonymous_participation_allowed">
                <input type="checkbox" id="anonymous_participation_allowed" name="anonymous_participation_allowed" value="1"
                       <?= !empty($study['anonymous_participation_allowed']) ? 'checked' : '' ?>>
                <span><strong>Allow anonymous participation</strong><small>Participants may take part anonymously if the study permits it.</small></span>
            </label>
        </section>

        <div class="edit-save-panel">
            <div>
                <h2>Ready to save your changes?</h2>
                <p>Your updates will be saved to this draft. Publishing is a separate action.</p>
            </div>
            <button class="btn btn--primary edit-submit" type="submit">Save changes</button>
        </div>
    </form>

    <section class="edit-shortcuts" aria-label="Researcher shortcuts">
        <div><h2>Continue managing your research</h2><p>Review your draft in My Studies or return to your dashboard.</p></div>
        <div class="edit-shortcuts__links">
            <a class="btn edit-btn--light" href="my-studies.php">My studies</a>
            <a class="btn edit-btn--light" href="academic-dashboard.php">Dashboard</a>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <span>© 2026 Research Recruitment · KV6014 university project</span>
        <div class="site-footer__items" aria-label="Additional information">
            <span>Privacy Policy</span><span>Contact</span><span>Terms</span>
        </div>
    </div>
</footer>
</body>
</html>
