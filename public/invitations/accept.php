<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/InvitationRepository.php';

startSession();

$pdo   = getDb($config);
$token = trim($_GET['token'] ?? '');

function showMsg(string $msg, string $title): never {
    $content = '<div class="max-w-md mx-auto bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">'
             . '<p class="text-gray-700">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div>';
    $GLOBALS['pageTitle'] = $title;
    $GLOBALS['flash']     = null;
    include __DIR__ . '/../../templates/layout.php';
    exit;
}

if ($token === '') { showMsg('Invalid invitation link.', 'Invalid'); }
$invitation = getInvitationByToken($pdo, $token);
if ($invitation === null) { showMsg('Invitation not found.', 'Invalid'); }
if ($invitation['accepted_at'] !== null) { showMsg('This invitation has already been accepted.', 'Already Accepted'); }

$createdAt = new DateTimeImmutable($invitation['created_at'], new DateTimeZone('UTC'));
$expiresAt = $createdAt->modify('+7 days');
$now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
if ($now > $expiresAt) { showMsg('This invitation has expired. Please contact the team owner for a new one.', 'Expired'); }

if (isLoggedIn()) {
    acceptInvitationForUser($pdo, $token, (int) $_SESSION['user_id']);
    setFlash('success', 'You have joined the team: ' . $invitation['team_name']);
    header('Location: /dashboard.php');
    exit;
}

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([mb_strtolower(trim($invitation['invited_email']))]);
$existingUser = $stmt->fetch();

if ($existingUser !== false) {
    $_SESSION['pending_invite_token'] = $token;
    header('Location: /login.php');
    exit;
}

header('Location: /register.php?email=' . urlencode($invitation['invited_email']) . '&invite=' . urlencode($token));
exit;
