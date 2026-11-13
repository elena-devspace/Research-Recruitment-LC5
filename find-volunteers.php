<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Only academic researchers can access this page.
requireRole('academic');

$user = currentUser();
$pdo = getConnection();

$researcherId = (int) $user['user_id'];

// Check researcher verification.
$verifyStmt = $pdo->prepare("
    SELECT verification_status
    FROM academic_profiles
    WHERE user_id = :user_id
    LIMIT 1
");

$verifyStmt->execute([
    'user_id' => $researcherId
]);

$verificationStatus = $verifyStmt->fetchColumn();

if ($verificationStatus !== 'verified') {
    http_response_code(403);
    exit(
        'Access denied. Your researcher account must ' .
        'be verified before searching for volunteers.'
    );
}

// Escape displayed database values.
function escapeVolunteerValue($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Read search filters.
function volunteerSearchInput(string $key): string
{
    $value = $_GET[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

$keyword = volunteerSearchInput('keyword');
$location = volunteerSearchInput('location');
$availability = volunteerSearchInput('availability');
$format = volunteerSearchInput('format');

$allowedFormats = ['online', 'in_person', 'either'];

if (!in_array($format, $allowedFormats, true)) {
    $format = '';
}

// Only explicitly visible volunteer profiles
// can appear in search results.
$sql = "
    SELECT
        u.user_id,
        u.first_name,
        u.last_name,
        vp.location,
        vp.research_interests,
        vp.availability,
        vp.preferred_format
    FROM users u
    INNER JOIN volunteer_profiles vp
        ON u.user_id = vp.user_id
    WHERE u.role = 'volunteer'
      AND vp.visible_to_researchers = 1
";

$params = [];

// Filter by research interests.
if ($keyword !== '') {
    $sql .= "
        AND vp.research_interests LIKE :keyword
    ";

    $params['keyword'] = '%' . $keyword . '%';
}

// Filter by location.
if ($location !== '') {
    $sql .= "
        AND vp.location LIKE :location
    ";

    $params['location'] = '%' . $location . '%';
}

// Filter by availability.
if ($availability !== '') {
    $sql .= "
        AND vp.availability LIKE :availability
    ";

    $params['availability'] = '%' . $availability . '%';
}

// Filter by preferred participation format.
if ($format !== '') {
    if ($format === 'either') {
        $sql .= "
            AND vp.preferred_format = :format
        ";
    } else {
        $sql .= "
            AND vp.preferred_format IN (:format, 'either')
        ";
    }

    $params['format'] = $format;
}

$sql .= "
    ORDER BY u.first_name ASC, u.last_name ASC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$volunteers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Find Volunteers - Research Recruitment</title>
</head>
<body>

<h1>Research Recruitment</h1>

<h2>Find Volunteers</h2>

<p>
    Welcome,
    <?= escapeVolunteerValue($user['first_name']) ?>!
</p>

<p>
    Search for volunteers who have agreed to make
    their profiles visible to researchers.
</p>

<h3>Search Volunteers</h3>

<form method="GET" action="find-volunteers.php">

    <p>
        <label for="keyword">Research Interests:</label>
        <input
            type="text"
            id="keyword"
            name="keyword"
            value="<?= escapeVolunteerValue($keyword) ?>"
        >
    </p>

    <p>
        <label for="location">Location:</label>
        <input
            type="text"
            id="location"
            name="location"
            value="<?= escapeVolunteerValue($location) ?>"
        >
    </p>

    <p>
        <label for="availability">Availability:</label>
        <input
            type="text"
            id="availability"
            name="availability"
            value="<?= escapeVolunteerValue($availability) ?>"
        >
    </p>

    <p>
        <label for="format">Preferred Format:</label>

        <select id="format" name="format">
            <option value="">All Formats</option>

            <option value="online"
                <?= $format === 'online' ? 'selected' : '' ?>>
                Online
            </option>

            <option value="in_person"
                <?= $format === 'in_person' ? 'selected' : '' ?>>
                In Person
            </option>

            <option value="either"
                <?= $format === 'either' ? 'selected' : '' ?>>
                Either
            </option>
        </select>
    </p>

    <button type="submit">Search Volunteers</button>

    <a href="find-volunteers.php">Clear Filters</a>

</form>

<hr>

<h3>Available Volunteers</h3>

<p>
    Volunteers found: <?= count($volunteers) ?>
</p>

<?php if (empty($volunteers)): ?>

    <p>No volunteers match your search criteria.</p>

<?php else: ?>

    <?php foreach ($volunteers as $volunteer): ?>

        <h4>
            <?= escapeVolunteerValue($volunteer['first_name']) ?>
            <?= escapeVolunteerValue($volunteer['last_name']) ?>
        </h4>

        <p>
            Volunteer ID:
            <?= (int) $volunteer['user_id'] ?>
        </p>

        <p>
            Location:
            <?= escapeVolunteerValue($volunteer['location']) ?>
        </p>

        <p>
            Research Interests:
            <?= escapeVolunteerValue($volunteer['research_interests']) ?>
        </p>

        <p>
            Availability:
            <?= escapeVolunteerValue($volunteer['availability']) ?>
        </p>

        <p>
            Preferred Format:
            <?= escapeVolunteerValue(
                str_replace(
                    '_',
                    ' ',
                    $volunteer['preferred_format'] ?? ''
                )
            ) ?>
        </p>

        <!-- Invite volunteer to a research study -->
        <p>
            <a href="send-request.php?volunteer_id=<?= (int) $volunteer['user_id'] ?>">
                Invite to Research Study
            </a>
        </p>

        <hr>

    <?php endforeach; ?>

<?php endif; ?>

<p>
    <a href="academic-dashboard.php">
        Back to Academic Dashboard
    </a>
</p>

</body>
</html>
