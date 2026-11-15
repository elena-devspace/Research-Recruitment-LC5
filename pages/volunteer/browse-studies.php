<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';

$user = requireRole('volunteer');
$pdo = getConnection();

if (empty($_SESSION['preference_csrf'])) {
    $_SESSION['preference_csrf'] = bin2hex(random_bytes(32));
}
$preferenceNotice = $_SESSION['preference_notice'] ?? null;
unset($_SESSION['preference_notice']);

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function queryValue(string $name): string {
    $value = $_GET[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

$keyword = queryValue('keyword');
$location = queryValue('location');
$format = queryValue('format');
$sort = queryValue('sort');
$view = queryValue('view');
if (!in_array($view, ['all', 'saved', 'hidden'], true)) {
    $view = 'all';
}

if ($sort === '') {
    $sort = 'newest';
}

$sortOptions = [
    'newest' => 'Newest First',
    'reward_high' => 'Highest Reimbursement',
    'reward_low' => 'Lowest Reimbursement'
];

$formatOptions = [
    '' => 'All Formats',
    'online' => 'Online',
    'in_person' => 'In Person',
    'hybrid' => 'Hybrid'
];

$errors = [];
$studies = [];

if (mb_strlen($keyword) > 100) {
    $errors[] = 'Search keyword is too long.';
}

if (mb_strlen($location) > 150) {
    $errors[] = 'Location is too long.';
}

if (!array_key_exists($format, $formatOptions)) {
    $errors[] = 'Invalid participation format.';
}

if (!array_key_exists($sort, $sortOptions)) {
    $errors[] = 'Invalid sorting option.';
}

if (empty($errors)) {

    $sql = "
        SELECT
            s.study_id,
            s.title,
            s.topic,
            s.aim,
            s.description,
            s.participation_tasks,
            s.eligibility_requirements,
            s.participation_format,
            s.location,
            s.start_date,
            s.end_date,
            s.application_deadline,
            s.reimbursement_amount,
            s.reimbursement_details,
            s.anonymous_participation_allowed,
            s.is_test,
            u.first_name,
            u.last_name,
            ap.institution,
            sa.status AS application_status,
            pr.status AS invitation_status,
            COALESCE(vsp.preference_status, '') AS preference_status

        FROM studies s
        LEFT JOIN volunteer_study_preferences vsp
            ON vsp.study_id = s.study_id AND vsp.volunteer_id = :viewer_id
        LEFT JOIN study_applications sa
            ON sa.study_id = s.study_id AND sa.volunteer_id = :application_viewer_id
        LEFT JOIN participation_requests pr
            ON pr.study_id = s.study_id AND pr.volunteer_id = :invitation_viewer_id

        INNER JOIN users u
            ON u.user_id = s.researcher_id

        INNER JOIN academic_profiles ap
            ON ap.user_id = u.user_id

        WHERE s.status = 'open'
          AND u.role = 'academic'
          AND ap.verification_status = 'verified'
          AND s.application_deadline >= CURDATE()
          AND s.end_date >= CURDATE()
    ";

    $params = [
        ':viewer_id' => (int)$user['user_id'],
        ':application_viewer_id' => (int)$user['user_id'],
        ':invitation_viewer_id' => (int)$user['user_id']
    ];

    if ($view === 'saved') {
        $sql .= " AND vsp.preference_status = 'saved'";
    } elseif ($view === 'hidden') {
        $sql .= " AND vsp.preference_status = 'hidden'";
    } else {
        $sql .= " AND (vsp.preference_status IS NULL OR vsp.preference_status <> 'hidden')";
    }

    if ($keyword !== '') {

        $sql .= "
            AND (
                s.title LIKE :keyword_title
                OR s.topic LIKE :keyword_topic
                OR s.description LIKE :keyword_description
            )
        ";

        $search = '%' . $keyword . '%';

        $params[':keyword_title'] = $search;
        $params[':keyword_topic'] = $search;
        $params[':keyword_description'] = $search;
    }

    if ($location !== '') {

        $sql .= " AND s.location LIKE :location";

        $params[':location'] = '%' . $location . '%';
    }

    if ($format !== '') {

        $sql .= " AND s.participation_format = :format";

        $params[':format'] = $format;
    }

    switch ($sort) {

        case 'reward_high':
            $sql .= "
                ORDER BY
                    s.reimbursement_amount DESC,
                    s.study_id DESC
            ";
            break;

        case 'reward_low':
            $sql .= "
                ORDER BY
                    s.reimbursement_amount ASC,
                    s.study_id DESC
            ";
            break;

        default:
            $sql .= "
                ORDER BY
                    s.created_at DESC,
                    s.study_id DESC
            ";
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $studies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Throwable $exception) {

        error_log(
            'Study search error: ' .
            $exception->getMessage()
        );

        $errors[] = 'Unable to load studies.';
    }
}

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Browse Research Studies | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/browse-studies.css">
</head>
<body>
<header class="site-header">
    <div class="site-header__inner">
        <a class="brand" href="../../index.php" aria-label="Research Recruitment homepage">
            <span class="brand__mark">LOGO</span>
            <span class="brand__name">Research Recruitment</span>
        </a>
        <nav class="site-nav dashboard-nav" aria-label="Main navigation">
            <a href="volunteer-dashboard.php">Dashboard</a>
            <a href="browse-studies.php" aria-current="page">Studies</a>
            <a href="my-applications.php">Applications</a>
            <a href="my-invitations.php">Invitations</a>
            <a href="../shared/profile.php">My profile</a>
            <form action="../../actions/account/logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell studies-page">
    <section class="studies-banner" aria-labelledby="studies-page-title">
        <div>
            <span class="eyebrow">Research opportunities</span>
            <h1 id="studies-page-title">Find your next research study</h1>
            <p>Welcome back, <?= e($user['first_name']) ?>! Explore available studies, track your applications and save the opportunities that interest you.</p>
        </div>
        <div class="studies-banner__links">
            <a class="btn btn--primary" href="my-applications.php">My applications</a>
            <a class="btn studies-btn--outline" href="my-invitations.php">My invitations</a>
        </div>
    </section>

    <section class="studies-filter-panel" aria-labelledby="studies-search-title">
        <div class="studies-section-heading">
            <div>
                <span class="eyebrow">Discover studies</span>
                <h2 id="studies-search-title">Search and filter</h2>
            </div>
            <p>Find a study by interest, location or participation format.</p>
        </div>

        <form method="get" action="browse-studies.php" class="studies-search-form">
            <div class="studies-filter-grid">
                <div class="field">
                    <label for="keyword">Search keyword</label>
                    <input type="search" id="keyword" name="keyword" maxlength="100" placeholder="e.g. technology, wellbeing" value="<?= e($keyword) ?>">
                </div>
                <div class="field">
                    <label for="location">Location</label>
                    <input type="text" id="location" name="location" maxlength="150" placeholder="e.g. Newcastle" value="<?= e($location) ?>">
                </div>
                <div class="field">
                    <label for="format">Participation format</label>
                    <select id="format" name="format">
                        <?php foreach ($formatOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $format === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="sort">Sort by</label>
                    <select id="sort" name="sort">
                        <?php foreach ($sortOptions as $value => $label): ?>
                            <option value="<?= e($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="view">Show</label>
                    <select name="view" id="view">
                        <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Available</option>
                        <option value="saved" <?= $view === 'saved' ? 'selected' : '' ?>>Saved</option>
                        <option value="hidden" <?= $view === 'hidden' ? 'selected' : '' ?>>Hidden</option>
                    </select>
                </div>
            </div>
            <div class="studies-filter-actions">
                <button class="btn btn--primary" type="submit">Search studies</button>
                <a class="btn studies-btn--outline" href="browse-studies.php">Clear filters</a>
            </div>
        </form>
    </section>

    <section class="studies-results" aria-labelledby="studies-results-title">
        <div class="studies-section-heading studies-results__heading">
            <div>
                <span class="eyebrow">Your opportunities</span>
                <h2 id="studies-results-title"><?= e($view === 'all' ? 'Available' : ucfirst($view)) ?> research studies</h2>
            </div>
            <?php if (empty($errors)): ?>
                <p class="studies-result-count"><?= count($studies) ?> <?= count($studies) === 1 ? 'study' : 'studies' ?> found</p>
            <?php endif; ?>
        </div>

        <?php if (is_string($preferenceNotice)): ?>
            <div class="feedback feedback--success studies-feedback" role="status"><?= e($preferenceNotice) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="feedback feedback--error studies-feedback" role="alert">
                <strong>Unable to complete your search.</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php elseif (empty($studies)): ?>
            <div class="studies-empty">
                <h3>No studies found</h3>
                <p>No research studies match these filters. Try another keyword or change your search options.</p>
                <a class="btn btn--primary" href="browse-studies.php">View available studies</a>
            </div>
        <?php else: ?>
            <div class="studies-grid">
                <?php foreach ($studies as $study): ?>
                    <article class="study-card">
                        <div class="study-card__top">
                            <div class="study-card__tags">
                                <span class="study-tag"><?= e($study['topic']) ?></span>
                                <span class="study-tag study-tag--light"><?= e($formatOptions[$study['participation_format']] ?? $study['participation_format']) ?></span>
                            </div>
                            <h3><?= e($study['title']) ?></h3>
                            <p class="study-card__byline">By <?= e($study['first_name']) ?> <?= e($study['last_name']) ?><?php if (!empty($study['institution'])): ?> · <?= e($study['institution']) ?><?php endif; ?></p>
                        </div>

                        <dl class="study-meta">
                            <div>
                                <dt>Location</dt>
                                <dd><?= e($study['location'] ?: ($study['participation_format'] === 'online' ? 'Online' : 'Not specified')) ?></dd>
                            </div>
                            <div>
                                <dt>Reimbursement</dt>
                                <dd>£<?= number_format((float)$study['reimbursement_amount'], 2) ?></dd>
                            </div>
                            <div>
                                <dt>Application deadline</dt>
                                <dd><?= e($study['application_deadline']) ?></dd>
                            </div>
                        </dl>

                        <details class="study-details">
                            <summary>View study details</summary>
                            <div class="study-details__content">
                                <h4>Research aim</h4>
                                <p><?= nl2br(e($study['aim'])) ?></p>
                                <h4>Study description</h4>
                                <p><?= nl2br(e($study['description'])) ?></p>
                                <h4>Participant tasks</h4>
                                <p><?= nl2br(e($study['participation_tasks'])) ?></p>
                                <h4>Eligibility requirements</h4>
                                <p><?= nl2br(e($study['eligibility_requirements'])) ?></p>
                                <h4>Study dates</h4>
                                <p><?= e($study['start_date']) ?> to <?= e($study['end_date']) ?></p>
                                <h4>Reimbursement details</h4>
                                <p><?= e($study['reimbursement_details'] ?? 'Not specified') ?></p>
                                <h4>Anonymous participation</h4>
                                <p><?= $study['anonymous_participation_allowed'] ? 'Allowed' : 'Not allowed' ?></p>
                            </div>
                        </details>

                        <div class="study-card__actions">
                            <?php if ($study['application_status'] !== null): ?>
                                <p class="study-state">Your application: <strong><?= e(ucfirst($study['application_status'])) ?></strong></p>
                                <a class="btn btn--primary" href="my-applications.php">View my applications</a>
                            <?php elseif ($study['invitation_status'] !== null): ?>
                                <p class="study-state">Your invitation: <strong><?= e(ucfirst($study['invitation_status'])) ?></strong></p>
                                <a class="btn btn--primary" href="my-invitations.php">View my invitations</a>
                            <?php else: ?>
                                <a class="btn btn--primary" href="apply-study.php?id=<?= (int)$study['study_id'] ?>">View and apply</a>
                            <?php endif; ?>

                            <form class="study-preferences" method="post" action="../../actions/preferences/preference-action.php">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['preference_csrf']) ?>">
                                <input type="hidden" name="kind" value="study">
                                <input type="hidden" name="item_id" value="<?= (int)$study['study_id'] ?>">
                                <input type="hidden" name="return_page" value="browse-studies.php">
                                <input type="hidden" name="return_query" value="<?= e(http_build_query($_GET)) ?>">
                                <button class="study-action-secondary" type="submit" name="action" value="<?= $study['preference_status'] === 'saved' ? 'clear' : 'save' ?>">
                                    <?= $study['preference_status'] === 'saved' ? 'Remove saved' : 'Save study' ?>
                                </button>
                                <button class="study-action-secondary" type="submit" name="action" value="<?= $study['preference_status'] === 'hidden' ? 'clear' : 'hide' ?>">
                                    <?= $study['preference_status'] === 'hidden' ? 'Restore study' : 'Hide study' ?>
                                </button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
