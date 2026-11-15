<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireLogin();
$pdo = getConnection();

$userId = (int)$user['user_id'];
$role = $user['role'];

if (!in_array($role, ['volunteer', 'academic'], true)) {
    http_response_code(403);
    exit('Invalid account role.');
}

function e(?string $value): string {
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function posted(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$saved = !empty($_SESSION['profile_saved']);
unset($_SESSION['profile_saved']);
if ($role === 'volunteer') {
    $stmt = $pdo->prepare(
        'SELECT location, postcode, phone_number,
                preferred_contact_method, research_interests,
                availability, age_range, preferred_format,
                visible_to_researchers
         FROM volunteer_profiles
         WHERE user_id = ?'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT institution, department, research_interests
         FROM academic_profiles
         WHERE user_id = ?'
    );
}

$stmt->execute([$userId]);
$profile = $stmt->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $errors[] = 'Invalid request. Refresh the page.';
    }

    $interests = posted('research_interests');

    if (mb_strlen($interests) > 2000) {
        $errors[] = 'Research interests must be under 2000 characters.';
    }

    if ($role === 'volunteer') {

        $location = posted('location');
        $postcode = strtoupper(posted('postcode'));
        $postcode = preg_replace('/\s+/', ' ', $postcode);
        $phoneNumber = posted('phone_number');
        $contactPreference = posted('preferred_contact_method');
        $ageRange = posted('age_range');
        $format = posted('preferred_format');
        $postedDays = $_POST['availability_days'] ?? [];
        $selectedDays = [];
        $allowedDays = ['weekdays', 'weekends'];

        if (!is_array($postedDays)) {
            $errors[] = 'Invalid availability selection.';
        } else {
            foreach ($postedDays as $day) {
                if (!is_string($day) || !in_array($day, $allowedDays, true)) {
                    $errors[] = 'Invalid availability selection.';
                    break;
                }
                if (!in_array($day, $selectedDays, true)) {
                    $selectedDays[] = $day;
                }
            }
        }

        if (empty($selectedDays)) {
            $errors[] = 'Select at least one availability option.';
        }
        $availability = implode(', ', array_map('ucfirst', $selectedDays));
        // Preserve earlier free-text availability when the selected days have not changed.
        $previousAvailability = (string) ($profile['availability'] ?? '');
        $previousDays = [];
        if (stripos($previousAvailability, 'weekday') !== false) {
            $previousDays[] = 'weekdays';
        }
        if (stripos($previousAvailability, 'weekend') !== false) {
            $previousDays[] = 'weekends';
        }
        $originalSelection = $previousDays;
        $newSelection = $selectedDays;
        sort($originalSelection);
        sort($newSelection);
        if ($previousAvailability !== '' &&
            !empty($newSelection) &&
            $originalSelection === $newSelection) {
            $availability = $previousAvailability;
        }

        $visible = (
            posted('visible_to_researchers') === '1'
        ) ? 1 : 0;

        $profile = [
            'location' => $location,
            'postcode' => $postcode,
            'phone_number' => $phoneNumber,
            'preferred_contact_method' => $contactPreference,
            'research_interests' => $interests,
            'availability' => $availability,
            'age_range' => $ageRange,
            'preferred_format' => $format,
            'visible_to_researchers' => $visible
        ];

        if (mb_strlen($location) > 150) {
            $errors[] = 'Location is too long.';
        }

        if (mb_strlen($availability) > 255) {
            $errors[] = 'Availability is too long.';
        }
        $ageOptions = ['', '18-24', '25-34', '35-44', '45-54', '55-64', '65+'];
        if (!in_array($ageRange, $ageOptions, true)) {
            $errors[] = 'Select a valid age range.';
        }
        if ($postcode !== '' && !preg_match(
            '/^(GIR ?0AA|[A-Z]{1,2}[0-9][0-9A-Z]? ?[0-9][A-Z]{2})$/D',
            $postcode
        )) {
            $errors[] = 'Enter a valid UK-format postcode (e.g. NE1 8ST).';
        }

        if (!in_array(
            $format,
            ['', 'online', 'in_person', 'either'],
            true
        )) {
            $errors[] = 'Invalid participation format.';
        }

        if ($phoneNumber !== '' && (
            mb_strlen($phoneNumber) > 25 ||
            !preg_match('/^\+?[0-9 ()-]{7,25}$/D', $phoneNumber) ||
            strlen(preg_replace('/\D/', '', $phoneNumber)) < 7
        )) {
            $errors[] = 'Enter a valid phone number.';
        }

        if (!in_array($contactPreference, ['email', 'phone'], true)) {
            $errors[] = 'Select a preferred contact method.';
        } elseif ($contactPreference === 'phone' && $phoneNumber === '') {
            $errors[] = 'Enter a phone number to choose phone contact.';
        }

    } else {

        $institution = posted('institution');
        $department = posted('department');

        $profile = [
            'institution' => $institution,
            'department' => $department,
            'research_interests' => $interests
        ];

        if (mb_strlen($institution) > 200) {
            $errors[] = 'Institution is too long.';
        }

        if (mb_strlen($department) > 200) {
            $errors[] = 'Department is too long.';
        }
    }

    if (empty($errors)) {
        try {

            if ($role === 'volunteer') {

                $stmt = $pdo->prepare(
                    'INSERT INTO volunteer_profiles
                     (user_id, location, postcode, phone_number,
                      preferred_contact_method, research_interests,
                      availability, age_range, preferred_format,
                      visible_to_researchers)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       location = VALUES(location),
                       postcode = VALUES(postcode),
                       phone_number = VALUES(phone_number),
                       preferred_contact_method = VALUES(preferred_contact_method),
                       research_interests = VALUES(research_interests),
                       availability = VALUES(availability),
                       age_range = VALUES(age_range),
                       preferred_format = VALUES(preferred_format),
                       visible_to_researchers =
                           VALUES(visible_to_researchers)'
                );

                $stmt->execute([
                    $userId,
                    $location,
                    $postcode !== '' ? $postcode : null,
                    $phoneNumber !== '' ? $phoneNumber : null,
                    $contactPreference,
                    $interests,
                    $availability,
                    $ageRange !== '' ? $ageRange : null,
                    $format === '' ? null : $format,
                    $visible
                ]);

            } else {

                $stmt = $pdo->prepare(
                    'INSERT INTO academic_profiles
                     (user_id, institution, department,
                      research_interests)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       institution = VALUES(institution),
                       department = VALUES(department),
                       research_interests =
                           VALUES(research_interests)'
                );

                $stmt->execute([
                    $userId,
                    $institution,
                    $department,
                    $interests
                ]);
            }

            $_SESSION['profile_saved'] = true;
            $_SESSION['csrf_token'] =
                bin2hex(random_bytes(32));

            header('Location: profile.php');
            exit;

        } catch (Throwable $exception) {
            error_log(
                'Profile error: ' . $exception->getMessage()
            );
            $errors[] = 'Unable to save profile.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/profile.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="<?= $role === 'volunteer' ? 'volunteer-dashboard.php' : 'academic-dashboard.php' ?>">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <?php if ($role === 'volunteer'): ?>
                <a href="volunteer-dashboard.php">Dashboard</a>
                <a href="browse-studies.php">Studies</a>
                <a href="my-applications.php">Applications</a>
                <a href="my-invitations.php">Invitations</a>
            <?php else: ?>
                <a href="academic-dashboard.php">Dashboard</a>
                <a href="my-studies.php">My studies</a>
                <a href="review-applications.php">Applications</a>
                <a href="find-volunteers.php">Find volunteers</a>
                <a href="my-sent-requests.php">Invitations</a>
            <?php endif; ?>
            <a href="profile.php" aria-current="page">My profile</a>
            <form action="logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell profile-page">
    <section class="profile-hero" aria-labelledby="profile-page-title">
        <div>
            <span class="eyebrow">Your account</span>
            <h1 id="profile-page-title">Manage your profile</h1>
            <p>Welcome back, <?= e($user['first_name']) ?>! Keep your information and research preferences up to date.</p>
        </div>
        <div class="profile-hero__aside">
            <span class="profile-hero__role"><?= $role === 'volunteer' ? 'Volunteer account' : 'Academic researcher account' ?></span>
            <a class="btn profile-btn--light" href="<?= $role === 'volunteer' ? 'volunteer-dashboard.php' : 'academic-dashboard.php' ?>">Back to dashboard</a>
        </div>
    </section>

    <?php if ($saved): ?>
        <div class="feedback feedback--success profile-feedback" role="status">Profile saved successfully!</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="feedback feedback--error profile-feedback" role="alert">
            <h2>Unable to save your profile</h2>
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="profile-section-heading">
        <div>
            <span class="eyebrow">Profile settings</span>
            <h2><?= $role === 'volunteer' ? 'Your volunteer profile' : 'Your researcher profile' ?></h2>
        </div>
        <p>Make changes below and save when you're done.</p>
    </div>

    <form method="post" action="profile.php" class="profile-form">
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

        <?php if ($role === 'volunteer'): ?>
            <div class="profile-grid">
                <section class="profile-card" aria-labelledby="about-heading">
                    <span class="profile-card__tag">01</span>
                    <h3 id="about-heading">About you</h3>
                    <p class="profile-card__intro">Help researchers understand which opportunities may be relevant.</p>

                    <div class="profile-fields">
                        <div class="profile-field">
                            <label for="location">Location</label>
                            <input type="text" id="location" name="location" maxlength="150"
                                   autocomplete="address-level2" placeholder="e.g. Newcastle upon Tyne"
                                   value="<?= e($profile['location'] ?? '') ?>">
                        </div>
                        <div class="profile-field">
                            <label for="postcode">Postcode <span class="profile-optional">(optional)</span></label>
                            <input type="text" id="postcode" name="postcode" maxlength="10"
                                   autocomplete="postal-code" placeholder="e.g. NE1 8ST"
                                   value="<?= e($profile['postcode'] ?? '') ?>" aria-describedby="postcode-hint">
                            <p class="profile-hint" id="postcode-hint">Used for location matching. Your postcode is not displayed in volunteer search results.</p>
                        </div>
                        <div class="profile-field">
                            <label for="age_range">Age range <span class="profile-optional">(optional)</span></label>
                            <select id="age_range" name="age_range">
                                <?php
                                $ageOptions = [
                                    '' => 'Prefer not to say',
                                    '18-24' => '18–24',
                                    '25-34' => '25–34',
                                    '35-44' => '35–44',
                                    '45-54' => '45–54',
                                    '55-64' => '55–64',
                                    '65+' => '65+'
                                ];
                                $selectedAgeRange = $profile['age_range'] ?? '';
                                foreach ($ageOptions as $value => $label):
                                ?>
                                    <option value="<?= e($value) ?>" <?= $selectedAgeRange === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="profile-card" aria-labelledby="contact-heading">
                    <span class="profile-card__tag">02</span>
                    <h3 id="contact-heading">Contact preferences</h3>
                    <p class="profile-card__intro">Choose how you would prefer to be contacted after an invitation is accepted.</p>

                    <div class="profile-fields">
                        <div class="profile-field">
                            <label for="contact_email">Account email</label>
                            <input type="email" id="contact_email" value="<?= e($user['email']) ?>" readonly>
                            <p class="profile-hint">This email is linked to your account and cannot be changed here.</p>
                        </div>
                        <div class="profile-field">
                            <label for="phone_number">Phone number <span class="profile-optional">(optional)</span></label>
                            <input type="tel" id="phone_number" name="phone_number" maxlength="25"
                                   autocomplete="tel" placeholder="e.g. 07123 456789"
                                   value="<?= e($profile['phone_number'] ?? '') ?>" aria-describedby="phone-hint">
                            <p class="profile-hint" id="phone-hint">Your phone number is not shown in volunteer search results.</p>
                        </div>
                        <fieldset class="profile-choice-group">
                            <legend>Preferred contact method</legend>
                            <?php $selectedContact = $profile['preferred_contact_method'] ?? 'email'; ?>
                            <div class="profile-choice-row">
                                <label class="profile-choice">
                                    <input type="radio" name="preferred_contact_method" value="email" required
                                        <?= $selectedContact === 'email' ? 'checked' : '' ?>>
                                    Email
                                </label>
                                <label class="profile-choice">
                                    <input type="radio" name="preferred_contact_method" value="phone" required
                                        <?= $selectedContact === 'phone' ? 'checked' : '' ?>>
                                    Phone
                                </label>
                            </div>
                            <p class="profile-hint">A phone number is required if you select Phone.</p>
                        </fieldset>
                    </div>
                </section>

                <section class="profile-card" aria-labelledby="research-heading">
                    <span class="profile-card__tag">03</span>
                    <h3 id="research-heading">Research preferences</h3>
                    <p class="profile-card__intro">Tell researchers what interests you and when you're available.</p>

                    <div class="profile-fields">
                        <div class="profile-field">
                            <label for="research_interests">Research interests</label>
                            <textarea id="research_interests" name="research_interests" maxlength="2000"
                                      rows="5" placeholder="e.g. Psychology, Technology, Education"><?= e($profile['research_interests'] ?? '') ?></textarea>
                        </div>
                        <fieldset class="profile-choice-group">
                            <legend>Availability <span class="profile-required">(select at least one)</span></legend>
                            <?php $selectedAvailability = strtolower((string) ($profile['availability'] ?? '')); ?>
                            <div class="profile-choice-row">
                                <label class="profile-choice">
                                    <input type="checkbox" name="availability_days[]" value="weekdays"
                                        <?= str_contains($selectedAvailability, 'weekday') ? 'checked' : '' ?>>
                                    Weekdays
                                </label>
                                <label class="profile-choice">
                                    <input type="checkbox" name="availability_days[]" value="weekends"
                                        <?= str_contains($selectedAvailability, 'weekend') ? 'checked' : '' ?>>
                                    Weekends
                                </label>
                            </div>
                        </fieldset>
                        <div class="profile-field">
                            <label for="preferred_format">Preferred participation format</label>
                            <select id="preferred_format" name="preferred_format">
                                <?php
                                $selectedFormat = $profile['preferred_format'] ?? '';
                                $formats = [
                                    '' => 'Not specified',
                                    'online' => 'Online',
                                    'in_person' => 'In Person',
                                    'either' => 'Either'
                                ];
                                foreach ($formats as $value => $label):
                                ?>
                                    <option value="<?= e($value) ?>" <?= $selectedFormat === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </section>

                <section class="profile-card" aria-labelledby="visibility-heading">
                    <span class="profile-card__tag">04</span>
                    <h3 id="visibility-heading">Privacy and visibility</h3>
                    <p class="profile-card__intro">Control whether verified academic researchers can find your profile.</p>

                    <div class="profile-privacy-box">
                        <label class="profile-privacy-option">
                            <input type="checkbox" name="visible_to_researchers" value="1"
                                <?= !empty($profile['visible_to_researchers']) ? 'checked' : '' ?>>
                            <span>
                                <strong>Allow researchers to discover my profile</strong>
                                <small>Make my volunteer profile visible in searches by verified researchers.</small>
                            </span>
                        </label>
                    </div>
                    <p class="profile-privacy-note">Your profile stays private from researcher searches unless you choose to make it visible. Personal contact details are not shown in search results.</p>
                </section>
            </div>
        <?php else: ?>
            <div class="profile-grid profile-grid--academic">
                <section class="profile-card" aria-labelledby="academic-details-heading">
                    <span class="profile-card__tag">01</span>
                    <h3 id="academic-details-heading">Academic details</h3>
                    <p class="profile-card__intro">Keep your institution and department information up to date.</p>
                    <div class="profile-fields">
                        <div class="profile-field">
                            <label for="institution">Institution</label>
                            <input type="text" id="institution" name="institution" maxlength="200"
                                   placeholder="e.g. Northumbria University"
                                   value="<?= e($profile['institution'] ?? '') ?>">
                        </div>
                        <div class="profile-field">
                            <label for="department">Department</label>
                            <input type="text" id="department" name="department" maxlength="200"
                                   placeholder="e.g. Computer and Information Sciences"
                                   value="<?= e($profile['department'] ?? '') ?>">
                        </div>
                    </div>
                </section>

                <section class="profile-card" aria-labelledby="academic-interests-heading">
                    <span class="profile-card__tag">02</span>
                    <h3 id="academic-interests-heading">Research interests</h3>
                    <p class="profile-card__intro">Describe the areas of research you work in.</p>
                    <div class="profile-fields">
                        <div class="profile-field">
                            <label for="research_interests">Your research interests</label>
                            <textarea id="research_interests" name="research_interests" maxlength="2000"
                                      rows="7" placeholder="e.g. Digital learning, Student wellbeing"><?= e($profile['research_interests'] ?? '') ?></textarea>
                            <p class="profile-hint">Up to 2,000 characters.</p>
                        </div>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <div class="profile-save-panel">
            <div>
                <h2>Ready to save your changes?</h2>
                <p>Your updated information will be saved to your profile.</p>
            </div>
            <button type="submit" class="btn btn--primary profile-save-button">Save profile</button>
        </div>
    </form>
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
