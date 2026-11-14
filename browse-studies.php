<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Browse Research Studies</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Browse Research Studies</h2>

<p>Welcome, <?= e($user['first_name']) ?>!</p>

<h3>Find a Research Study</h3>

<form method="get" action="browse-studies.php">

    <p>
        <label for="keyword">
            Search by keyword:
        </label>

        <input type="search"
               id="keyword"
               name="keyword"
               maxlength="100"
               value="<?= e($keyword) ?>">
    </p>

    <p>
        <label for="location">Location:</label>

        <input type="text"
               id="location"
               name="location"
               maxlength="150"
               value="<?= e($location) ?>">
    </p>

    <p>
        <label for="format">
            Participation Format:
        </label>

        <select id="format" name="format">

            <?php foreach ($formatOptions as $value => $label): ?>

                <option
                    value="<?= e($value) ?>"
                    <?= $format === $value ? 'selected' : '' ?>
                >
                    <?= e($label) ?>
                </option>

            <?php endforeach; ?>

        </select>
    </p>

    <p>
        <label for="sort">Sort by:</label>

        <select id="sort" name="sort">

            <?php foreach ($sortOptions as $value => $label): ?>

                <option
                    value="<?= e($value) ?>"
                    <?= $sort === $value ? 'selected' : '' ?>
                >
                    <?= e($label) ?>
                </option>

            <?php endforeach; ?>

        </select>
    </p>

    <p>
        <label for="view">Show:</label>
        <select name="view" id="view">
            <option value="all" <?= $view === 'all' ? 'selected' : '' ?>>Available</option>
            <option value="saved" <?= $view === 'saved' ? 'selected' : '' ?>>Saved</option>
            <option value="hidden" <?= $view === 'hidden' ? 'selected' : '' ?>>Hidden</option>
        </select>
    </p>

    <button type="submit">Search Studies</button>

    <a href="browse-studies.php">Clear Filters</a>

</form>

<hr>

<h3><?= e(ucfirst($view === 'all' ? 'available' : $view)) ?> Research Studies</h3>
<?php if (is_string($preferenceNotice)): ?>
    <p role="status"><?= e($preferenceNotice) ?></p>
<?php endif; ?>

<?php if (!empty($errors)): ?>

    <div role="alert">
        <ul>
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>

<?php elseif (empty($studies)): ?>

    <p>No research studies match your selected filters.</p>

<?php else: ?>

    <p>
        Studies found: <?= count($studies) ?>
    </p>

    <?php foreach ($studies as $study): ?>

        <article>

            <h4><?= e($study['title']) ?></h4>

            <p>
                Topic: <?= e($study['topic']) ?>
            </p>

            <p>
                Researcher:
                <?= e($study['first_name']) ?>
                <?= e($study['last_name']) ?>
            </p>

            <p>
                Institution:
                <?= e($study['institution']) ?>
            </p>

            <p>
                Participation Format:
                <?= e($study['participation_format']) ?>
            </p>

            <p>
                Location:
                <?= e($study['location'] ?? 'Online') ?>
            </p>

            <p>
                Reimbursement:
                £<?= number_format(
                    (float)$study['reimbursement_amount'],
                    2
                ) ?>
            </p>

            <p>
                Application Deadline:
                <?= e($study['application_deadline']) ?>
            </p>

            <details>
                <summary>View Study Details</summary>

                <h5>Research Aim</h5>
                <p><?= nl2br(e($study['aim'])) ?></p>

                <h5>Study Description</h5>
                <p><?= nl2br(e($study['description'])) ?></p>

                <h5>Participant Tasks</h5>
                <p><?= nl2br(e($study['participation_tasks'])) ?></p>

                <h5>Eligibility Requirements</h5>
                <p><?= nl2br(e(
                    $study['eligibility_requirements']
                )) ?></p>

                <h5>Study Dates</h5>
                <p>
                    <?= e($study['start_date']) ?>
                    to
                    <?= e($study['end_date']) ?>
                </p>

                <h5>Reimbursement Details</h5>
                <p><?= e(
                    $study['reimbursement_details']
                    ?? 'Not specified'
                ) ?></p>

                <h5>Anonymous Participation</h5>
                <p>
                    <?= $study['anonymous_participation_allowed']
                        ? 'Allowed'
                        : 'Not allowed' ?>
                </p>
            </details>

            
            <?php if ($study['application_status'] !== null): ?>
                <p>
                    Your application: <strong><?= e(ucfirst($study['application_status'])) ?></strong>
                </p>
                <p><a href="my-applications.php">View My Applications</a></p>
            <?php elseif ($study['invitation_status'] !== null): ?>
                <p>
                    Your invitation: <strong><?= e(ucfirst($study['invitation_status'])) ?></strong>
                </p>
                <p><a href="my-invitations.php">View My Invitations</a></p>
            <?php else: ?>
                <p><a href="apply-study.php?id=<?= (int)$study['study_id'] ?>">View and Apply</a></p>
            <?php endif; ?>

            <form method="post" action="preference-action.php">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['preference_csrf']) ?>">
                <input type="hidden" name="kind" value="study">
                <input type="hidden" name="item_id" value="<?= (int)$study['study_id'] ?>">
                <input type="hidden" name="return_page" value="browse-studies.php">
                <input type="hidden" name="return_query" value="<?= e(http_build_query($_GET)) ?>">
                <button type="submit" name="action" value="<?= $study['preference_status'] === 'saved' ? 'clear' : 'save' ?>">
                    <?= $study['preference_status'] === 'saved' ? 'Remove saved' : 'Save study' ?>
                </button>
                <button type="submit" name="action" value="<?= $study['preference_status'] === 'hidden' ? 'clear' : 'hide' ?>">
                    <?= $study['preference_status'] === 'hidden' ? 'Restore study' : 'Hide study' ?>
                </button>
            </form>

            <hr>

        </article>

    <?php endforeach; ?>

<?php endif; ?>

<p>
    <a href="volunteer-dashboard.php">
        Back to Volunteer Dashboard
    </a>
</p>

</body>
</html>
