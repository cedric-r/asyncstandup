<?php

declare(strict_types=1);

$config = require __DIR__ . '/../../config/config.php';

require_once __DIR__ . '/../../src/Db.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Csrf.php';
require_once __DIR__ . '/../../src/OrgRepository.php';

startSession();
requireLogin();

$pdo         = getDb($config);
$currentUser = getCurrentUser($pdo);
$flash       = getFlash();
$orgs        = getOrgsForUser($pdo, (int) $_SESSION['user_id']);

ob_start();
?>
<h1 class="page-title">My Organisations</h1>
<a href="/orgs/create.php" class="btn btn-primary">+ New Organisation</a>

<div class="mt-16">
<?php if (empty($orgs)): ?>
<p class="text-muted">You are not a member of any organisation yet.</p>
<?php else: ?>
<table>
<thead><tr><th>Name</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach ($orgs as $org): ?>
<tr>
    <td><?= htmlspecialchars($org['name'], ENT_QUOTES, 'UTF-8') ?></td>
    <td class="actions">
        <a href="/teams/index.php?org_id=<?= (int) $org['id'] ?>" class="btn btn-secondary btn-sm">Teams</a>
        <a href="/orgs/edit.php?id=<?= (int) $org['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
        <a href="/orgs/delete.php?id=<?= (int) $org['id'] ?>" class="btn btn-danger btn-sm">Delete</a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>
<?php
$content   = ob_get_clean();
$pageTitle = 'Organisations';
include __DIR__ . '/../../templates/layout.php';
