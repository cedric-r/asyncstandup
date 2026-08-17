<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$userId = (int) $_SESSION['user_id'];

$newRawKey = null; // shown once after generation

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');

    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $rawKey  = bin2hex(random_bytes(32));
        $keyHash = hash('sha256', $rawKey);
        $label   = trim($_POST['label'] ?? '');
        $label   = $label !== '' ? substr($label, 0, 100) : null;

        $pdo->prepare(
            'INSERT INTO api_keys (user_id, key_hash, label, created_at) VALUES (?, ?, ?, ?)'
        )->execute([$userId, $keyHash, $label, gmdate('Y-m-d H:i:s')]);

        $newRawKey = $rawKey; // display once
    } elseif ($action === 'revoke') {
        $keyId = (int) ($_POST['key_id'] ?? 0);
        if ($keyId > 0) {
            $pdo->prepare('DELETE FROM api_keys WHERE id = ? AND user_id = ?')
                ->execute([$keyId, $userId]);
        }
    }
}

$csrfToken = generateCsrfToken();

$keys = $pdo->prepare('SELECT id, label, created_at, last_used_at FROM api_keys WHERE user_id = ? ORDER BY created_at DESC');
$keys->execute([$userId]);
$keys = $keys->fetchAll();

$currentPage = 'profile';

ob_start();
?>
<div class="max-w-2xl mx-auto mt-8">
  <h1 class="text-2xl font-bold text-gray-800 mb-6">API Keys</h1>

  <?php if ($newRawKey !== null): ?>
  <div class="mb-6 bg-amber-50 border border-amber-300 rounded-xl p-4">
    <p class="text-sm font-medium text-amber-800 mb-2">⚠️ This key will only be shown once. Copy it now.</p>
    <code class="block text-sm font-mono bg-white border border-amber-200 rounded px-3 py-2 break-all text-gray-800"><?= htmlspecialchars($newRawKey, ENT_QUOTES, 'UTF-8') ?></code>
  </div>
  <?php endif; ?>

  <!-- Generate new key -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Generate a new API key</h2>
    <form method="POST" action="/profile/api-keys.php" class="flex gap-2 items-end">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="generate">
      <div class="flex-1">
        <label class="block text-sm font-medium text-gray-700 mb-1">Label <span class="text-gray-400 font-normal">(optional)</span></label>
        <input type="text" name="label" maxlength="100" placeholder="e.g. CI pipeline"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
      </div>
      <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm">Generate</button>
    </form>
  </div>

  <!-- Existing keys -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Your API keys</h2>
    <?php if (empty($keys)): ?>
      <p class="text-sm text-gray-500">No API keys yet.</p>
    <?php else: ?>
    <ul class="divide-y divide-gray-100">
      <?php foreach ($keys as $k): ?>
      <li class="py-3 flex items-center justify-between gap-4">
        <div>
          <p class="text-sm font-medium text-gray-800"><?= $k['label'] !== null ? htmlspecialchars((string) $k['label'], ENT_QUOTES, 'UTF-8') : '<span class="text-gray-400 italic">Unlabelled</span>' ?></p>
          <p class="text-xs text-gray-400 mt-0.5">
            Created <?= htmlspecialchars((string) $k['created_at'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($k['last_used_at'] !== null): ?>
              &nbsp;·&nbsp; Last used <?= htmlspecialchars((string) $k['last_used_at'], ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
          </p>
        </div>
        <form method="POST" action="/profile/api-keys.php">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="key_id" value="<?= (int) $k['id'] ?>">
          <button type="submit" onclick="return confirm('Revoke this key? This cannot be undone.')"
                  class="text-xs text-red-500 hover:text-red-700">Revoke</button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'API Keys';
include __DIR__ . '/../../templates/layout.php';
