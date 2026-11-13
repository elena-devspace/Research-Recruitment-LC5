<?php
declare(strict_types=1);

/**
 * Run an application or invitation insertion safely.
 *
 * Both submission pages must use this function.
 * Returns null on success or a conflict type.
 */
function runRecruitmentWrite(
    PDO $pdo,
    int $studyId,
    int $volunteerId,
    callable $write
): ?string {

    $pdo->beginTransaction();

    try {
        // Lock the shared study record.
        $lock = $pdo->prepare("
            SELECT study_id
            FROM studies
            WHERE study_id = ?
            FOR UPDATE
        ");

        $lock->execute([$studyId]);

        if ($lock->fetchColumn() === false) {
            $pdo->rollBack();
            return 'study_missing';
        }

        // Check for an existing application.
        $application = $pdo->prepare("
            SELECT application_id
            FROM study_applications
            WHERE study_id = ?
              AND volunteer_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $application->execute([$studyId, $volunteerId]);

        if ($application->fetchColumn() !== false) {
            $pdo->rollBack();
            return 'application';
        }

        // Check for an existing invitation.
        $invitation = $pdo->prepare("
            SELECT request_id
            FROM participation_requests
            WHERE study_id = ?
              AND volunteer_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $invitation->execute([$studyId, $volunteerId]);

        if ($invitation->fetchColumn() !== false) {
            $pdo->rollBack();
            return 'invitation';
        }

        // Perform the insert while holding the lock.
        $write();

        $pdo->commit();

        return null;

    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
