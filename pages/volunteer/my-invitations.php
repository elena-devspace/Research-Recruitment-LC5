<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';
require_once __DIR__ . '/../../app/recruitment/study-capacity.php';

requireRole('volunteer');

$user = currentUser();
$pdo = getConnection();

$volunteerId = (int) $user['user_id'];

function escapeInvitation($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

if (!isset($_SESSION['invitation_csrf'])) {
    $_SESSION['invitation_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

if (isset($_SESSION['invitation_success'])) {
    $success = (string) $_SESSION['invitation_success'];
    unset($_SESSION['invitation_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['invitation_csrf'], $token)
    ) {
        http_response_code(403);
        exit('Invalid request token.');
    }

    $requestId = filter_input(
        INPUT_POST,
        'request_id',
        FILTER_VALIDATE_INT
    );

    $rawDecision = $_POST['decision'] ?? '';

    $decision = is_string($rawDecision)
        ? $rawDecision
        : '';

    if (!$requestId || $requestId < 1) {

        $error = 'Invalid invitation.';

    } elseif (!in_array(
        $decision,
        ['accepted', 'declined'],
        true
    )) {

        $error = 'Invalid invitation response.';

    } else {

        try {
            $lookup = $pdo->prepare(
                'SELECT study_id FROM participation_requests
                 WHERE request_id = ? AND volunteer_id = ?'
            );
            $lookup->execute([$requestId, $volunteerId]);
            $studyId = $lookup->fetchColumn();

            if ($studyId === false) {
                $error = 'Invitation not found or access denied.';
            } else {
                $pdo->beginTransaction();
                $capacity = getStudyCapacity($pdo, (int)$studyId, true);

                $stateStmt = $pdo->prepare(
                    'SELECT pr.status, s.status AS study_status
                     FROM participation_requests pr
                     INNER JOIN studies s ON s.study_id = pr.study_id
                     WHERE pr.request_id = ? AND pr.volunteer_id = ? FOR UPDATE'
                );
                $stateStmt->execute([$requestId, $volunteerId]);
                $state = $stateStmt->fetch(PDO::FETCH_ASSOC);

                if (!$state || $state['status'] !== 'pending') {
                    $error = 'This invitation has already been answered.';
                } elseif ($decision === 'accepted' && $state['study_status'] !== 'open') {
                    $error = 'This study is no longer open.';
                } elseif (
                    $decision === 'accepted' &&
                    $capacity['remaining'] !== null &&
                    $capacity['remaining'] < 1
                ) {
                    $error = 'This study is full. The invitation cannot be accepted.';
                } else {
                    $update = $pdo->prepare(
                        "UPDATE participation_requests
                         SET status = ?, responded_at = NOW()
                         WHERE request_id = ? AND volunteer_id = ? AND status = 'pending'"
                    );
                    $update->execute([$decision, $requestId, $volunteerId]);

                    if ($update->rowCount() === 1) {
                        $pdo->commit();
                        $_SESSION['invitation_success'] = $decision === 'accepted'
                            ? 'Invitation accepted successfully!'
                            : 'Invitation declined successfully!';
                        header('Location: my-invitations.php');
                        exit;
                    }

                    $error = 'Unable to update this invitation.';
                }

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Invitation response error: ' . $exception->getMessage());
            $error = 'Unable to update your invitation. Please try again.';
        }
    }
}

$stmt = $pdo->prepare("
    SELECT
        pr.request_id,
        pr.study_id,
        pr.message,
        pr.status AS request_status,
        pr.requested_at,
        pr.responded_at,

        s.title,
        s.topic,
        s.status AS study_status,
        s.is_test,

        u.first_name AS researcher_first_name,
        u.last_name AS researcher_last_name

    FROM participation_requests pr

    INNER JOIN studies s
        ON pr.study_id = s.study_id

    INNER JOIN users u
        ON pr.researcher_id = u.user_id

    WHERE pr.volunteer_id = ?

    ORDER BY
        (pr.status = 'pending') DESC,
        pr.requested_at DESC,
        pr.request_id DESC
");

$stmt->execute([$volunteerId]);

$invitations = $stmt->fetchAll(PDO::FETCH_ASSOC);
$capacityByStudy = [];
foreach ($invitations as $invitation) {
    $studyId = (int)$invitation['study_id'];
    if (!isset($capacityByStudy[$studyId])) {
        $capacityByStudy[$studyId] = getStudyCapacity($pdo, $studyId);
    }
}

$pendingCount = 0;

foreach ($invitations as $invitation) {
    if ($invitation['request_status'] === 'pending') {
        $pendingCount++;
    }
}

$statusCounts = [
    'pending' => 0,
    'accepted' => 0,
    'declined' => 0,
];
foreach ($invitations as $invitation) {
    $status = (string)$invitation['request_status'];
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
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
    <title>My Invitations | Research Recruitment</title>
    <link rel="stylesheet" href="../../assets/css/site.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/my-invitations.css">
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
            <a href="browse-studies.php">Studies</a>
            <a href="my-applications.php">Applications</a>
            <a href="my-invitations.php" aria-current="page">Invitations</a>
            <a href="../shared/profile.php">My profile</a>
            <form action="../../actions/account/logout.php" method="post" class="dashboard-logout">
                <input type="hidden" name="csrf_token" value="<?= escapeInvitation($_SESSION['csrf_token']) ?>">
                <button type="submit" class="dashboard-logout__button">Log out</button>
            </form>
        </nav>
    </div>
</header>

<main class="page-shell invitations-page">
    <section class="invitations-hero" aria-labelledby="invitations-title">
        <div class="invitations-hero__copy">
            <span class="eyebrow">Your research journey</span>
            <h1 id="invitations-title">Manage your invitations</h1>
            <p>Welcome back, <?= escapeInvitation($user['first_name']) ?>! Review research invitations and decide which opportunities are right for you.</p>
        </div>
        <a href="browse-studies.php" class="btn btn--primary">Explore research studies</a>
    </section>

    <?php if ($success !== ''): ?>
        <div class="feedback feedback--success invitations-feedback" role="status"><?= escapeInvitation($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="feedback feedback--error invitations-feedback" role="alert"><?= escapeInvitation($error) ?></div>
    <?php endif; ?>

    <section class="invitations-overview" aria-labelledby="invitations-overview-title">
        <div class="invitations-section-header">
            <div>
                <span class="eyebrow">Invitation overview</span>
                <h2 id="invitations-overview-title">Your invitations at a glance</h2>
            </div>
            <p><?= count($invitations) ?> total invitation<?= count($invitations) === 1 ? '' : 's' ?></p>
        </div>
        <div class="invitations-stats" aria-label="Invitation status counts">
            <div class="invitations-stat">
                <span class="invitations-stat__label">Total invitations</span>
                <strong><?= count($invitations) ?></strong>
                <span class="invitations-stat__description">Received from researchers</span>
            </div>
            <div class="invitations-stat">
                <span class="invitations-stat__label">Pending</span>
                <strong><?= $statusCounts['pending'] ?></strong>
                <span class="invitations-stat__description">Awaiting your response</span>
            </div>
            <div class="invitations-stat">
                <span class="invitations-stat__label">Accepted</span>
                <strong><?= $statusCounts['accepted'] ?></strong>
                <span class="invitations-stat__description">Invitations you accepted</span>
            </div>
            <div class="invitations-stat">
                <span class="invitations-stat__label">Declined</span>
                <strong><?= $statusCounts['declined'] ?></strong>
                <span class="invitations-stat__description">Invitations you declined</span>
            </div>
        </div>
    </section>

    <section class="invitations-list" aria-labelledby="received-invitations-title">
        <div class="invitations-section-header">
            <div>
                <span class="eyebrow">Invitation history</span>
                <h2 id="received-invitations-title">My research invitations</h2>
            </div>
            <p>Pending invitations appear first.</p>
        </div>

        <?php if (empty($invitations)): ?>
            <div class="invitations-empty">
                <span class="invitations-empty__icon" aria-hidden="true">01</span>
                <h3>No invitations yet</h3>
                <p>When an academic researcher invites you to participate in a study, you will see it here.</p>
                <a href="browse-studies.php" class="btn btn--primary">Browse research studies</a>
            </div>
        <?php else: ?>
            <div class="invitations-grid">
                <?php foreach ($invitations as $invitation): ?>
                    <?php
                        $status = (string)$invitation['request_status'];
                        $statusClass = in_array($status, ['pending', 'accepted', 'declined'], true)
                            ? $status
                            : 'unknown';
                        $capacity = $capacityByStudy[(int)$invitation['study_id']];
                        $canAccept = $status === 'pending'
                            && $invitation['study_status'] === 'open'
                            && ($capacity['remaining'] === null || $capacity['remaining'] > 0);
                    ?>
                    <article class="invitation-card" aria-labelledby="invitation-title-<?= (int)$invitation['request_id'] ?>">
                        <div class="invitation-card__badges">
                            <span class="invitation-card__topic"><?= escapeInvitation($invitation['topic']) ?></span>
                            <?php if ((int)$invitation['is_test'] === 1): ?>
                                <span class="invitation-card__tag">Fictional test study</span>
                            <?php endif; ?>
                            <span class="invitation-card__status invitation-card__status--<?= $statusClass ?>">
                                <?= escapeInvitation(ucfirst($status)) ?>
                            </span>
                        </div>

                        <h3 id="invitation-title-<?= (int)$invitation['request_id'] ?>"><?= escapeInvitation($invitation['title']) ?></h3>
                        <p class="invitation-card__researcher">Invited by <?= escapeInvitation(trim($invitation['researcher_first_name'] . ' ' . $invitation['researcher_last_name'])) ?></p>

                        <dl class="invitation-card__details">
                            <div>
                                <dt>Invitation ID</dt>
                                <dd>#<?= (int)$invitation['request_id'] ?></dd>
                            </div>
                            <div>
                                <dt>Study status</dt>
                                <dd><?= escapeInvitation(ucfirst($invitation['study_status'])) ?></dd>
                            </div>
                            <div>
                                <dt>Sent at</dt>
                                <dd><?= escapeInvitation($invitation['requested_at']) ?></dd>
                            </div>
                            <div>
                                <dt>Spaces remaining</dt>
                                <dd><?= $capacity['remaining'] === null
                                    ? 'No limit set'
                                    : (int)$capacity['remaining'] . ' of ' . (int)$capacity['capacity'] ?></dd>
                            </div>
                            <?php if ($status !== 'pending' && !empty($invitation['responded_at'])): ?>
                                <div>
                                    <dt>Responded at</dt>
                                    <dd><?= escapeInvitation($invitation['responded_at']) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>

                        <?php if ((int)$invitation['is_test'] === 1): ?>
                            <p class="invitation-card__test-notice">Fictional test study — no real participation or payment.</p>
                        <?php endif; ?>

                        <div class="invitation-card__message">
                            <h4>Message from researcher</h4>
                            <p><?= nl2br(escapeInvitation($invitation['message'] ?: 'No message provided.')) ?></p>
                        </div>

                        <?php if ($status === 'pending'): ?>
                            <div class="invitation-card__actions">
                                <h4>Respond to invitation</h4>
                                <?php if ($invitation['study_status'] !== 'open'): ?>
                                    <p class="invitation-card__warning">This study is no longer open. You cannot accept this invitation.</p>
                                <?php elseif ($capacity['remaining'] !== null && $capacity['remaining'] < 1): ?>
                                    <p class="invitation-card__warning">This study is full. You cannot accept this invitation.</p>
                                <?php endif; ?>
                                <form method="post" action="my-invitations.php" class="invitation-card__form">
                                    <input type="hidden" name="csrf_token" value="<?= escapeInvitation($_SESSION['invitation_csrf']) ?>">
                                    <input type="hidden" name="request_id" value="<?= (int)$invitation['request_id'] ?>">
                                    <?php if ($canAccept): ?>
                                        <button type="submit" name="decision" value="accepted" class="btn btn--primary invitation-card__accept">Accept invitation</button>
                                    <?php endif; ?>
                                    <button type="submit" name="decision" value="declined" class="invitation-card__decline">Decline invitation</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="invitation-card__decision invitation-card__decision--<?= $statusClass ?>">
                                <?= $status === 'accepted'
                                    ? 'You have accepted this invitation.'
                                    : ($status === 'declined'
                                        ? 'You have declined this invitation.'
                                        : 'This invitation has already been answered.') ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="invitations-shortcuts" aria-label="More volunteer actions">
        <div>
            <h2>Continue your research journey</h2>
            <p>Browse studies, review your applications or return to your dashboard.</p>
        </div>
        <div class="invitations-shortcuts__links">
            <a href="browse-studies.php" class="btn invitations-btn--light">Browse studies</a>
            <a href="my-applications.php" class="btn invitations-btn--light">My applications</a>
            <a href="volunteer-dashboard.php" class="btn invitations-btn--light">Dashboard</a>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <span>© 2026 Research Recruitment · KV6014 university project</span>
        <div class="site-footer__items" aria-label="Additional information">
            <span>Privacy Policy</span>
            <span>Contact</span>
            <span>Terms</span>
        </div>
    </div>
</footer>
</body>
</html>
