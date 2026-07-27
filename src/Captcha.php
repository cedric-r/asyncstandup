<?php

declare(strict_types=1);

/**
 * Load the full CAPTCHA question bank.
 *
 * @return array{q: string, a: string[]}[]
 */
function captchaLoadQuestions(): array
{
    return require __DIR__ . '/../config/captcha_questions.php';
}

/**
 * Select a random CAPTCHA question and store its index in the session.
 *
 * Uses random_int() (cryptographically secure) rather than rand().
 * Requires an active session ($SESSION must be started by the caller).
 *
 * @return array{idx: int, question: string}
 */
function captchaGetRandomQuestion(): array
{
    $questions = captchaLoadQuestions();
    $idx       = random_int(0, count($questions) - 1);

    $_SESSION['captcha_idx'] = $idx;

    return ['idx' => $idx, 'question' => $questions[$idx]['q']];
}

/**
 * Validate the user's CAPTCHA answer against the stored session index.
 *
 * One-attempt policy: $_SESSION['captcha_idx'] is cleared unconditionally
 * after this call (pass or fail) to prevent replaying the same question.
 *
 * Returns false without checking answers if the session key is absent
 * (replay protection: direct POST without prior GET, or double-submit).
 *
 * Comparison is case-insensitive and trims leading/trailing whitespace.
 *
 * @param string $userInput Raw value from $_POST['captcha_answer'].
 */
function captchaValidate(string $userInput): bool
{
    if (!isset($_SESSION['captcha_idx'])) {
        return false; // No active question — replay attempt or session expired.
    }

    $idx = (int) $_SESSION['captcha_idx'];
    unset($_SESSION['captcha_idx']); // Always invalidate after one attempt.

    $questions = captchaLoadQuestions();

    if (!isset($questions[$idx])) {
        return false; // Index out of range — should never happen in practice.
    }

    $accepted = array_map('strtolower', $questions[$idx]['a']);
    $given    = strtolower(trim($userInput));

    return in_array($given, $accepted, true);
}
