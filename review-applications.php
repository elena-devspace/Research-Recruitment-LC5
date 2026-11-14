<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('academic');
$pdo = getConnection();

$researcherId = (int)$user['user_id'];

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$verification = $pdo->prepare(
    "SELECT verification_status
     FROM academic_profiles
     WHERE user_id = ?
     LIMIT 1"
);

$verification->execute([$researcherId]);

if ($verification->fetchColumn() !== 'verified') {
    http_response_code(403);
    exit('Researcher verification required.');
}

if (!in_array(
    $_SERVER['REQUEST_METHOD'],
    ['GET', 'POST'],
    true
)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$notice = $_SESSION['application_review_notice'] ?? '';
unset($_SESSION['application_review_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;
    $decision = $_POST['decision'] ?? null;

    $applicationId = filter_input(
        INPUT_POST,
        'application_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        $error = 'Invalid request. Refresh the page.';

    } elseif (
        !is_int($applicationId) ||
        !is_string($decision) ||
        !in_array(
            $decision,
            ['accepted', 'rejected'],
            true
        )
    ) {
        http_response_code(400);
        $error = 'Invalid application decision.';

    } else {

        try {


            $update = $pdo->prepare(
                "UPDATE study_applications a
                 INNER JOIN studies s
                     ON s.study_id = a.study_id
                 SET
                     a.status = ?,
                     a.reviewed_at = NOW()
                 WHERE a.application_id = ?
                   AND s.researcher_id = ?
                   AND a.status = 'pending'"
            );

            $update->execute([
                $decision,
                $applicationId,
                $researcherId
            ]);

            if ($update->rowCount() === 1) {

                $_SESSION['application_review_notice'] =
                    $decision === 'accepted'
                        ? 'Application accepted successfully!'
                        : 'Application rejected successfully!';

                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                header('Location: review-applications.php');
                exit;

            } else {
                $error =
                    'Application not found, already reviewed, or access denied.';
            }

        } catch (Throwable $exception) {

            error_log(
                'Application review error: ' .
                $exception->getMessage()
            );

            $error = 'Unable to review application.';
        }
    }
}

$stmt = $pdo->prepare(
    "SELECT
        a.application_id,
        a.study_id,
        a.volunteer_id,
        a.message,
        a.status,
        a.applied_at,
        a.reviewed_at,

        s.title AS study_title,
        s.is_test,

        CASE WHEN a.status = 'accepted' THEN v.first_name END AS first_name,
        CASE WHEN a.status = 'accepted' THEN v.last_name END AS last_name,
        CASE WHEN a.status = 'accepted' THEN COALESCE(vp.preferred_contact_method, 'email') END AS contact_method,
        CASE WHEN a.status = 'accepted' AND COALESCE(vp.preferred_contact_method, 'email') = 'email'
            THEN v.email END AS email,
        CASE WHEN a.status = 'accepted' AND vp.preferred_contact_method = 'phone'
            THEN vp.phone_number END AS phone_number

     FROM study_applications a

     INNER JOIN studies s
         ON s.study_id = a.study_id

     INNER JOIN users v
         ON v.user_id = a.volunteer_id
     LEFT JOIN volunteer_profiles vp
         ON vp.user_id = v.user_id

     WHERE s.researcher_id = ?

     ORDER BY
         (a.status = 'pending') DESC,
         a.applied_at DESC,
         a.application_id DESC"
);

$stmt->execute([$researcherId]);

$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = 0;

foreach ($applications as $application) {
    if ($application['status'] === 'pending') {
        $pendingCount++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Review Volunteer Applications</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Review Volunteer Applications</h2>

<p>
    Welcome, <?= e($user['first_name']) ?>!
</p>

<p>
    Total Applications: <?= count($applications) ?>
</p>

<p>
    Pending Applications: <?= $pendingCount ?>
</p>

<?php if ($notice !== ''): ?>
    <p role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<hr>

<?php if (empty($applications)): ?>

    <p>No volunteer applications received yet.</p>

<?php else: ?>

    <?php foreach ($applications as $application): ?>

        <section>

            <h3>
                <?= e($application['study_title']) ?>
            </h3>

            <?php if ($application['is_test']): ?>
                <p>
                    <strong>
                        FICTIONAL TEST STUDY
                    </strong>
                </p>
            <?php endif; ?>

            <p>
                Application ID:
                <?= (int)$application['application_id'] ?>
            </p>

            <p>Volunteer ID: <?= (int) $application['volunteer_id'] ?></p>

            <?php if ($application['status'] === 'accepted'): ?>
                <p>Volunteer: <?= e($application['first_name']) ?> <?= e($application['last_name']) ?></p>
                <p>Preferred contact method: <?= e(ucfirst($application['contact_method'])) ?></p>
                <?php if ($application['contact_method'] === 'phone'): ?>
                    <p>Phone: <?= e($application['phone_number'] ?: 'Not supplied') ?></p>
                <?php else: ?>
                    <p>Email: <?= e($application['email']) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p>Identity and contact details are private while this application is not accepted.</p>
            <?php endif; ?>

            <p>
                Applied At:
                <?= e($application['applied_at']) ?>
            </p>

            <p>
                Status:
                <strong>
                    <?= e($application['status']) ?>
                </strong>
            </p>

            <h4>Application Message</h4>

            <p>
                <?= nl2br(e(
                    $application['message']
                    ?: 'No message provided.'
                )) ?>
            </p>

            <?php if ($application['reviewed_at']): ?>
                <p>
                    Reviewed At:
                    <?= e($application['reviewed_at']) ?>
                </p>
            <?php endif; ?>

            <?php if ($application['status'] === 'pending'): ?>

                <h4>Review Application</h4>

                <form
                    method="post"
                    action="review-applications.php"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($_SESSION['csrf_token']) ?>"
                    >

                    <input
                        type="hidden"
                        name="application_id"
                        value="<?= (int)$application['application_id'] ?>"
                    >

                    <button
                        type="submit"
                        name="decision"
                        value="accepted"
                    >
                        Accept Application
                    </button>

                    <button
                        type="submit"
                        name="decision"
                        value="rejected"
                    >
                        Reject Application
                    </button>

                </form>

            <?php else: ?>

                <p>
                    This application has already been processed.
                </p>

            <?php endif; ?>

        </section>

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
