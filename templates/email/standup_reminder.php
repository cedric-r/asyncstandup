<?php

declare(strict_types=1);

/**
 * Submission reminder email template.
 *
 * Variables injected via extract():
 *
 * @var string $userName     Developer's display name.
 * @var string $teamName     Team name.
 * @var string $standupUrl   Submission link (same token URL as the original prompt).
 * @var string $expiresAt    Formatted local time when the token expires (e.g. "11:00 UTC").
 * @var string $teamTimezone Team timezone string (e.g. "Europe/London").
 */
?>
Hi <?= $userName ?>,

A quick reminder — you haven't submitted your standup for <?= $teamName ?> yet.

Your response window closes at <?= $expiresAt ?> (<?= $teamTimezone ?>).

Submit here: <?= $standupUrl ?>

If you've already submitted, please ignore this message.
