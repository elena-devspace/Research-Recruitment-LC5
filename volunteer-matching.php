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


function matchStudyAvailability(string $requirement, string $availability): ?bool
{
    $availability = strtolower($availability);
    if (trim($availability) === '') {
        return null;
    }
    $hasWeekdays = str_contains($availability, 'weekday');
    $hasWeekends = str_contains($availability, 'weekend');
    if (!$hasWeekdays && !$hasWeekends) {
        return null;
    }
    return match ($requirement) {
        'weekdays' => $hasWeekdays,
        'weekends' => $hasWeekends,
        'both' => $hasWeekdays && $hasWeekends,
        default => null
    };
}

function matchStudyAge(?int $minimum, ?int $maximum, string $ageRange): ?bool
{
    if (!preg_match('/^(\d{2})-(\d{2})$/D', $ageRange, $parts)
        && !preg_match('/^(\d{2})\+$/D', $ageRange, $parts)) {
        return null;
    }
    $lower = (int)$parts[1];
    $upper = str_ends_with($ageRange, '+') ? null : (int)$parts[2];
    if ($maximum !== null && $lower > $maximum) {
        return false;
    }
    if ($upper !== null && $minimum !== null && $upper < $minimum) {
        return false;
    }
    if (($minimum === null || $lower >= $minimum)
        && ($maximum === null || ($upper !== null && $upper <= $maximum))) {
        return true;
    }
    return null;
}

function calculateVolunteerMatch(array $study, array $volunteer): array
{
    $checks = [];
    $unknown = [];
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

    $dayRequirement = (string)($study['required_availability'] ?? '');
    if ($dayRequirement !== '') {
        $dayMatch = matchStudyAvailability($dayRequirement, (string)($volunteer['availability'] ?? ''));
        if ($dayMatch === null) {
            $unknown[] = 'Availability not specified or unrecognised';
        } else {
            $checks[] = ['label' => 'Required availability', 'matched' => $dayMatch];
        }
    }

    $minimum = $study['minimum_age'] === null ? null : (int)$study['minimum_age'];
    $maximum = $study['maximum_age'] === null ? null : (int)$study['maximum_age'];
    if ($minimum !== null || $maximum !== null) {
        $ageRange = (string)($volunteer['age_range'] ?? '');
        $ageMatch = matchStudyAge($minimum, $maximum, $ageRange);
        if ($ageMatch === null) {
            $unknown[] = 'Age bracket missing or overlaps a study age boundary';
        } else {
            $checks[] = ['label' => 'Age range estimate', 'matched' => $ageMatch];
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
        'checks' => $checks,
        'unknown' => $unknown
    ];
}
