<?php declare(strict_types=1);
/**
 * Email template: password reset.
 *
 * Variables provided by extract():
 * @var string $user_name      Display name or email address.
 * @var string $reset_url      Full URL to reset-password.php with token.
 * @var int    $expires_minutes How many minutes the link is valid (60).
 */
?>
Hi <?= $user_name ?>,

You (or someone claiming to be you) requested a password reset for your AsyncStandUp account.

Click the link below to set a new password:

<?= $reset_url ?>

This link expires in <?= $expires_minutes ?> minutes.

If you did not request a password reset, you can safely ignore this email — your password has not been changed.

---
AsyncStandUp
