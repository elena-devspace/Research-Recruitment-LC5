<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/study-eligibility.php';
require_once __DIR__ . '/postcode-distance.php';

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

$data = array_fill_keys($fields, '');
$data['reimbursement_amount'] = '0.00';

$errors = [];
$eligibilityRows = eligibilityFormRows([]);
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

    if ($data['participant_capacity'] !== '' && (
        !ctype_digit($data['participant_capacity']) ||
        (int)$data['participant_capacity'] < 1 ||
        (int)$data['participant_capacity'] > 10000
    )) {
        $errors[] = 'Enter a participant capacity between 1 and 10000.';
    }


    if (!in_array($data['required_availability'], ['', 'weekdays', 'weekends', 'both'], true)) {
        $errors[] = 'Select valid required availability.';
    }
    foreach (['minimum_age', 'maximum_age'] as $ageField) {
        $ageValue = $data[$ageField];
        if ($ageValue !== '' && (!ctype_digit($ageValue) || (int)$ageValue < 18 || (int)$ageValue > 120)) {
            $errors[] = 'Study age limits must be between 18 and 120.';
        }
    }
    if ($data['minimum_age'] !== '' && $data['maximum_age'] !== ''
        && ctype_digit($data['minimum_age']) && ctype_digit($data['maximum_age'])
        && (int)$data['minimum_age'] > (int)$data['maximum_age']) {
        $errors[] = 'Maximum age cannot be lower than minimum age.';
    }


    $postcode = normaliseUkPostcode($data['study_postcode']);
    $data['study_postcode'] = $postcode;
    if ($postcode !== '' && !validUkPostcodeFormat($postcode)) {
        $errors[] = 'Enter a valid UK-format study postcode.';
    }
    $radius = $data['max_distance_miles'];
    if ($radius !== '' && (!ctype_digit($radius)
        || (int)$radius < 1 || (int)$radius > 100)) {
        $errors[] = 'The search radius must be between 1 and 100 miles.';
    }
    if ($radius !== '' && $postcode === '') {
        $errors[] = 'A study postcode is required when setting a search radius.';
    }
    if ($data['participation_format'] === 'online'
        && ($postcode !== '' || $radius !== '')) {
        $errors[] = 'Postcode distance matching is for in-person or hybrid studies.';
    }

    [$eligibilityRows, $eligibilityErrors] = submittedEligibilityRows();
    $errors = array_merge($errors, $eligibilityErrors);

    $anonymous = (
        ($_POST['anonymous_participation_allowed'] ?? '')
        === '1'
    ) ? 1 : 0;

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "INSERT INTO studies (
                    researcher_id,
                    title,
                    topic,
                    aim,
                    description,
                    participation_tasks,
                    eligibility_requirements,
                    participant_capacity,
                    required_availability,
                    minimum_age,
                    maximum_age,
                    participation_format,
                    location,
                    study_postcode,
                    max_distance_miles,
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
                    :participant_capacity,
                    :required_availability,
                    :minimum_age,
                    :maximum_age,
                    :format,
                    :location,
                    :study_postcode,
                    :max_distance_miles,
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
                'participant_capacity' => $data['participant_capacity'] === ''
                    ? null : (int)$data['participant_capacity'],
                'required_availability' => $data['required_availability'] ?: null,
                'minimum_age' => $data['minimum_age'] === '' ? null : (int)$data['minimum_age'],
                'maximum_age' => $data['maximum_age'] === '' ? null : (int)$data['maximum_age'],
                'format' => $data['participation_format'],
                'location' => $data['location'] ?: null,
                'study_postcode' => $data['study_postcode'] ?: null,
                'max_distance_miles' => $data['max_distance_miles'] === ''
                    ? null : (int)$data['max_distance_miles'],
                'start_date' => $data['start_date'] ?: null,
                'end_date' => $data['end_date'] ?: null,
                'deadline' => $data['application_deadline'] ?: null,
                'amount' => $data['reimbursement_amount'],
                'details' => $data['reimbursement_details'] ?: null,
                'anonymous' => $anonymous
            ]);

            $newStudyId = (int)$pdo->lastInsertId();
            replaceStudyEligibilityQuestions($pdo, $newStudyId, $eligibilityRows);
            $pdo->commit();

            $_SESSION['study_created'] = $newStudyId;

            $_SESSION['csrf_token'] =
                bin2hex(random_bytes(32));

            header('Location: create-study.php');
            exit;

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Research Study | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/create-study.css">
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

<main class="page-shell create-page">
    <a class="create-back" href="my-studies.php">&larr; Back to my research studies</a>

    <section class="create-hero" aria-labelledby="create-title">
        <div>
            <span class="eyebrow">Your research workspace</span>
            <h1 id="create-title">Create a research study</h1>
            <p>Welcome back, <?= e($user['first_name']) ?>! Set up your study, choose who can participate and save everything as a draft.</p>
        </div>
        <span class="create-hero__pill">Saved as draft</span>
    </section>

    <?php if ($createdId !== null): ?>
        <div class="feedback feedback--success create-feedback" role="status">
            <strong>Study #<?= (int)$createdId ?> created successfully as a draft!</strong>
            <p>You can review and publish it from My Research Studies.</p>
            <a href="my-studies.php">View my research studies</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="feedback feedback--error create-feedback" role="alert">
            <h2>Please correct the following</h2>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="create-heading">
        <div>
            <span class="eyebrow">Study setup</span>
            <h2>Tell us about your research</h2>
        </div>
        <p>Required fields are marked with an asterisk (*).</p>
    </div>

    <form method="post" action="create-study.php" class="create-form">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <section class="create-card" aria-labelledby="create-basics-title">
            <div class="create-card__heading">
                <span class="create-card__number">01</span>
                <div><h3 id="create-basics-title">Study basics</h3><p>Start with a clear title and research topic.</p></div>
            </div>
            <div class="create-fields create-fields--two">
                <div class="create-field">
                    <label for="title">Study title <span aria-hidden="true">*</span></label>
                    <input type="text" id="title" name="title" maxlength="255" required
                           placeholder="e.g. Student Study Habits" value="<?= e($data['title']) ?>">
                </div>
                <div class="create-field">
                    <label for="topic">Research topic <span aria-hidden="true">*</span></label>
                    <input type="text" id="topic" name="topic" maxlength="150" required
                           placeholder="e.g. Education, Health or Technology" value="<?= e($data['topic']) ?>">
                </div>
            </div>
        </section>

        <section class="create-card" aria-labelledby="create-details-title">
            <div class="create-card__heading">
                <span class="create-card__number">02</span>
                <div><h3 id="create-details-title">Research information</h3><p>Explain the study and what participation involves.</p></div>
            </div>
            <div class="create-fields create-fields--two">
                <?php
                $textareas = [
                    'aim' => ['Research aim', 'What do you want to investigate?'],
                    'description' => ['Study description', 'Describe the purpose of your study.'],
                    'participation_tasks' => ['Participant tasks', 'What will participants be asked to do?'],
                    'eligibility_requirements' => ['Eligibility requirements', 'Who can take part in your study?']
                ];
                foreach ($textareas as $field => $fieldInfo):
                ?>
                    <div class="create-field">
                        <label for="<?= e($field) ?>"><?= e($fieldInfo[0]) ?> <span aria-hidden="true">*</span></label>
                        <textarea id="<?= e($field) ?>" name="<?= e($field) ?>" rows="5"
                                  maxlength="10000" placeholder="<?= e($fieldInfo[1]) ?>" required><?= e($data[$field]) ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="create-card" aria-labelledby="create-screening-title">
            <div class="create-card__heading">
                <span class="create-card__number">03</span>
                <div><h3 id="create-screening-title">Eligibility screening</h3><p>Optional Yes/No questions for prospective participants.</p></div>
            </div>
            <fieldset class="create-fieldset">
                <legend>Screening questions (optional)</legend>
                <p class="create-help">Add up to five questions and select the answer required to qualify. Leave unused questions blank.</p>
                <div class="create-screening-list">
                    <?php foreach ($eligibilityRows as $index => $row): ?>
                        <div class="create-screening-row">
                            <span class="create-screening-row__number"><?= $index + 1 ?></span>
                            <div class="create-field">
                                <label for="eligibility_question_<?= $index ?>">Question <?= $index + 1 ?></label>
                                <input type="text" id="eligibility_question_<?= $index ?>"
                                       name="eligibility_questions[<?= $index ?>]" maxlength="255"
                                       placeholder="e.g. Are you aged 18 or over?"
                                       value="<?= e($row['question_text']) ?>">
                            </div>
                            <div class="create-field">
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

        <section class="create-card" aria-labelledby="create-participants-title">
            <div class="create-card__heading">
                <span class="create-card__number">04</span>
                <div><h3 id="create-participants-title">Participant requirements</h3><p>Set capacity and preferences used for volunteer matching.</p></div>
            </div>
            <div class="create-fields create-fields--two">
                <div class="create-field">
                    <label for="participant_capacity">Participant capacity <span class="create-optional">(optional)</span></label>
                    <input type="number" id="participant_capacity" name="participant_capacity" min="1" max="10000" step="1"
                           placeholder="Leave blank for no limit" value="<?= e($data['participant_capacity']) ?>">
                    <p class="create-help">Up to 10,000 participants. Leave blank if there is no limit.</p>
                </div>
                <div class="create-field">
                    <label for="required_availability">Required availability <span class="create-optional">(optional)</span></label>
                    <select id="required_availability" name="required_availability">
                        <?php foreach (['' => 'Not specified', 'weekdays' => 'Weekdays', 'weekends' => 'Weekends', 'both' => 'Both weekdays and weekends'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $data['required_availability'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="create-field">
                    <label for="minimum_age">Minimum age <span class="create-optional">(optional)</span></label>
                    <input type="number" id="minimum_age" name="minimum_age" min="18" max="120" step="1"
                           placeholder="18 to 120" value="<?= e($data['minimum_age']) ?>">
                </div>
                <div class="create-field">
                    <label for="maximum_age">Maximum age <span class="create-optional">(optional)</span></label>
                    <input type="number" id="maximum_age" name="maximum_age" min="18" max="120" step="1"
                           placeholder="18 to 120" value="<?= e($data['maximum_age']) ?>">
                </div>
            </div>
            <p class="create-note">Age matching uses volunteer-selected age brackets, not exact ages. These matches are preliminary and do not confirm eligibility.</p>
        </section>

        <section class="create-card" aria-labelledby="create-format-title">
            <div class="create-card__heading">
                <span class="create-card__number">05</span>
                <div><h3 id="create-format-title">Format and location</h3><p>Tell volunteers how and where the study takes place.</p></div>
            </div>
            <div class="create-fields create-fields--two">
                <div class="create-field">
                    <label for="participation_format">Participation format <span aria-hidden="true">*</span></label>
                    <select id="participation_format" name="participation_format" required>
                        <option value="">Select format</option>
                        <?php foreach (['online' => 'Online', 'in_person' => 'In Person', 'hybrid' => 'Hybrid'] as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $data['participation_format'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="create-field">
                    <label for="location">Location <span class="create-optional">(required for in-person or hybrid)</span></label>
                    <input type="text" id="location" name="location" maxlength="150"
                           placeholder="e.g. Newcastle upon Tyne" value="<?= e($data['location']) ?>">
                </div>
            </div>
            <fieldset class="create-fieldset create-distance">
                <legend>Postcode distance matching (optional)</legend>
                <p class="create-help">For in-person or hybrid studies only. Distances are approximate straight-line miles, not travel distances.</p>
                <div class="create-fields create-fields--two">
                    <div class="create-field">
                        <label for="study_postcode">Study postcode</label>
                        <input type="text" id="study_postcode" name="study_postcode" maxlength="10"
                               placeholder="e.g. NE1 8ST" autocomplete="postal-code" value="<?= e($data['study_postcode']) ?>">
                    </div>
                    <div class="create-field">
                        <label for="max_distance_miles">Maximum search radius (miles)</label>
                        <input type="number" id="max_distance_miles" name="max_distance_miles" min="1" max="100" step="1"
                               placeholder="1 to 100" value="<?= e($data['max_distance_miles']) ?>">
                    </div>
                </div>
                <p class="create-help">A study postcode is required if you set a radius. Do not enter postcode matching details for online studies.</p>
            </fieldset>
        </section>

        <section class="create-card" aria-labelledby="create-schedule-title">
            <div class="create-card__heading">
                <span class="create-card__number">06</span>
                <div><h3 id="create-schedule-title">Study dates and reimbursement</h3><p>Choose the study timeline and any participant reimbursement.</p></div>
            </div>
            <div class="create-fields create-fields--three">
                <?php foreach (['start_date' => 'Start date', 'end_date' => 'End date', 'application_deadline' => 'Application deadline'] as $field => $label): ?>
                    <div class="create-field">
                        <label for="<?= e($field) ?>"><?= e($label) ?> <span class="create-optional">(optional)</span></label>
                        <input type="date" id="<?= e($field) ?>" name="<?= e($field) ?>" value="<?= e($data[$field]) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="create-fields create-fields--two create-reimbursement">
                <div class="create-field">
                    <label for="reimbursement_amount">Reimbursement amount (£) <span aria-hidden="true">*</span></label>
                    <input type="number" id="reimbursement_amount" name="reimbursement_amount" min="0" step="0.01" required
                           value="<?= e($data['reimbursement_amount']) ?>">
                </div>
                <div class="create-field">
                    <label for="reimbursement_details">Reimbursement details <span class="create-optional">(optional)</span></label>
                    <input type="text" id="reimbursement_details" name="reimbursement_details" maxlength="255"
                           placeholder="e.g. Travel costs covered" value="<?= e($data['reimbursement_details']) ?>">
                </div>
            </div>
        </section>

        <section class="create-card" aria-labelledby="create-privacy-title">
            <div class="create-card__heading">
                <span class="create-card__number">07</span>
                <div><h3 id="create-privacy-title">Participation settings</h3><p>Specify whether anonymous participation is allowed.</p></div>
            </div>
            <label class="create-checkbox" for="anonymous_participation_allowed">
                <input type="checkbox" id="anonymous_participation_allowed" name="anonymous_participation_allowed" value="1"
                       <?= ($_POST['anonymous_participation_allowed'] ?? '') === '1' ? 'checked' : '' ?>>
                <span><strong>Allow anonymous participation</strong><small>Participants may take part anonymously if the study permits it.</small></span>
            </label>
        </section>

        <div class="create-save-panel">
            <div>
                <h2>Ready to save your study?</h2>
                <p>Your new study will be saved as a draft. You can review it before publishing.</p>
            </div>
            <button class="btn btn--primary create-submit" type="submit">Save study as draft</button>
        </div>
    </form>

    <section class="create-shortcuts" aria-label="Researcher shortcuts">
        <div><h2>Continue managing your research</h2><p>Review your saved studies or return to your dashboard.</p></div>
        <div class="create-shortcuts__links">
            <a class="btn create-btn--light" href="my-studies.php">My studies</a>
            <a class="btn create-btn--light" href="academic-dashboard.php">Dashboard</a>
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
