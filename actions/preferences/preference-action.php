<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/core/auth.php';

$user = requireLogin();
$pdo = getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$token = $_POST['csrf_token'] ?? null;
if (!is_string($token) || empty($_SESSION['preference_csrf'])
    || !hash_equals($_SESSION['preference_csrf'], $token)) {
    http_response_code(403);
    exit('Invalid request.');
}

$kind = $_POST['kind'] ?? null;
$action = $_POST['action'] ?? null;
$rawId = $_POST['item_id'] ?? null;
$page = $_POST['return_page'] ?? null;

if (!is_string($kind) || !in_array($kind, ['study', 'volunteer'], true)
    || !is_string($action) || !in_array($action, ['save', 'hide', 'clear'], true)
    || !is_string($rawId) || !ctype_digit($rawId)
    || (int)$rawId < 1 || !is_string($page)) {
    http_response_code(400);
    exit('Invalid preference.');
}

$allowedPages = $kind === 'study'
    ? ['browse-studies.php']
    : ['find-volunteers.php'];
if (!in_array($page, $allowedPages, true)) {
    http_response_code(400);
    exit('Invalid return page.');
}

$expectedRole = $kind === 'study' ? 'volunteer' : 'academic';
if ($user['role'] !== $expectedRole) {
    http_response_code(403);
    exit('Access denied.');
}

$userId = (int)$user['user_id'];
$itemId = (int)$rawId;

if ($kind === 'volunteer') {
    $verification = $pdo->prepare(
        'SELECT verification_status FROM academic_profiles WHERE user_id = ?'
    );
    $verification->execute([$userId]);
    if ($verification->fetchColumn() !== 'verified') {
        http_response_code(403);
        exit('Researcher verification required.');
    }
}

if ($action !== 'clear') {
    if ($kind === 'study') {
        $stmt = $pdo->prepare(
            "SELECT s.study_id FROM studies s
             JOIN users u ON u.user_id = s.researcher_id
             JOIN academic_profiles ap ON ap.user_id = u.user_id
             WHERE s.study_id = ? AND s.status = 'open'
               AND u.role = 'academic' AND ap.verification_status = 'verified'
               AND s.application_deadline >= CURDATE()
               AND s.end_date >= CURDATE()"
        );
        $stmt->execute([$itemId]);
        if ($stmt->fetchColumn() === false) {
            http_response_code(403);
            exit('Study unavailable.');
        }
    } else {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM users u
             INNER JOIN volunteer_profiles vp ON vp.user_id = u.user_id
             WHERE u.user_id = ? AND u.role = 'volunteer'
               AND vp.visible_to_researchers = 1"
        );
        $stmt->execute([$itemId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            exit('Volunteer unavailable.');
        }
    }
}

if ($kind === 'study') {
    if ($action === 'clear') {
        $stmt = $pdo->prepare(
            'DELETE FROM volunteer_study_preferences WHERE volunteer_id = ? AND study_id = ?'
        );
        $stmt->execute([$userId, $itemId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO volunteer_study_preferences
             (volunteer_id, study_id, preference_status) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE preference_status = VALUES(preference_status)'
        );
        $stmt->execute([$userId, $itemId, $action === 'save' ? 'saved' : 'hidden']);
    }
} else {
    if ($action === 'clear') {
        $stmt = $pdo->prepare(
            'DELETE FROM academic_volunteer_preferences WHERE researcher_id = ? AND volunteer_id = ?'
        );
        $stmt->execute([$userId, $itemId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO academic_volunteer_preferences
             (researcher_id, volunteer_id, preference_status) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE preference_status = VALUES(preference_status)'
        );
        $stmt->execute([$userId, $itemId, $action === 'save' ? 'saved' : 'hidden']);
    }
}

$_SESSION['preference_notice'] = match ($action) {
    'save' => 'Item saved.',
    'hide' => 'Item hidden. You can restore it from the Hidden view.',
    default => 'Preference removed.'
};

$query = [];
$rawQuery = $_POST['return_query'] ?? '';
if (is_string($rawQuery) && strlen($rawQuery) < 1200) {
    parse_str($rawQuery, $parsed);
    $allowed = $kind === 'study'
        ? ['keyword', 'location', 'format', 'sort', 'view']
        : ['study_id', 'keyword', 'location', 'availability', 'format', 'sort', 'view', 'within_radius'];
    foreach ($allowed as $key) {
        if (isset($parsed[$key]) && is_string($parsed[$key]) && strlen($parsed[$key]) <= 150) {
            $query[$key] = $parsed[$key];
        }
    }
}

$redirect = $kind === 'study'
    ? '../../pages/volunteer/browse-studies.php'
    : '../../pages/researcher/find-volunteers.php';
if ($query !== []) {
    $redirect .= '?' . http_build_query($query);
}
header('Location: ' . $redirect, true, 303);
exit;
