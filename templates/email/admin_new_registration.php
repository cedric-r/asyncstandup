<?php declare(strict_types=1);
/**
 * Email template: new user registration notification to admins.
 *
 * Variables provided by extract():
 * @var string $new_user_name   Display name of the newly registered user.
 * @var string $new_user_email  Email address of the newly registered user.
 * @var string $admin_url       Full URL to /admin/users.php.
 * @var string $app_name        Application name.
 */
?>
Hi,

A new user has registered on <?= $app_name ?> and is awaiting your approval.

Name:  <?= $new_user_name ?>

Email: <?= $new_user_email ?>

Review and approve or reject this registration at:

<?= $admin_url ?>

---
<?= $app_name ?>
