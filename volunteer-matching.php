<?php
declare(strict_types=1);

function matchNormalise(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    return trim((string)$text);
}

function matchSubject(string $topic, string $interests): bool
{
    $topic = matchNormalise($topic);
    if ($topic === '') {
        return false;
    }

    $stopwords = [
        'research', 'study', 'studies', 'student', 'students',
        'and', 'the', 'for', 'with', 'into', 'from', 'about',
        'behaviour', 'behavior', 'general'
    ];

    $topicWords = array_values(array_filter(
        explode(' ', $topic),
        static fn(string $word): bool => strlen($word) >= 4
            && !in_array($word, $stopwords, true)
    ));

    foreach (explode(',', $interests) as $interest) {
        $interest = matchNormalise($interest);
        if ($interest === '') {
            continue;
        }

        if ($interest === $topic ||
            str_contains(' ' . $topic . ' ', ' ' . $interest . ' ') ||
            str_contains(' ' . $interest . ' ', ' ' . $topic . ' ')) {
            return true;
        }

        $interestWords = array_values(array_filter(
            explode(' ', $interest),
            static fn(string $word): bool => strlen($word) >= 4
                && !in_array($word, $stopwords, true)
        ));

        if (count(array_intersect($topicWords, $interestWords)) > 0) {
            return true;
        }
    }

    return false;
}

function matchFormat(string $studyFormat, string $volunteerFormat): bool
{
    if ($volunteerFormat === 'either') {
        return true;
    }

    return $studyFormat === $volunteerFormat;
}

function matchLocation(string $studyLocation, string $volunteerLocation): bool
{
    $study = matchNormalise($studyLocation);
    $volunteer = matchNormalise($volunteerLocation);

    return $study !== '' && $volunteer !== '' && (
        $study === $volunteer ||
        (strlen($study) >= 4 && str_contains($volunteer, $study)) ||
        (strlen($volunteer) >= 4 && str_contains($study, $volunteer))
    );
}

function calculateVolunteerMatch(array $study, array $volunteer): array
{
    $checks = [];
    $interests = trim((string)($volunteer['research_interests'] ?? ''));
    $topic = trim((string)($study['topic'] ?? ''));

    if ($topic !== '' && $interests !== '') {
        $checks[] = [
            'label' => 'Research interests',
            'matched' => matchSubject($topic, $interests)
        ];
    }

    $studyFormat = (string)($study['participation_format'] ?? '');
    $volunteerFormat = (string)($volunteer['preferred_format'] ?? '');

    if ($studyFormat !== '' && $volunteerFormat !== '') {
        $checks[] = [
            'label' => 'Participation format',
            'matched' => matchFormat($studyFormat, $volunteerFormat)
        ];
    }

    if ($studyFormat !== 'online') {
        $studyLocation = trim((string)($study['location'] ?? ''));
        $volunteerLocation = trim((string)($volunteer['location'] ?? ''));
        if ($studyLocation !== '' && $volunteerLocation !== '') {
            $checks[] = [
                'label' => 'Location (text comparison)',
                'matched' => matchLocation($studyLocation, $volunteerLocation)
            ];
        }
    }

    $matched = count(array_filter(
        $checks,
        static fn(array $check): bool => $check['matched']
    ));
    $total = count($checks);

    return [
        'matched' => $matched,
        'total' => $total,
        'percent' => $total > 0 ? (int)round(100 * $matched / $total) : null,
        'checks' => $checks
    ];
}
