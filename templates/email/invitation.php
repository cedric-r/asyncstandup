<?php declare(strict_types=1);
/**
 * Email template: team invitation.
 *
 * Variables provided by extract():
 * @var string $team_name
 * @var string $org_name
 * @var string $inviter_name
 * @var string $accept_url
 * @var int    $expires_days
 * @var string $roles  e.g. "Developer, Recipient"
 */
?>
You have been invited to join a team on AsyncStandUp!

Organisation : <?= $org_name ?>

Team         : <?= $team_name ?>

Invited by   : <?= $inviter_name ?>

Role(s)      : <?= $roles ?>

Click the link below to accept your invitation:

<?= $accept_url ?>

This link expires in <?= $expires_days ?> days.

If you did not expect this invitation, you can safely ignore this email.

---
AsyncStandUp
