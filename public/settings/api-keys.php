<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/ApiKeyRepository.php';

startSession();
requireLogin();

$pdo    = getDb($config);
$userId = (int) $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Key name is required.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Key name must be 100 characters or fewer.';
        } else {
            $rawKey = createApiKey($pdo, $userId, $name);
            setFlash('api_key_created', $rawKey);
            header('Location: /settings/api-keys.php');
            exit;
        }
    } elseif ($action === 'revoke') {
        $keyId = (int) ($_POST['key_id'] ?? 0);
        if ($keyId > 0) {
            revokeApiKey($pdo, $keyId, $userId);
        }
        setFlash('success', 'API key revoked.');
        header('Location: /settings/api-keys.php');
        exit;
    }
}

$keys        = listApiKeysForUser($pdo, $userId);
$currentUser = getCurrentUser($pdo);
$csrfToken   = generateCsrfToken();

// Retrieve one-time flash (raw key shown once, or generic success/error).
$flash  = getFlash();
$newKey = ($flash !== null && $flash['type'] === 'api_key_created') ? $flash['text'] : null;
$flashSuccess = ($flash !== null && $flash['type'] === 'success') ? $flash['text'] : null;

ob_start();
?>
<div class="max-w-2xl mx-auto mt-8">
  <h1 class="text-2xl font-bold text-gray-800 mb-6">API Keys</h1>

  <?php if ($newKey !== null): ?>
  <div class="bg-green-50 border border-green-300 rounded-lg p-4 mb-6">
    <p class="font-semibold text-green-800 mb-1">New API key created — copy it now, it will not be shown again.</p>
    <code class="block text-sm font-mono bg-white border border-green-200 rounded p-2 text-green-900 select-all"><?= htmlspecialchars($newKey, ENT_QUOTES, 'UTF-8') ?></code>
  </div>
  <?php endif; ?>

  <?php if ($flashSuccess !== null): ?>
  <div class="bg-green-50 border border-green-200 rounded-lg p-3 mb-4 text-sm text-green-800">
    <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
  <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
    <?php foreach ($errors as $e): ?>
      <p class="text-sm text-red-700"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Create new key form -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Create a new API key</h2>
    <form method="POST" action="/settings/api-keys.php" class="flex gap-2 items-end">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="create">
      <div class="flex-1">
        <label for="key-name" class="block text-sm font-medium text-gray-700 mb-1">
          Key name <span class="text-red-500">*</span>
        </label>
        <input type="text" id="key-name" name="name" maxlength="100" required
               placeholder="e.g. CI pipeline"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
      </div>
      <button type="submit"
              class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg text-sm whitespace-nowrap">
        Create key
      </button>
    </form>
  </div>

  <!-- Existing keys -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Your API keys</h2>
    <?php if (empty($keys)): ?>
      <p class="text-sm text-gray-500">No API keys yet. Create one above.</p>
    <?php else: ?>
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-gray-100">
          <th class="text-left font-medium text-gray-600 pb-2">Name</th>
          <th class="text-left font-medium text-gray-600 pb-2">Key preview</th>
          <th class="text-left font-medium text-gray-600 pb-2">Created</th>
          <th class="text-left font-medium text-gray-600 pb-2">Last used</th>
          <th></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($keys as $key): ?>
        <tr>
          <td class="py-2 pr-3 font-medium text-gray-800">
            <?= htmlspecialchars((string) $key['name'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td class="py-2 pr-3 font-mono text-gray-500">
            <?= htmlspecialchars((string) $key['masked_key'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td class="py-2 pr-3 text-gray-600">
            <?= htmlspecialchars(substr((string) $key['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td class="py-2 pr-3 text-gray-600">
            <?= $key['last_used_at'] !== null
                ? htmlspecialchars(substr((string) $key['last_used_at'], 0, 10), ENT_QUOTES, 'UTF-8')
                : '—' ?>
          </td>
          <td class="py-2 text-right">
            <form method="POST" action="/settings/api-keys.php">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="action" value="revoke">
              <input type="hidden" name="key_id" value="<?= (int) $key['id'] ?>">
              <button type="submit"
                      onclick="return confirm('Revoke this key? This cannot be undone.')"
                      class="text-xs text-red-600 hover:text-red-800">Revoke</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'API Keys';
include __DIR__ . '/../../templates/layout.php';
