# US-15: Text-Based CAPTCHA on Login and Register

**Feature**: asyncstandup-core  
**Story**: US-15  
**Branch**: `feature/asyncstandup-captcha`

## User Story

**As an** operator  
**I can** require users to answer a simple text question on login and registration  
**So that** automated bots are deterred from creating accounts or brute-forcing passwords

## Acceptance Criteria

1. **Given** login or register page loaded (GET), **When** rendered, **Then** a CAPTCHA question from the 50-question bank is displayed with a text input field
2. **Given** correct answer submitted (any accepted variant, any case, leading/trailing whitespace trimmed), **When** form submitted, **Then** CAPTCHA passes; form proceeds normally (login/register logic runs)
3. **Given** incorrect answer submitted, **When** form submitted, **Then** error "Incorrect answer to the security question." shown; form NOT processed (no login attempt, no account creation); new random question displayed
4. **Given** empty CAPTCHA field submitted, **When** processed, **Then** treated as wrong answer (AC-3 path)
5. **Given** answer "4" or "four" for a question expecting either, **When** submitted, **Then** both accepted (case-insensitive, trimmed)
6. **Given** 50 questions in the bank, **When** two consecutive page loads, **Then** questions are statistically likely to differ (random selection)
7. **Given** CAPTCHA session key not set (direct POST without prior GET), **When** submitted, **Then** treated as wrong answer; session replay prevented

## Definition of Done

- [ ] All ACs met
- [ ] 50 questions in `config/captcha_questions.php` covering varied categories
- [ ] Validation: `in_array(strtolower(trim($input)), array_map('strtolower', $answers))` — no strict typing issues
- [ ] On POST failure: new random question selected (never reuse same index after wrong answer)
- [ ] `$_SESSION['captcha_idx']` cleared / regenerated after each POST attempt
- [ ] No new DB tables; session-only state
- [ ] Works without JavaScript
- [ ] Both `register.php` and `login.php` modified — CSRF validation still runs before CAPTCHA check

## Files

| Action | File | Risk |
|---|---|---|
| Create | `config/captcha_questions.php` | — |
| Create | `src/Captcha.php` | — |
| Modify | `public/register.php` | ⚠️ Path B |
| Modify | `public/login.php` | ⚠️ Path B |

## Question Bank (`config/captcha_questions.php`)

```php
<?php
return [
    // Arithmetic
    ['q' => 'What is 2 + 2?',                                       'a' => ['4', 'four']],
    ['q' => 'What is 3 + 5?',                                       'a' => ['8', 'eight']],
    ['q' => 'What is 10 - 4?',                                      'a' => ['6', 'six']],
    ['q' => 'What is 7 + 1?',                                       'a' => ['8', 'eight']],
    ['q' => 'What is 9 - 3?',                                       'a' => ['6', 'six']],
    ['q' => 'What is 5 + 5?',                                       'a' => ['10', 'ten']],
    ['q' => 'What is 12 - 7?',                                      'a' => ['5', 'five']],
    ['q' => 'What is 3 × 3?',                                       'a' => ['9', 'nine']],
    ['q' => 'What is 2 × 6?',                                       'a' => ['12', 'twelve']],
    ['q' => 'What is 20 ÷ 4?',                                      'a' => ['5', 'five']],

    // Days / months / time
    ['q' => 'How many days are in a week?',                         'a' => ['7', 'seven']],
    ['q' => 'How many months are in a year?',                       'a' => ['12', 'twelve']],
    ['q' => 'How many hours are in a day?',                         'a' => ['24', 'twenty-four', 'twenty four']],
    ['q' => 'How many minutes are in an hour?',                     'a' => ['60', 'sixty']],
    ['q' => 'What month comes after January?',                      'a' => ['february']],
    ['q' => 'What month comes after March?',                        'a' => ['april']],
    ['q' => 'What is the last month of the year?',                  'a' => ['december']],
    ['q' => 'What is the first month of the year?',                 'a' => ['january']],
    ['q' => 'How many days are in a fortnight?',                    'a' => ['14', 'fourteen']],
    ['q' => 'How many seconds are in a minute?',                    'a' => ['60', 'sixty']],

    // Colours / nature
    ['q' => 'What colour is the sky on a clear day?',               'a' => ['blue']],
    ['q' => 'What colour is grass?',                                'a' => ['green']],
    ['q' => 'What colour is a ripe banana?',                        'a' => ['yellow']],
    ['q' => 'What colour is snow?',                                 'a' => ['white']],
    ['q' => 'What colour is a tomato?',                             'a' => ['red']],
    ['q' => 'What colour is coal?',                                 'a' => ['black']],
    ['q' => 'What colour is an orange (the fruit)?',                'a' => ['orange']],
    ['q' => 'What colour is the sun?',                              'a' => ['yellow', 'white']],

    // Animal legs
    ['q' => 'How many legs does a dog have?',                       'a' => ['4', 'four']],
    ['q' => 'How many legs does a human have?',                     'a' => ['2', 'two']],
    ['q' => 'How many legs does a spider have?',                    'a' => ['8', 'eight']],
    ['q' => 'How many legs does a cat have?',                       'a' => ['4', 'four']],
    ['q' => 'How many legs does an ant have?',                      'a' => ['6', 'six']],
    ['q' => 'How many wings does a bird have?',                     'a' => ['2', 'two']],
    ['q' => 'How many legs does a horse have?',                     'a' => ['4', 'four']],

    // Plants / nature facts
    ['q' => 'What fruit grows on a cherry tree?',                   'a' => ['cherry', 'cherries']],
    ['q' => 'What fruit grows on an apple tree?',                   'a' => ['apple', 'apples']],
    ['q' => 'What do bees produce?',                                'a' => ['honey']],
    ['q' => 'What do cows produce that we drink?',                  'a' => ['milk']],

    // Planets / space
    ['q' => 'What planet do we live on?',                           'a' => ['earth']],
    ['q' => 'What is the closest star to Earth?',                   'a' => ['sun', 'the sun']],
    ['q' => 'How many planets are in our solar system?',            'a' => ['8', 'eight']],

    // Simple word / general knowledge
    ['q' => 'How many sides does a triangle have?',                 'a' => ['3', 'three']],
    ['q' => 'How many sides does a square have?',                   'a' => ['4', 'four']],
    ['q' => 'What shape has no corners?',                           'a' => ['circle']],
    ['q' => 'How many fingers does a human hand have?',             'a' => ['5', 'five']],
    ['q' => 'What do you use to write on a whiteboard?',            'a' => ['marker', 'pen', 'whiteboard marker']],
    ['q' => 'What is H2O commonly called?',                         'a' => ['water']],
    ['q' => 'What is the opposite of hot?',                         'a' => ['cold']],
    ['q' => 'What is the opposite of day?',                         'a' => ['night']],
];
// Total: 50 questions
```

## `src/Captcha.php`

```php
<?php
declare(strict_types=1);

function captchaLoadQuestions(): array {
    return require __DIR__ . '/../config/captcha_questions.php';
}

function captchaGetRandomQuestion(): array {
    $questions = captchaLoadQuestions();
    $idx = random_int(0, count($questions) - 1);
    $_SESSION['captcha_idx'] = $idx;
    return ['idx' => $idx, 'question' => $questions[$idx]['q']];
}

/**
 * Returns true if the submitted answer matches any accepted answer for the
 * question stored in the session. Clears the session key regardless of result.
 * Returns false if the session key is missing (replay prevention).
 */
function captchaValidate(string $userInput): bool {
    if (!isset($_SESSION['captcha_idx'])) {
        return false;
    }
    $idx = (int) $_SESSION['captcha_idx'];
    unset($_SESSION['captcha_idx']);  // always invalidate after one attempt

    $questions = captchaLoadQuestions();
    if (!isset($questions[$idx])) {
        return false;
    }

    $accepted = array_map('strtolower', $questions[$idx]['a']);
    $given    = strtolower(trim($userInput));

    return in_array($given, $accepted, true);
}
```

## Integration Pattern

### GET request (both pages)

```php
// At top of GET handler, after session_start():
require_once __DIR__ . '/../src/Captcha.php';
$captcha = captchaGetRandomQuestion();
// $captcha['question'] used in template
```

### POST request (both pages)

```php
// Validation order: CSRF check → captcha check → form logic
require_once __DIR__ . '/../src/Captcha.php';

// 1. CSRF
validateCsrfOrFail();

// 2. CAPTCHA — checked before touching DB
$captchaAnswer = $_POST['captcha_answer'] ?? '';
if (!captchaValidate($captchaAnswer)) {
    $errors[] = 'Incorrect answer to the security question.';
    $captcha  = captchaGetRandomQuestion();  // new question on failure
    // render form with $errors and $captcha, exit
}

// 3. Form logic (login / register)
```

### Form template snippet

```html
<div class="captcha-block">
    <label for="captcha_answer">
        Security question: <?= htmlspecialchars($captcha['question'], ENT_QUOTES, 'UTF-8') ?>
    </label>
    <input type="text" id="captcha_answer" name="captcha_answer"
           autocomplete="off" required>
</div>
```

## Implementation Notes

- **Session prerequisite**: `session_start()` must be called before `captchaGetRandomQuestion()` and `captchaValidate()` — both pages already call it
- **CSRF first**: CSRF validation should run before CAPTCHA to avoid leaking a "captcha failed" signal on forged requests — order: CSRF → CAPTCHA → form logic
- **One attempt per question**: `captchaValidate()` unconditionally `unset`s `$_SESSION['captcha_idx']` — prevents retrying the same question with different answers via multiple POSTs
- **`random_int()` not `rand()`**: `random_int()` is cryptographically secure; `rand()` is not — use `random_int(0, count($questions) - 1)`
- **No JS dependency**: CAPTCHA degrades correctly without JavaScript
- **Not a replacement for rate limiting**: document in README that CAPTCHA reduces bot pressure but does not replace server-level rate limiting or account lockout for production deployments
