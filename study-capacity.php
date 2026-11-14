<?php
declare(strict_types=1);

function getStudyCapacity(PDO $pdo, int $studyId, bool $lock = false): ?array
{
    if ($lock && !$pdo->inTransaction()) {
        throw new LogicException('A transaction is required to lock study capacity.');
    }

    $sql = 'SELECT participant_capacity FROM studies WHERE study_id = ?';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $studyStmt = $pdo->prepare($sql);
    $studyStmt->execute([$studyId]);
    $study = $studyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$study) {
        return null;
    }

    $acceptedStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT volunteer_id
            FROM study_applications
            WHERE study_id = ? AND status = 'accepted'
            UNION
            SELECT volunteer_id
            FROM participation_requests
            WHERE study_id = ? AND status = 'accepted'
        ) AS accepted_volunteers"
    );
    $acceptedStmt->execute([$studyId, $studyId]);

    $accepted = (int)$acceptedStmt->fetchColumn();
    $capacity = $study['participant_capacity'] === null
        ? null
        : (int)$study['participant_capacity'];

    return [
        'capacity' => $capacity,
        'accepted' => $accepted,
        'remaining' => $capacity === null ? null : max(0, $capacity - $accepted)
    ];
}
