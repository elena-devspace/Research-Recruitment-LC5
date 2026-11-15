<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

requireRole('academic');

$user = currentUser();
$pdo = getConnection();

$researcherId = (int) $user['user_id'];

function escapeSentRequest($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}


$stmt = $pdo->prepare("
    SELECT
        pr.request_id,
        pr.study_id,
        pr.volunteer_id,
        CASE WHEN pr.status = 'accepted' THEN pr.message END AS message,
        pr.status AS request_status,
        pr.requested_at,
        pr.responded_at,

        s.title AS study_title,
        s.topic,
        s.status AS study_status,
        s.is_test,

        CASE WHEN pr.status = 'accepted' THEN v.first_name END AS volunteer_first_name,
        CASE WHEN pr.status = 'accepted' THEN v.last_name END AS volunteer_last_name,
        CASE WHEN pr.status = 'accepted' THEN COALESCE(vp.preferred_contact_method, 'email') END AS contact_method,
        CASE WHEN pr.status = 'accepted' AND COALESCE(vp.preferred_contact_method, 'email') = 'email'
            THEN v.email END AS volunteer_email,
        CASE WHEN pr.status = 'accepted' AND vp.preferred_contact_method = 'phone'
            THEN vp.phone_number END AS volunteer_phone

    FROM participation_requests pr

    INNER JOIN studies s
        ON pr.study_id = s.study_id

    INNER JOIN users v
        ON pr.volunteer_id = v.user_id
    LEFT JOIN volunteer_profiles vp
        ON vp.user_id = v.user_id

    WHERE pr.researcher_id = :researcher_id
      AND s.researcher_id = :study_researcher_id
      AND v.role = 'volunteer'

    ORDER BY pr.requested_at DESC, pr.request_id DESC
");

$stmt->execute([
    'researcher_id' => $researcherId,
    'study_researcher_id' => $researcherId
]);

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRequests = count($requests);

$pendingRequests = 0;
$acceptedRequests = 0;
$declinedRequests = 0;

foreach ($requests as $request) {

    switch ($request['request_status']) {

        case 'pending':
            $pendingRequests++;
            break;

        case 'accepted':
            $acceptedRequests++;
            break;

        case 'declined':
            $declinedRequests++;
            break;
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Sent Invitations | Research Recruitment</title>
    <link rel="stylesheet" href="assets/css/site.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="assets/css/my-sent-requests.css">
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
            <a href="find-volunteers.php">Find volunteers</a>
            <a href="my-sent-requests.php" aria-current="page">Invitations</a>
            <a href="profile.php">My profile</a>
            <form action="logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= escapeSentRequest($_SESSION['csrf_token']) ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>
<main class="page-shell sent-page">
    <section class="sent-hero">
        <div>
            <p class="eyebrow">YOUR RESEARCH WORKSPACE</p>
            <h1>Track your sent invitations</h1>
            <p>Welcome back, <?= escapeSentRequest($user['first_name']) ?>! Review invitations you have sent to volunteers and keep track of their responses.</p>
        </div>
        <a href="find-volunteers.php" class="btn btn--primary">Find volunteers</a>
    </section>

    <section class="sent-section" aria-labelledby="sent-overview-heading">
        <div class="sent-section-head">
            <div>
                <p class="eyebrow">INVITATION OVERVIEW</p>
                <h2 id="sent-overview-heading">Your invitations at a glance</h2>
            </div>
            <span class="sent-subtle"><?= $totalRequests ?> total invitations</span>
        </div>
        <div class="sent-stats">
            <div class="sent-stat"><span>Total invitations</span><strong><?= $totalRequests ?></strong><small>Sent to volunteers</small></div>
            <div class="sent-stat"><span>Pending</span><strong><?= $pendingRequests ?></strong><small>Awaiting volunteer responses</small></div>
            <div class="sent-stat"><span>Accepted</span><strong><?= $acceptedRequests ?></strong><small>Invitations accepted</small></div>
            <div class="sent-stat"><span>Declined</span><strong><?= $declinedRequests ?></strong><small>Invitations declined</small></div>
        </div>
    </section>

    <section class="sent-section" aria-labelledby="sent-history-heading">
        <div class="sent-section-head">
            <div>
                <p class="eyebrow">INVITATION HISTORY</p>
                <h2 id="sent-history-heading">Invitations to your studies</h2>
            </div>
            <span class="sent-subtle">Newest invitations first</span>
        </div>
        <?php if (empty($requests)): ?>
            <div class="sent-empty">
                <h3>No invitations sent yet</h3>
                <p>You haven't invited any volunteers to participate in your research studies.</p>
                <a href="find-volunteers.php" class="btn btn--primary">Find volunteers</a>
            </div>
        <?php else: ?>
            <div class="sent-card-grid">
                <?php foreach ($requests as $request): ?>
                    <?php
                    $status = (string)$request['request_status'];
                    $statusClass = in_array($status, ['pending', 'accepted', 'declined'], true)
                        ? $status
                        : 'other';
                    $accepted = $status === 'accepted';
                    $contactMethod = (string)($request['contact_method'] ?? 'email');
                    ?>
                    <article class="sent-card">
                        <div class="sent-card-head">
                            <div class="sent-tags">
                                <span class="sent-tag"><?= escapeSentRequest($request['topic']) ?></span>
                                <?php if ((int)$request['is_test'] === 1): ?>
                                    <span class="sent-tag sent-tag-muted">Fictional test study</span>
                                <?php endif; ?>
                            </div>
                            <span class="sent-status sent-status--<?= $statusClass ?>"><?= escapeSentRequest(ucfirst($status)) ?></span>
                        </div>
                        <h3><?= escapeSentRequest($request['study_title']) ?></h3>
                        <p class="sent-reference">Invitation #<?= (int)$request['request_id'] ?> · Volunteer #<?= (int)$request['volunteer_id'] ?></p>

                        <dl class="sent-facts">
                            <div><dt>Study ID</dt><dd>#<?= (int)$request['study_id'] ?></dd></div>
                            <div><dt>Study status</dt><dd><?= escapeSentRequest(ucfirst((string)$request['study_status'])) ?></dd></div>
                            <div><dt>Sent at</dt><dd><?= escapeSentRequest($request['requested_at']) ?></dd></div>
                            <?php if (!empty($request['responded_at'])): ?>
                                <div><dt>Responded at</dt><dd><?= escapeSentRequest($request['responded_at']) ?></dd></div>
                            <?php endif; ?>
                        </dl>

                        <?php if ((int)$request['is_test'] === 1): ?>
                            <p class="sent-info">Fictional test study — no real recruitment or payment.</p>
                        <?php endif; ?>

                        <div class="sent-card-section">
                            <h4>Volunteer information</h4>
                            <?php if ($accepted): ?>
                                <div class="sent-contact">
                                    <strong>Volunteer contact details</strong>
                                    <p><?= escapeSentRequest($request['volunteer_first_name']) ?> <?= escapeSentRequest($request['volunteer_last_name']) ?></p>
                                    <p>Preferred contact: <?= escapeSentRequest(ucfirst($contactMethod)) ?></p>
                                    <?php if ($contactMethod === 'phone'): ?>
                                        <p>Phone: <?= escapeSentRequest($request['volunteer_phone'] ?: 'Not supplied') ?></p>
                                    <?php else: ?>
                                        <p>Email: <?= escapeSentRequest($request['volunteer_email'] ?: 'Not supplied') ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <p class="sent-private">Identity and contact details are private until the invitation is accepted.</p>
                            <?php endif; ?>
                        </div>

                        <div class="sent-card-section">
                            <h4>Invitation message</h4>
                            <?php if (!$accepted): ?>
                                <p>Invitation message hidden until acceptance.</p>
                            <?php elseif (!empty($request['message'])): ?>
                                <p class="sent-message"><?= escapeSentRequest($request['message']) ?></p>
                            <?php else: ?>
                                <p>No invitation message provided.</p>
                            <?php endif; ?>
                        </div>

                        <div class="sent-response sent-response--<?= $statusClass ?>">
                            <?php if ($status === 'pending'): ?>
                                Awaiting a response from the volunteer.
                            <?php elseif ($status === 'accepted'): ?>
                                The volunteer has accepted your invitation.
                            <?php elseif ($status === 'declined'): ?>
                                The volunteer has declined your invitation.
                            <?php else: ?>
                                Current response: <?= escapeSentRequest($status) ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <aside class="sent-shortcuts">
        <div>
            <h2>Keep your recruitment organised</h2>
            <p>Find more volunteers, manage your studies or return to your dashboard.</p>
        </div>
        <div class="sent-shortcut-links">
            <a href="find-volunteers.php" class="btn sent-light-button">Find volunteers</a>
            <a href="my-studies.php" class="btn sent-light-button">My studies</a>
            <a href="academic-dashboard.php" class="btn sent-light-button">Dashboard</a>
        </div>
    </aside>
</main>
<footer class="site-footer">
    <div class="site-footer__inner">
        <span>© <?= date('Y') ?> Research Recruitment · KV6014 university project</span>
        <div class="site-footer__items" aria-label="Site information">
            <span>Privacy Policy</span><span>Contact</span><span>Terms</span>
        </div>
    </div>
</footer>
</body>
</html>
