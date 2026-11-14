<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/study-capacity.php';

$user = requireRole('academic');
$deletedTitle = $_SESSION['deleted_study_title'] ?? null;
unset($_SESSION['deleted_study_title']);
$pdo = getConnection();

$closedStudyId = $_SESSION['closed_study_id'] ?? null;
unset($_SESSION['closed_study_id']);

$verificationStmt = $pdo->prepare(
    "SELECT verification_status
     FROM academic_profiles
     WHERE user_id = ?"
);

$verificationStmt->execute([$user['user_id']]);

$canPublish =
    $verificationStmt->fetchColumn() === 'verified';

$publishedId = $_SESSION['published_study_id'] ?? null;
unset($_SESSION['published_study_id']);

function e(?string $value): string {
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Only retrieve studies belonging to this researcher.
$stmt = $pdo->prepare(
    "SELECT *
     FROM studies
     WHERE researcher_id = :researcher_id
     ORDER BY created_at DESC, study_id DESC"
);

$stmt->execute([
    ':researcher_id' => $user['user_id']
]);

$studies = $stmt->fetchAll();
$capacityByStudy = [];
foreach ($studies as $study) {
    $studyId = (int)$study['study_id'];
    $capacityByStudy[$studyId] = getStudyCapacity($pdo, $studyId);
}

$questionStmt = $pdo->prepare(
    'SELECT q.study_id, q.question_text, q.required_answer
     FROM study_eligibility_questions q
     INNER JOIN studies s ON s.study_id = q.study_id
     WHERE s.researcher_id = ?
     ORDER BY q.study_id, q.position'
);
$questionStmt->execute([$user['user_id']]);
$questionsByStudy = [];
foreach ($questionStmt->fetchAll(PDO::FETCH_ASSOC) as $question) {
    $questionsByStudy[(int)$question['study_id']][] = $question;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>My Research Studies</title>
</head>
<body>

<h1>Research Recruitment</h1>
<h2>My Research Studies</h2>

<?php if (is_int($closedStudyId)): ?>
    <p role="status">
        Study #<?= $closedStudyId ?>
        closed successfully!
    </p>
<?php endif; ?>

<?php if (is_int($publishedId)): ?>
    <p role="status">
        Study #<?= $publishedId ?>
        published successfully!
    </p>
<?php endif; ?>

<?php if (is_string($deletedTitle)): ?>
    <p role="status">
        Draft "<?= e($deletedTitle) ?>"
        deleted successfully!
    </p>
<?php endif; ?>

<p>
    Welcome, <?= e($user['first_name']) ?>!
</p>

<p>
    <a href="create-study.php">
        Create New Study
    </a>
</p>

<?php if (empty($studies)): ?>

    <p>You haven't created any research studies yet.</p>

<?php else: ?>

    <p>
        Total studies: <?= count($studies) ?>
    </p>

    <?php foreach ($studies as $study): ?>

        <article>

            <h3>
                <?= e($study['title']) ?>
            </h3>

            <p>
                Study ID:
                <?= (int)$study['study_id'] ?>
            </p>

            <p>
                Status:
                <?= e($study['status']) ?>
            </p>

            <p>
                Topic:
                <?= e($study['topic']) ?>
            </p>

            <p>
                Participation format:
                <?= e($study['participation_format']) ?>
            </p>

            <p>
                Start date:
                <?= e($study['start_date'] ?? 'Not set') ?>
            </p>

            <p>
                End date:
                <?= e($study['end_date'] ?? 'Not set') ?>
            </p>

            <details>
                <summary>View Study Details</summary>

                <h4>Research Aim</h4>
                <p><?= nl2br(e($study['aim'])) ?></p>

                <h4>Description</h4>
                <p><?= nl2br(e($study['description'])) ?></p>

                <h4>Participant Tasks</h4>
                <p><?= nl2br(e($study['participation_tasks'])) ?></p>

                <h4>Eligibility Requirements</h4>
                <p><?= nl2br(e($study['eligibility_requirements'])) ?></p>

                <h4>Matching Requirements</h4>
                <p>Availability: <?= e(match ((string)($study['required_availability'] ?? '')) {
                    'weekdays' => 'Weekdays',
                    'weekends' => 'Weekends',
                    'both' => 'Both weekdays and weekends',
                    default => 'Not specified'
                }) ?></p>
                <p>Age range: <?= e($study['minimum_age'] ?? 'Not specified') ?> to <?= e($study['maximum_age'] ?? 'No upper limit') ?></p>

                <h4>Eligibility Screening Questions</h4>
                <?php $screeningQuestions = $questionsByStudy[(int)$study['study_id']] ?? []; ?>
                <?php if (empty($screeningQuestions)): ?>
                    <p>None specified.</p>
                <?php else: ?>
                    <ol>
                        <?php foreach ($screeningQuestions as $question): ?>
                            <li>
                                <?= e($question['question_text']) ?>
                                — Required: <?= e(ucfirst($question['required_answer'])) ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <?php $capacity = $capacityByStudy[(int)$study['study_id']]; ?>
                <h4>Participant Capacity</h4>
                <p><?= $capacity['capacity'] === null ? 'No limit set' : $capacity['capacity'] ?></p>
                <p>Accepted participants: <?= $capacity['accepted'] ?></p>
                <p>
                    Spaces remaining:
                    <?= $capacity['remaining'] === null ? 'No limit set' : $capacity['remaining'] ?>
                </p>

                <h4>Location</h4>
                <p><?= e($study['location'] ?? 'Not specified') ?></p>

                <h4>Application Deadline</h4>
                <p><?= e(
                    $study['application_deadline']
                    ?? 'Not specified'
                ) ?></p>

                <h4>Reimbursement</h4>
                <p>
                    £<?= number_format(
                        (float)$study['reimbursement_amount'],
                        2
                    ) ?>
                </p>

                <p>
                    <?= e(
                        $study['reimbursement_details']
                        ?? ''
                    ) ?>
                </p>

                <h4>Anonymous Participation</h4>
                <p>
                    <?= $study['anonymous_participation_allowed']
                        ? 'Allowed' : 'Not allowed' ?>
                </p>

            </details>

            
            <?php if ($study['status'] === 'draft'): ?>
                <p>
                    <a href="edit-study.php?id=<?= (int)$study['study_id'] ?>">
                        Edit Study
                    </a>
                </p>
            <?php endif; ?>


            <?php if (
                $study['status'] === 'draft' &&
                $canPublish
            ): ?>
                <p>
                    <a href="publish-study.php?id=<?= (int)$study['study_id'] ?>">
                        Publish Study
                    </a>
                </p>
            <?php endif; ?>

            
            <?php if ($study['status'] === 'draft'): ?>
                <p>
                    <a href="delete-study.php?id=<?= (int)$study['study_id'] ?>">
                        Delete Draft
                    </a>
                </p>
            <?php endif; ?>

            <?php if ($study['status'] === 'open'): ?>
                <p>
                    <a href="close-study.php?id=<?= (int)$study['study_id'] ?>">
                        Close Study
                    </a>
                </p>
            <?php endif; ?>


        </article>

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
