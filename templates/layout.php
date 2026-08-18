<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle ?? 'AsyncStandUp', ENT_QUOTES, 'UTF-8') ?> — AsyncStandUp</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">

<nav class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-50 text-gray-900">
  <div class="max-w-5xl mx-auto px-4">
    <div class="flex items-center justify-between h-14">

      <!-- Logo + main links -->
      <div class="flex items-center gap-6">
        <a href="<?= isset($currentUser) ? '/dashboard.php' : '/login.php' ?>" class="font-bold text-indigo-600 text-lg tracking-tight">AsyncStandUp</a>
        <?php if (isset($currentUser)): ?>
        <a href="/orgs/index.php" class="text-sm text-gray-600 hover:text-indigo-600">Organisations</a>
        <?php endif; ?>
      </div>

      <!-- Right side: context-aware (authenticated / unauthenticated) -->
      <?php if (isset($currentUser)): ?>
      <div class="flex items-center gap-4 text-sm">
        <?php if (!empty($_SESSION['is_admin'])): ?>
        <a href="/admin/users.php" class="text-amber-600 hover:text-amber-700 font-medium">Admin</a>
        <a href="/admin/teams.php" class="text-amber-600 hover:text-amber-700 font-medium">Teams</a>
        <?php endif; ?>
        <a href="/profile.php" class="text-gray-600 hover:text-indigo-600">
          <?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['email'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="/settings/api-keys.php" class="text-gray-600 hover:text-indigo-600">API Keys</a>
        <a href="/logout.php" class="text-gray-400 hover:text-gray-600">Log out</a>
      </div>
      <?php else: ?>
      <div class="flex items-center gap-4 text-sm">
        <a href="/login.php" class="text-gray-500 hover:text-indigo-600">Log in</a>
        <a href="/register.php" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium py-1.5 px-3 rounded-lg">Register</a>
      </div>
      <?php endif; ?>

    </div>
  </div>
</nav>

<main class="max-w-5xl mx-auto px-4 py-8">

<?php if (isset($flash) && $flash !== null): ?>
<?php
  $flashClasses = match($flash['type']) {
      'success' => 'bg-green-50 border-green-200 text-green-800',
      'error'   => 'bg-red-50 border-red-200 text-red-800',
      default   => 'bg-amber-50 border-amber-200 text-amber-800',
  };
?>
<div class="border <?= $flashClasses ?> px-4 py-3 rounded-lg mb-6 text-sm">
  <?= htmlspecialchars($flash['text'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<?= $content ?? '' ?>

</main>
</body>
</html>
