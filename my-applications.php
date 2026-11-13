<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('volunteer');
$pdo = getConnection();

$volunteerId = (int)$user['user_id'];

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$notice = $_SESSION['my_applications_notice'] ?? '';
unset($_SESSION['my_applications_notice']);

// Withdraw an existing application.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;

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

    } elseif (!is_int($applicationId)) {
        http_response_code(400);
        $error = 'Invalid application ID.';

    } else {

        try {

            // Only the applicant can withdraw.
            // Pending and accepted applications
            // can be withdrawn.
            $stmt = $pdo->prepare(
                "UPDATE study_applications
                 SET status = 'withdrawn'
                 WHERE application_id = ?
                   AND volunteer_id = ?
                   AND status IN ('pending', 'accepted')"
            );

            $stmt->execute([
                $applicationId,
                $volunteerId
            ]);

            if ($stmt->rowCount() === 1) {

                $_SESSION['my_applications_notice'] =
                    'Application withdrawn successfully.';

                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                header('Location: my-applications.php');
                exit;

            } else {

                $error =
                    'Application cannot be withdrawn or was not found.';
            }

        } catch (Throwable $exception) {

            error_log(
                'Withdrawal error: ' .
                $exception->getMessage()
            );

            $error = 'Unable to withdraw application.';
        }
    }
}

// Fetch applications belonging to this volunteer.
$stmt = $pdo->prepare(
    "SELECT
        a.application_id,
        a.study_id,
        a.message,
        a.status,
        a.applied_at,
        a.reviewed_at,

        s.title AS study_title,
        s.topic,
        s.status AS study_status,
        s.is_test,

        r.first_name AS researcher_first_name,
        r.last_name AS researcher_last_name

     FROM study_applications a

     INNER JOIN studies s
         ON s.study_id = a.study_id

     INNER JOIN users r
         ON r.user_id = s.researcher_id

     WHERE a.volunteer_id = ?

     ORDER BY
         a.applied_at DESC,
         a.application_id DESC"
);

$stmt->execute([$volunteerId]);

$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>My Applications - Research Recruitment</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>My Applications</h2>

<p>
    Welcome, <?= e($user['first_name']) ?>!
</p>

<p>
    Total Applications: <?= count($applications) ?>
</p>

<?php if ($notice !== ''): ?>
    <p role="status"><?= e($notice) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<hr>

<?php if (empty($applications)): ?>

    <p>
        You have not applied to any research studies yet.
    </p>

    <p>
        <a href="browse-studies.php">
            Browse Research Studies
        </a>
    </p>

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

            <p>
                Topic:
                <?= e($application['topic']) ?>
            </p>

            <p>
                Researcher:
                <?= e($application['researcher_first_name']) ?>
                <?= e($application['researcher_last_name']) ?>
            </p>

            <p>
                Study Status:
                <?= e($application['study_status']) ?>
            </p>

            <p>
                Applied At:
                <?= e($application['applied_at']) ?>
            </p>

            <p>
                Application Status:
                <strong>
                    <?= e(ucfirst($application['status'])) ?>
                </strong>
            </p>

            <?php if ($application['reviewed_at']): ?>
                <p>
                    Reviewed At:
                    <?= e($application['reviewed_at']) ?>
                </p>
            <?php endif; ?>

            <h4>Your Application Message</h4>

            <p>
                <?= nl2br(e(
                    $application['message']
                    ?: 'No message provided.'
                )) ?>
            </p>

            <?php if ($application['status'] === 'pending'): ?>

                <p>
                    Your application is awaiting review.
                </p>

            <?php elseif ($application['status'] === 'accepted'): ?>

                <p>
                    Your application has been accepted
                    by the researcher.
                </p>

            <?php elseif ($application['status'] === 'rejected'): ?>

                <p>
                    Your application was not accepted.
                </p>

            <?php elseif ($application['status'] === 'withdrawn'): ?>

                <p>
                    You have withdrawn this application.
                </p>

            <?php endif; ?>

            <?php if (in_array(
                $application['status'],
                ['pending', 'accepted'],
                true
            )): ?>

                <h4>Withdraw Application</h4>

                <form
                    method="post"
                    action="my-applications.php"
                    onsubmit="return confirm(
                        'Are you sure you want to withdraw this application?'
                    );"
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

                    <button type="submit">
                        Withdraw Application
                    </button>

                </form>

            <?php endif; ?>

        </section>

        <hr>

    <?php endforeach; ?>

<?php endif; ?>

<p>
    <a href="browse-studies.php">
        Browse Research Studies
    </a>
</p>

<p>
    <a href="volunteer-dashboard.php">
        Back to Volunteer Dashboard
    </a>
</p>

</body>
</html>
