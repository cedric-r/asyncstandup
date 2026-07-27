<?php declare(strict_types=1);
/**
 * Email template: daily standup prompt.
 *
 * Variables provided by extract():
 * @var string   $userName
 * @var string   $orgName
 * @var string   $teamName
 * @var string   $standupUrl
 * @var string   $sendDate
 * @var string   $teamTimezone
 * @var string[] $questions
 */
?>
Hi <?= $userName ?>,

Your daily standup for: <?= $orgName ?> / <?= $teamName ?>
Date: <?= $sendDate ?> (<?= $teamTimezone ?>)

Please submit your update by clicking the link below:

<?= $standupUrl ?>

Today's questions:

<?php foreach ($questions as $i => $q): ?>
<?= ($i + 1) ?>. <?= $q ?>

<?php endforeach; ?>

This link is valid for 48 hours.

---
AsyncStandUp
