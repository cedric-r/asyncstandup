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

if ($token === '') {
    http_response_code(404);
    echo 'Invalid invitation link.';
    exit;
}

$invitation = getInvitationByToken($pdo, $token);

if ($invitation === null) {
    http_response_code(404);
    echo 'Invitation not found.';
    exit;
}

if ($invitation['accepted_at'] !== null) {
    $message   = 'This invitation has already been accepted.';
    $flash     = null;
    $pageTitle = 'Already Accepted';
    $hideNav   = true;
    $content   = '<div class="card"><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div>';
    include __DIR__ . '/../../templates/layout.php';
    exit;
}

// Expiry check.
$createdAt = new DateTimeImmutable($invitation['created_at'], new DateTimeZone('UTC'));
$expiresAt = $createdAt->modify('+7 days');
$now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));

if ($now > $expiresAt) {
    $pageTitle = 'Invitation Expired';
    $hideNav   = true;
    $content   = '<div class="card"><p>This invitation has expired. Please contact the team owner for a new one.</p></div>';
    include __DIR__ . '/../../templates/layout.php';
    exit;
}

// If user is already logged in — accept immediately.
if (isLoggedIn()) {
    acceptInvitationForUser($pdo, $token, (int) $_SESSION['user_id']);
    setFlash('success', 'You have joined the team: ' . $invitation['team_name']);
    header('Location: /dashboard.php');
    exit;
}

// Check whether an account exists for the invited email.
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([mb_strtolower(trim($invitation['invited_email']))]);
$existingUser = $stmt->fetch();

if ($existingUser !== false) {
    // Existing user — store pending invite in session, then redirect to login.
    // login.php reads and clears this session key after successful authentication.
    $_SESSION['pending_invite_token'] = $token;
    header('Location: /login.php');
    exit;
}

// No account — redirect to registration with email pre-filled.
header('Location: /register.php?email=' . urlencode($invitation['invited_email']) . '&invite=' . urlencode($token));
exit;
