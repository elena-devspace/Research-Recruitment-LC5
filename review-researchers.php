<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$user = requireRole('admin');
$pdo = getConnection();

function e($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$message = $_SESSION['review_message'] ?? '';
unset($_SESSION['review_message']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? null;
    $decision = $_POST['decision'] ?? null;

    $researcherId = filter_input(
        INPUT_POST,
        'researcher_id',
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
        !is_string($decision) ||
        !in_array(
            $decision,
            ['verified', 'rejected'],
            true
        ) ||
        !is_int($researcherId)
    ) {
        http_response_code(400);
        $error = 'Invalid verification request.';

    } else {

        try {
            $stmt = $pdo->prepare(
                "UPDATE academic_profiles ap
                 INNER JOIN users u
                    ON u.user_id = ap.user_id
                 SET
                    ap.verification_status = :status,
                    ap.verified_at =
                        CASE
                            WHEN :approved = 1
                            THEN NOW()
                            ELSE NULL
                        END
                 WHERE ap.user_id = :researcher_id
                   AND ap.verification_status = 'pending'
                   AND u.role = 'academic'"
            );

            $stmt->execute([
                ':status' => $decision,
                ':approved' =>
                    $decision === 'verified' ? 1 : 0,
                ':researcher_id' => $researcherId
            ]);

            if ($stmt->rowCount() === 1) {

                $_SESSION['review_message'] =
                    $decision === 'verified'
                        ? 'Researcher approved successfully.'
                        : 'Researcher rejected successfully.';

                $_SESSION['csrf_token'] =
                    bin2hex(random_bytes(32));

                header('Location: review-researchers.php');
                exit;

            } else {
                $error =
                    'Researcher not found or already reviewed.';
            }

        } catch (Throwable $exception) {
            error_log(
                'Researcher verification error: ' .
                $exception->getMessage()
            );

            $error = 'Unable to update researcher status.';
        }
    }

} elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit('Method not allowed.');
}

// Retrieve pending academics only.
$stmt = $pdo->query(
    "SELECT
        u.user_id,
        u.first_name,
        u.last_name,
        u.email,
        ap.institution,
        ap.department,
        ap.research_interests,
        ap.verification_status
     FROM academic_profiles ap
     INNER JOIN users u
         ON u.user_id = ap.user_id
     WHERE u.role = 'academic'
       AND ap.verification_status = 'pending'
     ORDER BY u.user_id ASC"
);

$researchers = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>Review Researchers</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>Researcher Verification</h2>

<p>Administrator: <?= e($user['first_name']) ?></p>

<p>
    Review researcher information before approving
    access to publication features.
</p>

<p>
    Institutional details supplied by users
    must be checked independently.
</p>

<?php if ($message !== ''): ?>
    <p role="status"><?= e($message) ?></p>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<h3>Pending Researchers</h3>

<p>
    Awaiting review: <?= count($researchers) ?>
</p>

<?php if (empty($researchers)): ?>

    <p>No researchers are awaiting verification.</p>

<?php else: ?>

    <?php foreach ($researchers as $researcher): ?>

        <section>

            <h3>
                <?= e($researcher['first_name']) ?>
                <?= e($researcher['last_name']) ?>
            </h3>

            <p>
                User ID:
                <?= (int)$researcher['user_id'] ?>
            </p>

            <p>
                Email:
                <?= e($researcher['email']) ?>
            </p>

            <p>
                Institution:
                <?= e($researcher['institution']) ?>
            </p>

            <p>
                Department:
                <?= e($researcher['department']) ?>
            </p>

            <p>
                Research interests:
                <?= e($researcher['research_interests']) ?>
            </p>

            <p>
                Status:
                <?= e($researcher['verification_status']) ?>
            </p>

            <form method="post"
                  action="review-researchers.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($_SESSION['csrf_token']) ?>"
                >

                <input
                    type="hidden"
                    name="researcher_id"
                    value="<?= (int)$researcher['user_id'] ?>"
                >

                <button type="submit"
                        name="decision"
                        value="verified">
                    Approve Researcher
                </button>

                <button type="submit"
                        name="decision"
                        value="rejected">
                    Reject Researcher
                </button>

            </form>

        </section>

        <hr>

    <?php endforeach; ?>

<?php endif; ?>

<p>
    <a href="admin-dashboard.php">
        Back to Administrator Dashboard
    </a>
</p>

</body>
</html>
