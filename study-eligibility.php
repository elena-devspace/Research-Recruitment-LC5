<?php
declare(strict_types=1);

const MAX_ELIGIBILITY_QUESTIONS = 5;

function eligibilityFormRows(array $questions): array
{
    $rows = array_fill(0, MAX_ELIGIBILITY_QUESTIONS, [
        'question_text' => '',
        'required_answer' => 'yes'
    ]);

    foreach ($questions as $question) {
        $position = (int)($question['position'] ?? 0);
        if ($position >= 1 && $position <= MAX_ELIGIBILITY_QUESTIONS) {
            $rows[$position - 1] = [
                'question_text' => (string)$question['question_text'],
                'required_answer' => (string)$question['required_answer']
            ];
        }
    }

    return $rows;
}

function submittedEligibilityRows(): array
{
    $texts = $_POST['eligibility_questions'] ?? null;
    $answers = $_POST['eligibility_answers'] ?? null;
    $rows = eligibilityFormRows([]);
    $errors = [];

    if (!is_array($texts) || !is_array($answers)) {
        return [$rows, ['Invalid eligibility questions.']];
    }

    foreach ([$texts, $answers] as $values) {
        foreach (array_keys($values) as $index) {
            if (!is_int($index) || $index < 0 || $index >= MAX_ELIGIBILITY_QUESTIONS) {
                return [$rows, ['Invalid eligibility questions.']];
            }
        }
    }

    $seen = [];

    for ($i = 0; $i < MAX_ELIGIBILITY_QUESTIONS; $i++) {
        $text = $texts[$i] ?? '';
        $answer = $answers[$i] ?? 'yes';

        if (!is_string($text) || !is_string($answer)) {
            $errors[] = 'Invalid eligibility question ' . ($i + 1) . '.';
            continue;
        }

        $text = trim($text);
        $rows[$i] = [
            'question_text' => $text,
            'required_answer' => $answer
        ];

        if (mb_strlen($text) > 255) {
            $errors[] = 'Eligibility question ' . ($i + 1) . ' is too long.';
        }
        if (!in_array($answer, ['yes', 'no'], true)) {
            $errors[] = 'Invalid required answer for question ' . ($i + 1) . '.';
        }
        if ($text !== '') {
            $key = mb_strtolower($text);
            if (isset($seen[$key])) {
                $errors[] = 'Eligibility questions must be different.';
            }
            $seen[$key] = true;
        }
    }

    return [$rows, $errors];
}

function loadStudyEligibilityQuestions(PDO $pdo, int $studyId): array
{
    $stmt = $pdo->prepare(
        'SELECT position, question_text, required_answer
         FROM study_eligibility_questions
         WHERE study_id = ?
         ORDER BY position'
    );
    $stmt->execute([$studyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function replaceStudyEligibilityQuestions(PDO $pdo, int $studyId, array $rows): void
{
    $delete = $pdo->prepare(
        'DELETE FROM study_eligibility_questions WHERE study_id = ?'
    );
    $delete->execute([$studyId]);

    $insert = $pdo->prepare(
        'INSERT INTO study_eligibility_questions
         (study_id, position, question_text, required_answer)
         VALUES (?, ?, ?, ?)'
    );

    foreach ($rows as $index => $row) {
        if ($row['question_text'] !== '') {
            $insert->execute([
                $studyId,
                $index + 1,
                $row['question_text'],
                $row['required_answer']
            ]);
        }
    }
}
