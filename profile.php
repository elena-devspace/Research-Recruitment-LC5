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

// Retrieve the current user's profile
if ($role === 'volunteer') {
    $stmt = $pdo->prepare(
        'SELECT location, research_interests,
                availability, preferred_format,
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
        $availability = posted('availability');
        $format = posted('preferred_format');

        $visible = (
            posted('visible_to_researchers') === '1'
        ) ? 1 : 0;

        $profile = [
            'location' => $location,
            'research_interests' => $interests,
            'availability' => $availability,
            'preferred_format' => $format,
            'visible_to_researchers' => $visible
        ];

        if (mb_strlen($location) > 150) {
            $errors[] = 'Location is too long.';
        }

        if (mb_strlen($availability) > 255) {
            $errors[] = 'Availability is too long.';
        }

        if (!in_array(
            $format,
            ['', 'online', 'in_person', 'either'],
            true
        )) {
            $errors[] = 'Invalid participation format.';
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
                     (user_id, location, research_interests,
                      availability, preferred_format,
                      visible_to_researchers)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       location = VALUES(location),
                       research_interests = VALUES(research_interests),
                       availability = VALUES(availability),
                       preferred_format = VALUES(preferred_format),
                       visible_to_researchers =
                           VALUES(visible_to_researchers)'
                );

                $stmt->execute([
                    $userId,
                    $location,
                    $interests,
                    $availability,
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
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>My Profile - Research Recruitment</title>
</head>
<body>

<h1>Research Recruitment</h1>

<h2>My Profile</h2>

<p>
    Welcome, <?= e($user['first_name']) ?>!
</p>

<p>
    Account type: <?= e($role) ?>
</p>

<?php if ($saved): ?>
    <p role="status">Profile saved successfully!</p>
<?php endif; ?>

<?php if (!empty($errors)): ?>
    <div role="alert">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="profile.php">

    <input type="hidden"
           name="csrf_token"
           value="<?= e($_SESSION['csrf_token']) ?>">

    <?php if ($role === 'volunteer'): ?>

        <h3>Volunteer Profile</h3>

        <p>
            <label for="location">Location:</label>
            <input type="text"
                   id="location"
                   name="location"
                   maxlength="150"
                   value="<?= e($profile['location'] ?? '') ?>">
        </p>

        <p>
            <label for="research_interests">
                Research Interests:
            </label>
            <textarea id="research_interests"
                      name="research_interests"
                      maxlength="2000"><?= e(
                $profile['research_interests'] ?? ''
            ) ?></textarea>
        </p>

        <p>
            <label for="availability">Availability:</label>
            <input type="text"
                   id="availability"
                   name="availability"
                   maxlength="255"
                   value="<?= e($profile['availability'] ?? '') ?>">
        </p>

        <p>
            <label for="preferred_format">
                Preferred Participation Format:
            </label>

            <select id="preferred_format"
                    name="preferred_format">

                <?php
                $selectedFormat =
                    $profile['preferred_format'] ?? '';

                $formats = [
                    '' => 'Not specified',
                    'online' => 'Online',
                    'in_person' => 'In Person',
                    'either' => 'Either'
                ];

                foreach ($formats as $value => $label):
                ?>
                    <option value="<?= e($value) ?>"
                        <?= $selectedFormat === $value
                            ? 'selected' : '' ?>>
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </p>

        <p>
            <label>
                <input type="checkbox"
                       name="visible_to_researchers"
                       value="1"
                       <?= !empty(
                           $profile['visible_to_researchers']
                       ) ? 'checked' : '' ?>>

                Allow verified researchers to discover
                my volunteer profile
            </label>
        </p>

        <p>
            Your profile will remain private from
            researcher searches unless you choose
            to make it visible.
        </p>

    <?php else: ?>

        <h3>Academic Researcher Profile</h3>

        <p>
            <label for="institution">Institution:</label>
            <input type="text"
                   id="institution"
                   name="institution"
                   maxlength="200"
                   value="<?= e(
                       $profile['institution'] ?? ''
                   ) ?>">
        </p>

        <p>
            <label for="department">Department:</label>
            <input type="text"
                   id="department"
                   name="department"
                   maxlength="200"
                   value="<?= e(
                       $profile['department'] ?? ''
                   ) ?>">
        </p>

        <p>
            <label for="research_interests">
                Research Interests:
            </label>

            <textarea id="research_interests"
                      name="research_interests"
                      maxlength="2000"><?= e(
                $profile['research_interests'] ?? ''
            ) ?></textarea>
        </p>

    <?php endif; ?>

    <button type="submit">Save Profile</button>

</form>

<hr>

<?php if ($role === 'volunteer'): ?>
    <p>
        <a href="volunteer-dashboard.php">
            Back to Volunteer Dashboard
        </a>
    </p>
<?php else: ?>
    <p>
        <a href="academic-dashboard.php">
            Back to Academic Dashboard
        </a>
    </p>
<?php endif; ?>

</body>
</html>
