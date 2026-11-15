<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/volunteer-matching.php';
require_once __DIR__ . '/postcode-distance.php';

$user = requireRole('academic');
$pdo = getConnection();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['preference_csrf'])) {
    $_SESSION['preference_csrf'] = bin2hex(random_bytes(32));
}
$preferenceNotice = $_SESSION['preference_notice'] ?? null;
unset($_SESSION['preference_notice']);
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
$view = searchInput('view');
if (!in_array($view, ['all', 'saved', 'hidden'], true)) {
    $view = 'all';
}

if (!in_array($format, ['', 'online', 'in_person', 'either'], true)) {
    $format = '';
}
if (!in_array($sort, ['', 'best_match', 'id', 'distance'], true)) {
    $sort = '';
}

$sql = "SELECT u.user_id, vp.location, vp.postcode, vp.research_interests,
               vp.availability, vp.preferred_format, vp.age_range,
               COALESCE(avp.preference_status, '') AS preference_status
        FROM users u
        INNER JOIN volunteer_profiles vp ON vp.user_id = u.user_id
        LEFT JOIN academic_volunteer_preferences avp
            ON avp.volunteer_id = u.user_id AND avp.researcher_id = :researcher_id
        WHERE u.role = 'volunteer'
          AND vp.visible_to_researchers = 1";
$params = ['researcher_id' => $researcherId];
if ($view === 'saved') {
    $sql .= " AND avp.preference_status = 'saved'";
} elseif ($view === 'hidden') {
    $sql .= " AND avp.preference_status = 'hidden'";
} else {
    $sql .= " AND (avp.preference_status IS NULL OR avp.preference_status <> 'hidden')";
}

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
    <title>Find Volunteers | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/find-volunteers.css">
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
            <a href="my-studies.php">My studies</a>
            <a href="review-applications.php">Applications</a>
            <a href="find-volunteers.php" aria-current="page">Find volunteers</a>
            <a href="my-sent-requests.php">Invitations</a>
            <a href="profile.php">My profile</a>
            <form action="logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button class="dashboard-logout__button" type="submit">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell find-page">
    <section class="find-hero" aria-labelledby="find-title">
        <div class="find-hero__copy">
            <span class="eyebrow">Your research workspace</span>
            <h1 id="find-title">Find suitable volunteers</h1>
            <p>Welcome back, <?= e($user['first_name']) ?>! Search volunteer profiles, compare preliminary study matches and invite suitable participants.</p>
        </div>
        <a class="btn btn--primary find-hero__action" href="my-sent-requests.php">Sent invitations</a>
    </section>

    <?php if (is_string($preferenceNotice) && $preferenceNotice !== ''): ?>
        <div class="feedback feedback--success find-feedback" role="status"><?= e($preferenceNotice) ?></div>
    <?php endif; ?>

    <section class="find-filter-panel" aria-labelledby="find-filter-heading">
        <div class="find-section-heading find-filter-heading">
            <div>
                <span class="eyebrow">Discover volunteers</span>
                <h2 id="find-filter-heading">Search and filter</h2>
            </div>
            <p>Only volunteers who have opted into researcher searches can appear.</p>
        </div>

        <form method="get" action="find-volunteers.php" class="find-filter-form">
            <div class="find-field find-field--full">
                <label for="study_id">Compare volunteers against my study</label>
                <select id="study_id" name="study_id">
                    <option value="">No study selected</option>
                    <?php foreach ($studies as $study): ?>
                        <option value="<?= (int)$study['study_id'] ?>" <?= $selectedStudy !== null && (int)$selectedStudy['study_id'] === (int)$study['study_id'] ? 'selected' : '' ?>><?= e($study['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="find-field__hint">Select one of your open studies to compare interests, format and requirements.</span>
            </div>

            <div class="find-field">
                <label for="keyword">Research interests</label>
                <input id="keyword" name="keyword" type="text" value="<?= e($keyword) ?>" placeholder="e.g. Technology, Psychology">
            </div>
            <div class="find-field">
                <label for="location">Location</label>
                <input id="location" name="location" type="text" value="<?= e($location) ?>" placeholder="e.g. Newcastle">
            </div>
            <div class="find-field">
                <label for="availability">Availability</label>
                <input id="availability" name="availability" type="text" value="<?= e($availability) ?>" placeholder="e.g. Weekends">
            </div>
            <div class="find-field">
                <label for="format">Preferred participation format</label>
                <select id="format" name="format">
                    <option value="">All formats</option>
                    <?php foreach (['online' => 'Online', 'in_person' => 'In person', 'either' => 'Either'] as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $format === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="find-field">
                <label for="sort">Sort by</label>
                <select id="sort" name="sort">
                    <option value="best_match" <?= $sort === '' || $sort === 'best_match' ? 'selected' : '' ?>>Best match (with study)</option>
                    <option value="id" <?= $sort === 'id' ? 'selected' : '' ?>>Volunteer ID</option>
                    <option value="distance" <?= $sort === 'distance' ? 'selected' : '' ?>>Nearest distance (with postcode)</option>
                </select>
            </div>
            <div class="find-field">
                <label for="view">Show volunteers</label>
                <select id="view" name="view">
                    <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Available</option>
                    <option value="saved" <?= $view === 'saved' ? 'selected' : '' ?>>Saved</option>
                    <option value="hidden" <?= $view === 'hidden' ? 'selected' : '' ?>>Hidden</option>
                </select>
            </div>

            <?php if ($distanceEnabled && $radius !== null && (int)$radius > 0): ?>
                <label class="find-radius-choice find-field--full">
                    <input type="checkbox" name="within_radius" value="1" <?= $withinRadius ? 'checked' : '' ?>>
                    <span>
                        <strong>Only volunteers within <?= (int)$radius ?> miles</strong>
                        <small>Include only profiles with a known approximate distance from the selected study.</small>
                    </span>
                </label>
            <?php endif; ?>

            <div class="find-filter-actions find-field--full">
                <button class="btn btn--primary" type="submit">Search volunteers</button>
                <a class="btn find-btn--light" href="find-volunteers.php">Clear filters</a>
            </div>
        </form>
    </section>

    <section class="find-results" aria-labelledby="find-results-title">
        <div class="find-section-heading">
            <div>
                <span class="eyebrow">Volunteer discovery</span>
                <h2 id="find-results-title"><?= e($view === 'saved' ? 'Saved volunteers' : ($view === 'hidden' ? 'Hidden volunteers' : 'Available volunteers')) ?></h2>
            </div>
            <p><?= count($volunteers) ?> volunteer<?= count($volunteers) === 1 ? '' : 's' ?> found</p>
        </div>

        <?php if ($selectedStudy !== null): ?>
            <div class="find-context" role="note">
                <div>
                    <strong>Preliminary match against:</strong>
                    <span><?= e($selectedStudy['title']) ?></span>
                </div>
                <p>Matches compare interests, participation format and any specified location, availability or age requirements. Unknown criteria are not scored. Scores do not confirm eligibility.</p>
                <?php if ($distanceEnabled): ?>
                    <p>Distance means approximate straight-line miles between postcode centroids, not journey distance or travel time. Postcode coordinates are retrieved server-side; volunteer postcodes are not shown.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$volunteers): ?>
            <div class="find-empty">
                <h3>No volunteers found</h3>
                <p><?= $view === 'saved' ? 'You do not have any saved volunteers matching these filters.' : ($view === 'hidden' ? 'You do not have any hidden volunteers matching these filters.' : 'Try changing your filters or selecting another research study.') ?></p>
                <a class="btn btn--primary" href="find-volunteers.php">Show available volunteers</a>
            </div>
        <?php else: ?>
            <div class="find-card-grid">
                <?php foreach ($volunteers as $volunteer): ?>
                    <?php
                        $volunteerId = (int)$volunteer['user_id'];
                        $volunteerPreference = (string)$volunteer['preference_status'];
                        $volunteerMatch = $selectedStudy !== null ? $volunteer['match'] : null;
                    ?>
                    <article class="find-card" aria-labelledby="volunteer-<?= $volunteerId ?>">
                        <div class="find-card__top">
                            <span class="find-card__avatar" aria-hidden="true">#<?= $volunteerId ?></span>
                            <div class="find-card__identity">
                                <h3 id="volunteer-<?= $volunteerId ?>">Volunteer #<?= $volunteerId ?></h3>
                                <p>Research volunteer · ID <?= $volunteerId ?></p>
                            </div>
                            <?php if ($volunteerPreference === 'saved'): ?>
                                <span class="find-card__flag">Saved</span>
                            <?php elseif ($volunteerPreference === 'hidden'): ?>
                                <span class="find-card__flag find-card__flag--hidden">Hidden</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($selectedStudy !== null): ?>
                            <div class="find-match">
                                <div class="find-match__heading">
                                    <strong>Preliminary study match</strong>
                                    <?php if ($volunteerMatch['percent'] !== null): ?>
                                        <span><?= (int)$volunteerMatch['percent'] ?>% · <?= (int)$volunteerMatch['matched'] ?>/<?= (int)$volunteerMatch['total'] ?> criteria</span>
                                    <?php else: ?>
                                        <span>Not enough information</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($volunteerMatch['percent'] !== null): ?>
                                    <div class="find-match__track" role="progressbar" aria-label="Preliminary match percentage" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$volunteerMatch['percent'] ?>">
                                        <span style="width: <?= (int)$volunteerMatch['percent'] ?>%"></span>
                                    </div>
                                    <?php if (!empty($volunteerMatch['checks'])): ?>
                                        <ul class="find-match__checks">
                                            <?php foreach ($volunteerMatch['checks'] as $check): ?>
                                                <li>
                                                    <span><?= e($check['label']) ?></span>
                                                    <strong class="<?= $check['matched'] ? 'find-result--yes' : 'find-result--no' ?>"><?= $check['matched'] ? 'Match' : 'Not matched' ?></strong>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                    <?php if (!empty($volunteerMatch['unknown'])): ?>
                                        <p class="find-match__unknown"><strong>Not scored:</strong> <?= e(implode('; ', $volunteerMatch['unknown'])) ?></p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="find-match__unknown">Some study or profile information is unavailable. Matching remains preliminary.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <dl class="find-card__profile">
                            <div>
                                <dt>Location</dt>
                                <dd><?= e($volunteer['location']) !== '' ? e($volunteer['location']) : 'Not provided' ?></dd>
                            </div>
                            <div>
                                <dt>Preferred format</dt>
                                <dd><?= e((string)$volunteer['preferred_format']) !== '' ? e(ucwords(str_replace('_', ' ', (string)$volunteer['preferred_format']))) : 'Not provided' ?></dd>
                            </div>
                            <div class="find-card__profile-wide">
                                <dt>Research interests</dt>
                                <dd><?= e($volunteer['research_interests']) !== '' ? e($volunteer['research_interests']) : 'Not provided' ?></dd>
                            </div>
                            <div class="find-card__profile-wide">
                                <dt>Availability</dt>
                                <dd><?= e($volunteer['availability']) !== '' ? e($volunteer['availability']) : 'Not provided' ?></dd>
                            </div>
                            <?php if ($selectedStudy !== null && $distanceEnabled): ?>
                                <div class="find-card__profile-wide">
                                    <dt>Approximate straight-line distance</dt>
                                    <dd><?= $volunteer['distance_miles'] === null ? 'Unavailable (postcode missing or lookup unavailable)' : e(number_format($volunteer['distance_miles'], 1)) . ' miles' ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>

                        <p class="find-card__privacy">Names and contact details are private until participation is accepted.</p>

                        <div class="find-card__actions">
                            <a class="btn btn--primary find-card__invite" href="send-request.php?volunteer_id=<?= $volunteerId ?>">Invite to research study</a>
                            <form method="post" action="preference-action.php" class="find-card__preferences">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['preference_csrf']) ?>">
                                <input type="hidden" name="kind" value="volunteer">
                                <input type="hidden" name="item_id" value="<?= $volunteerId ?>">
                                <input type="hidden" name="return_page" value="find-volunteers.php">
                                <input type="hidden" name="return_query" value="<?= e(http_build_query($_GET)) ?>">
                                <button class="btn find-btn--light" type="submit" name="action" value="<?= $volunteerPreference === 'saved' ? 'clear' : 'save' ?>"><?= $volunteerPreference === 'saved' ? 'Remove saved' : 'Save volunteer' ?></button>
                                <button class="btn find-btn--light" type="submit" name="action" value="<?= $volunteerPreference === 'hidden' ? 'clear' : 'hide' ?>"><?= $volunteerPreference === 'hidden' ? 'Restore volunteer' : 'Hide volunteer' ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="find-results__limit">Search results include up to 100 opted-in profiles before study matching and distance filtering.</p>
    </section>

    <aside class="find-shortcuts" aria-label="Research navigation">
        <div>
            <h2>Keep your recruitment organised</h2>
            <p>Review your studies, manage invitations or return to your dashboard.</p>
        </div>
        <div class="find-shortcuts__links">
            <a class="btn find-btn--light" href="my-studies.php">My studies</a>
            <a class="btn find-btn--light" href="my-sent-requests.php">Invitations</a>
            <a class="btn find-btn--light" href="academic-dashboard.php">Dashboard</a>
        </div>
    </aside>
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <span>© 2026 Research Recruitment · KV6014 university project</span>
        <div class="site-footer__items" aria-label="Site information">
            <span>Privacy Policy</span><span>Contact</span><span>Terms</span>
        </div>
    </div>
</footer>
</body>
</html>
