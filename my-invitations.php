<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/study-capacity.php';

// Only volunteers can access this page.
requireRole('volunteer');

$user = currentUser();
$pdo = getConnection();

$volunteerId = (int) $user['user_id'];

// Safely display database values.
function escapeInvitation($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Create a CSRF token for responding to invitations.
if (!isset($_SESSION['invitation_csrf'])) {
    $_SESSION['invitation_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Display a success message after redirect.
if (isset($_SESSION['invitation_success'])) {
    $success = (string) $_SESSION['invitation_success'];
    unset($_SESSION['invitation_success']);
}

// Handle accepting or declining an invitation.
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

// Retrieve invitations for this volunteer only.
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>My Invitations - Research Recruitment</title>
</head>

<body>

<h1>Research Recruitment</h1>

<h2>My Research Invitations</h2>

<p>
    Welcome,
    <?= escapeInvitation($user['first_name']) ?>!
</p>

<p>
    View invitations from academic researchers
    and respond to participation requests.
</p>

<?php if ($success !== ''): ?>
    <p><?= escapeInvitation($success) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <p><?= escapeInvitation($error) ?></p>
<?php endif; ?>

<h3>Invitation Overview</h3>

<p>
    Total Invitations: <?= count($invitations) ?>
</p>

<p>
    Pending Invitations: <?= $pendingCount ?>
</p>

<hr>

<h3>Received Invitations</h3>

<?php if (empty($invitations)): ?>

    <p>
        You have not received any research
        participation invitations yet.
    </p>

<?php else: ?>

    <?php foreach ($invitations as $invitation): ?>

        <h4>
            <?= escapeInvitation($invitation['title']) ?>
        </h4>

        <?php if ((int) $invitation['is_test'] === 1): ?>

            <p>
                <strong>FICTIONAL TEST STUDY</strong>
                — No real participation or payment.
            </p>

        <?php endif; ?>

        <p>
            Invitation ID:
            <?= (int) $invitation['request_id'] ?>
        </p>

        <p>
            Research Topic:
            <?= escapeInvitation($invitation['topic']) ?>
        </p>

        <p>
            Researcher:
            <?= escapeInvitation(
                $invitation['researcher_first_name']
            ) ?>
            <?= escapeInvitation(
                $invitation['researcher_last_name']
            ) ?>
        </p>

        <p>
            Study Status:
            <?= escapeInvitation(
                $invitation['study_status']
            ) ?>
        </p>

        <p>
            Invitation Sent:
            <?= escapeInvitation(
                $invitation['requested_at']
            ) ?>
        </p>

        <?php $capacity = $capacityByStudy[(int)$invitation['study_id']]; ?>
        <p>
            Spaces remaining:
            <?= $capacity['remaining'] === null
                ? 'No limit set'
                : $capacity['remaining'] . ' of ' . $capacity['capacity'] ?>
        </p>

        <p>
            Invitation Status:
            <strong>
                <?= escapeInvitation(
                    ucfirst($invitation['request_status'])
                ) ?>
            </strong>
        </p>

        <h4>Message from Researcher</h4>

        <p>
            <?php if (
                !empty($invitation['message'])
            ): ?>

                <?= nl2br(
                    escapeInvitation($invitation['message'])
                ) ?>

            <?php else: ?>

                No message provided.

            <?php endif; ?>
        </p>

        <?php if (
            $invitation['request_status'] === 'pending'
        ): ?>

            <h4>Respond to Invitation</h4>

            <?php if (
                $invitation['study_status'] !== 'open'
            ): ?>

                <p>
                    This study is no longer open.
                    You cannot accept this invitation.
                </p>

            <?php endif; ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= escapeInvitation(
                        $_SESSION['invitation_csrf']
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="request_id"
                    value="<?= (int) $invitation['request_id'] ?>"
                >

                <?php if (
                    $invitation['study_status'] === 'open' &&
                    ($capacity['remaining'] === null || $capacity['remaining'] > 0)
                ): ?>

                    <button
                        type="submit"
                        name="decision"
                        value="accepted"
                    >
                        Accept Invitation
                    </button>

                <?php elseif ($invitation['study_status'] === 'open'): ?>
                    <p>This study is full. You cannot accept this invitation.</p>
                <?php endif; ?>

                <button
                    type="submit"
                    name="decision"
                    value="declined"
                >
                    Decline Invitation
                </button>

            </form>

        <?php else: ?>

            <p>
                This invitation has already been answered.
            </p>

            <?php if (
                !empty($invitation['responded_at'])
            ): ?>

                <p>
                    Responded At:
                    <?= escapeInvitation(
                        $invitation['responded_at']
                    ) ?>
                </p>

            <?php endif; ?>

        <?php endif; ?>

        <hr>

    <?php endforeach; ?>

<?php endif; ?>

<p>
    <a href="volunteer-dashboard.php">
        Back to Volunteer Dashboard
    </a>
</p>

</body>
</html>
