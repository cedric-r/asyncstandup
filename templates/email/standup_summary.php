<?php declare(strict_types=1);
/**
 * Email template: daily standup summary.
 *
 * Variables provided by extract():
 * @var string  $teamName
 * @var string  $sendDate
 * @var array[] $questions      [['id' => int, 'question' => string], ...]
 * @var array[] $submitterData  [['display_name' => string, 'answers' => [question_id => text]], ...]
 * @var string[] $nonSubmitters List of display names who did not submit.
 */
?>
<?= $teamName ?> Standup Summary — <?= $sendDate ?>

<?php if (!empty($submitterData)): ?>
<?php foreach ($submitterData as $sub): ?>
──────────────────────────────────────────────
<?= $sub['display_name'] ?>

<?php foreach ($questions as $q): ?>
<?= $q['question'] ?>
<?= !empty($sub['answers'][(int) $q['id']]) ? $sub['answers'][(int) $q['id']] : '(no answer)' ?>

<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($nonSubmitters)): ?>
──────────────────────────────────────────────
No response:

<?php foreach ($nonSubmitters as $name): ?>
- <?= $name ?>

<?php endforeach; ?>
<?php endif; ?>

<?php if (empty($submitterData) && empty($nonSubmitters)): ?>
No team members to report on.
<?php endif; ?>

---
AsyncStandUp
