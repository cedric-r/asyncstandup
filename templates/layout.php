<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'AsyncStandUp', ENT_QUOTES, 'UTF-8') ?> — AsyncStandUp</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<?php if (!isset($hideNav) || !$hideNav): ?>
<nav>
    <a href="/dashboard.php"><strong>AsyncStandUp</strong></a>
    <a href="/orgs/index.php">Organisations</a>
    <?php if (isset($currentUser)): ?>
    <span class="nav-right">
        <a href="/profile.php"><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['email'], ENT_QUOTES, 'UTF-8') ?></a>
        <a href="/logout.php">Log out</a>
    </span>
    <?php endif; ?>
</nav>
<?php endif; ?>

<div class="container">

<?php if (isset($flash)): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type'], ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<?= $content ?? '' ?>

</div>
</body>
</html>
