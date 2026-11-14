<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/volunteer-matching.php';
require_once __DIR__ . '/postcode-distance.php';

$user = requireRole('academic');
$pdo = getConnection();
$researcherId = (int)$user['user_id'];

$verified = $pdo->prepare(
    'SELECT verification_status FROM academic_profiles WHERE user_id = ? LIMIT 1'
);
$verified->execute([$researcherId]);

if ($verified->fetchColumn() !== 'verified') {
    http_response_code(403);
    exit('Access denied. Researcher verification required.');
}

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function searchInput(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

$studyStmt = $pdo->prepare(
    "SELECT study_id, title, topic, participation_format, location,
            required_availability, minimum_age, maximum_age,
            study_postcode, max_distance_miles
     FROM studies
     WHERE researcher_id = ? AND status = 'open'
     ORDER BY study_id DESC"
);
$studyStmt->execute([$researcherId]);
$studies = $studyStmt->fetchAll(PDO::FETCH_ASSOC);
$studiesById = [];
foreach ($studies as $study) {
    $studiesById[(int)$study['study_id']] = $study;
}

$studyId = filter_input(
    INPUT_GET,
    'study_id',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$selectedStudy = is_int($studyId) ? ($studiesById[$studyId] ?? null) : null;

$keyword = searchInput('keyword');
$location = searchInput('location');
$availability = searchInput('availability');
$format = searchInput('format');
$sort = searchInput('sort');

if (!in_array($format, ['', 'online', 'in_person', 'either'], true)) {
    $format = '';
}
if (!in_array($sort, ['', 'best_match', 'id', 'distance'], true)) {
    $sort = '';
}

$sql = "SELECT u.user_id, vp.location, vp.postcode, vp.research_interests,
               vp.availability, vp.preferred_format, vp.age_range
        FROM users u
        INNER JOIN volunteer_profiles vp ON vp.user_id = u.user_id
        WHERE u.role = 'volunteer'
          AND vp.visible_to_researchers = 1";
$params = [];

if ($keyword !== '') {
    $sql .= ' AND vp.research_interests LIKE :keyword';
    $params['keyword'] = '%' . $keyword . '%';
}
if ($location !== '') {
    $sql .= ' AND vp.location LIKE :location';
    $params['location'] = '%' . $location . '%';
}
if ($availability !== '') {
    $sql .= ' AND vp.availability LIKE :availability';
    $params['availability'] = '%' . $availability . '%';
}
if ($format !== '') {
    if ($format === 'either') {
        $sql .= ' AND vp.preferred_format = :format';
    } else {
        $sql .= " AND vp.preferred_format IN (:format, 'either')";
    }
    $params['format'] = $format;
}

$sql .= ' ORDER BY u.user_id ASC LIMIT 100';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$volunteers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$distanceEnabled = $selectedStudy !== null
    && in_array($selectedStudy['participation_format'], ['in_person', 'hybrid'], true)
    && trim((string)($selectedStudy['study_postcode'] ?? '')) !== '';
$radius = $selectedStudy === null ? null : $selectedStudy['max_distance_miles'];
$withinRadius = searchInput('within_radius') === '1'
    && $distanceEnabled && $radius !== null && (int)$radius > 0;

if ($selectedStudy !== null) {
    $coordinates = [];
    $studyCoordinates = null;

    if ($distanceEnabled) {
        $postcodes = [(string)$selectedStudy['study_postcode']];
        foreach ($volunteers as $volunteer) {
            if (!empty($volunteer['postcode'])) {
                $postcodes[] = (string)$volunteer['postcode'];
            }
        }
        $coordinates = lookupPostcodeCoordinates($postcodes);
        $studyKey = normaliseUkPostcode((string)$selectedStudy['study_postcode']);
        $studyCoordinates = $coordinates[$studyKey] ?? null;
    }

    foreach ($volunteers as &$volunteer) {
        $volunteer['distance_miles'] = null;
        $postcodeKey = normaliseUkPostcode((string)($volunteer['postcode'] ?? ''));
        if ($studyCoordinates !== null && isset($coordinates[$postcodeKey])) {
            $volunteer['distance_miles'] = straightLineMiles(
                $studyCoordinates,
                $coordinates[$postcodeKey]
            );
        }
        $volunteer['match'] = calculateVolunteerMatch(
            $selectedStudy,
            $volunteer,
            $volunteer['distance_miles']
        );
    }
    unset($volunteer);

    if ($withinRadius) {
        $volunteers = array_values(array_filter(
            $volunteers,
            static fn(array $v): bool => $v['distance_miles'] !== null
                && $v['distance_miles'] <= (int)$radius
        ));
    }

    if ($sort === 'distance' && $distanceEnabled) {
        usort($volunteers, static function (array $a, array $b): int {
            return ($a['distance_miles'] ?? INF) <=> ($b['distance_miles'] ?? INF)
                ?: (int)$a['user_id'] <=> (int)$b['user_id'];
        });
    } elseif ($sort !== 'id') {
        usort($volunteers, static function (array $a, array $b): int {
            $am = $a['match'];
            $bm = $b['match'];
            return ($bm['percent'] ?? -1) <=> ($am['percent'] ?? -1)
                ?: $bm['total'] <=> $am['total']
                ?: $bm['matched'] <=> $am['matched']
                ?: (int)$a['user_id'] <=> (int)$b['user_id'];
        });
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Find Volunteers - Research Recruitment</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Find Volunteers</h2>
<p>Welcome, <?= e($user['first_name']) ?>!</p>
<p>Only volunteers who opted into researcher searches appear here. Names and contact details remain private until acceptance.</p>

<form method="get" action="find-volunteers.php">
    <p>
        <label for="study_id">Compare volunteers against my study:</label>
        <select id="study_id" name="study_id">
            <option value="">No study selected</option>
            <?php foreach ($studies as $study): ?>
                <option value="<?= (int)$study['study_id'] ?>"
                    <?= $selectedStudy !== null && (int)$selectedStudy['study_id'] === (int)$study['study_id'] ? 'selected' : '' ?>>
                    <?= e($study['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="keyword">Research interests:</label>
        <input id="keyword" name="keyword" type="text" value="<?= e($keyword) ?>">
    </p>
    <p>
        <label for="location">Location:</label>
        <input id="location" name="location" type="text" value="<?= e($location) ?>">
    </p>
    <p>
        <label for="availability">Availability:</label>
        <input id="availability" name="availability" type="text" value="<?= e($availability) ?>">
    </p>
    <p>
        <label for="format">Preferred format:</label>
        <select id="format" name="format">
            <option value="">All formats</option>
            <?php foreach (['online' => 'Online', 'in_person' => 'In Person', 'either' => 'Either'] as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $format === $key ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="sort">Sort:</label>
        <select id="sort" name="sort">
            <option value="best_match" <?= $sort !== 'id' ? 'selected' : '' ?>>Best match (when study selected)</option>
            <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>Volunteer ID</option>
            <option value="distance" <?= $sort === 'distance' ? 'selected' : '' ?>>Nearest distance (study postcode required)</option>
        </select>
    </p>
    <?php if ($distanceEnabled && $radius !== null): ?>
        <p>
            <label>
                <input type="checkbox" name="within_radius" value="1"
                    <?= $withinRadius ? 'checked' : '' ?>>
                Show only volunteers within <?= (int)$radius ?> miles with a known distance
            </label>
        </p>
    <?php endif; ?>
    <button type="submit">Search volunteers</button>
    <a href="find-volunteers.php">Clear filters</a>
</form>

<hr>
<h3>Available Volunteers (<?= count($volunteers) ?>)</h3>

<?php if ($selectedStudy !== null): ?>
    <p>Preliminary match against: <strong><?= e($selectedStudy['title']) ?></strong></p>
    <p>Scores compare topic, format, and study-specific location, availability and age requirements. Unknown criteria are not scored. Scores do not confirm eligibility.</p>
    <?php if ($distanceEnabled): ?>
        <p>Distances are approximate straight-line miles between postcode centroids, not travel times. Postcode coordinates are retrieved securely server-side from Postcodes.io. Volunteer postcodes are not displayed.</p>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$volunteers): ?>
    <p>No volunteers match your search filters.</p>
<?php endif; ?>

<?php foreach ($volunteers as $volunteer): ?>
    <h4>Volunteer #<?= (int)$volunteer['user_id'] ?></h4>

    <?php if ($selectedStudy !== null): ?>
        <?php $match = $volunteer['match']; ?>
        <?php if ($distanceEnabled): ?>
            <p>Approximate distance:
                <?= $volunteer['distance_miles'] === null
                    ? 'Unavailable (postcode missing, not found or lookup unavailable)'
                    : e(number_format($volunteer['distance_miles'], 1)) . ' miles (straight line)' ?>
            </p>
        <?php endif; ?>
        <?php if ($match['percent'] !== null): ?>
            <p><strong>Preliminary match: <?= $match['percent'] ?>% (<?= $match['matched'] ?>/<?= $match['total'] ?> criteria)</strong></p>
            <ul>
                <?php foreach ($match['checks'] as $check): ?>
                    <li><?= e($check['label']) ?>: <?= $check['matched'] ? 'Match' : 'Not matched' ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($match['unknown'])): ?>
                <p>Not scored: <?= e(implode('; ', $match['unknown'])) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p>Not enough profile information to calculate a match.</p>
        <?php endif; ?>
    <?php endif; ?>

    <p>Volunteer ID: <?= (int)$volunteer['user_id'] ?></p>
    <p>Location: <?= e($volunteer['location']) ?></p>
    <p>Research interests: <?= e($volunteer['research_interests']) ?></p>
    <p>Availability: <?= e($volunteer['availability']) ?></p>
    <p>Preferred format: <?= e(str_replace('_', ' ', (string)$volunteer['preferred_format'])) ?></p>
    <p><a href="send-request.php?volunteer_id=<?= (int)$volunteer['user_id'] ?>">Invite to Research Study</a></p>
    <hr>
<?php endforeach; ?>

<p><a href="academic-dashboard.php">Back to Academic Dashboard</a></p>
</body>
</html>
