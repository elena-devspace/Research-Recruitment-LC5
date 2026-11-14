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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        My Sent Invitations - Research Recruitment
    </title>
</head>

<body>

<h1>Research Recruitment</h1>

<h2>My Sent Invitations</h2>

<p>
    Welcome,
    <?= escapeSentRequest($user['first_name']) ?>!
</p>

<p>
    View the participation invitations you have sent
    to volunteers and track their responses.
</p>

<h3>Invitation Overview</h3>

<p>
    Total Invitations:
    <?= $totalRequests ?>
</p>

<p>
    Pending Invitations:
    <?= $pendingRequests ?>
</p>

<p>
    Accepted Invitations:
    <?= $acceptedRequests ?>
</p>

<p>
    Declined Invitations:
    <?= $declinedRequests ?>
</p>

<hr>

<h3>Sent Invitations</h3>

<?php if (empty($requests)): ?>

    <p>
        You have not sent any research participation
        invitations yet.
    </p>

    <p>
        <a href="find-volunteers.php">
            Find Volunteers
        </a>
    </p>

<?php else: ?>

    <?php foreach ($requests as $request): ?>

        <h4>
            <?= escapeSentRequest(
                $request['study_title']
            ) ?>
        </h4>

        <?php if ((int) $request['is_test'] === 1): ?>

            <p>
                <strong>FICTIONAL TEST STUDY</strong>
                — No real recruitment or payment.
            </p>

        <?php endif; ?>

        <p>
            Invitation ID:
            <?= (int) $request['request_id'] ?>
        </p>

        <p>
            Study ID:
            <?= (int) $request['study_id'] ?>
        </p>

        <p>
            Research Topic:
            <?= escapeSentRequest($request['topic']) ?>
        </p>

        <p>
            Study Status:
            <?= escapeSentRequest(
                $request['study_status']
            ) ?>
        </p>

        <h4>Volunteer Information</h4>

        <p>Volunteer ID: <?= (int) $request['volunteer_id'] ?></p>

        <?php if ($request['request_status'] === 'accepted'): ?>
            <p>
                Volunteer:
                <?= escapeSentRequest($request['volunteer_first_name']) ?>
                <?= escapeSentRequest($request['volunteer_last_name']) ?>
            </p>
            <p>Preferred contact method: <?= escapeSentRequest(ucfirst($request['contact_method'])) ?></p>
            <?php if ($request['contact_method'] === 'phone'): ?>
                <p>Phone: <?= escapeSentRequest($request['volunteer_phone'] ?: 'Not supplied') ?></p>
            <?php else: ?>
                <p>Email: <?= escapeSentRequest($request['volunteer_email']) ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p>Identity and contact details are private until the invitation is accepted.</p>
        <?php endif; ?>

        <h4>Invitation Details</h4>

        <p>
            Invitation Sent:
            <?= escapeSentRequest(
                $request['requested_at']
            ) ?>
        </p>

        <p>
            Invitation Status:
            <strong>
                <?= escapeSentRequest(
                    ucfirst($request['request_status'])
                ) ?>
            </strong>
        </p>

        <h4>Invitation Message</h4>

        <?php if ($request['request_status'] !== 'accepted'): ?>

            <p>Invitation message hidden until acceptance.</p>

        <?php elseif (!empty($request['message'])): ?>

            <p style="white-space: pre-wrap;"><?= escapeSentRequest(
                $request['message']
            ) ?></p>

        <?php else: ?>

            <p>No invitation message provided.</p>

        <?php endif; ?>

        <h4>Volunteer Response</h4>

        <?php if ($request['request_status'] === 'pending'): ?>

            <p>
                Awaiting a response from the volunteer.
            </p>

        <?php elseif ($request['request_status'] === 'accepted'): ?>

            <p>
                The volunteer has accepted your invitation.
            </p>

        <?php elseif ($request['request_status'] === 'declined'): ?>

            <p>
                The volunteer has declined your invitation.
            </p>

        <?php else: ?>

            <p>
                Current response:
                <?= escapeSentRequest(
                    $request['request_status']
                ) ?>
            </p>

        <?php endif; ?>

        <?php if (!empty($request['responded_at'])): ?>

            <p>
                Responded At:
                <?= escapeSentRequest(
                    $request['responded_at']
                ) ?>
            </p>

        <?php endif; ?>

        <hr>

    <?php endforeach; ?>

<?php endif; ?>

<p>
    <a href="find-volunteers.php">
        Find Volunteers
    </a>
</p>

<p>
    <a href="academic-dashboard.php">
        Back to Academic Dashboard
    </a>
</p>

</body>
</html>
