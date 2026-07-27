<?php declare(strict_types=1);
/**
 * Email template: account approval notification.
 *
 * Variables provided by extract():
 * @var string $userName  User's display name or email.
 * @var string $loginUrl  Full URL to login.php.
 * @var string $appName   Application name.
 */
?>
Hi <?= $userName ?>,

Your <?= $appName ?> account has been approved. You can now log in.

<?= $loginUrl ?>

If you did not create this account, please ignore this email.

---
<?= $appName ?>
