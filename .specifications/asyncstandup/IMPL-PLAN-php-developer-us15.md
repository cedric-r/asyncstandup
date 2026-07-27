# IMPL-PLAN — PHP Developer
## US-15: Text-Based CAPTCHA on Login and Register

**Status**: APPROVED
**Branch**: `feature/asyncstandup-captcha`
**Agent**: PHP Developer

---

## File List (exhaustive — 5 files)

| Action | File | Path B? |
|---|---|---|
| Create | `config/captcha_questions.php` | No — new |
| Create | `src/Captcha.php` | No — new; pure functions only |
| Modify | `public/register.php` | ⚠️ Yes — additive: captcha require + call + form field |
| Modify | `public/login.php` | ⚠️ Yes — additive: captcha require + call + form field |
| Create | `.specifications/asyncstandup/IMPL-PLAN-php-developer-us15.md` | No — this file |

**No test file listed**: `src/Captcha.php` contains only pure functions (no PDO parameters). Per US-14 RETRO lesson 1, the Test Validator MAJOR precedent applies only to PDO-injectable `src/` functions. Pure functions with no DB side effects are testable but not subject to mandatory test coverage under the current project conventions.

**All Path B modifications are purely additive** — no existing logic removed. No characterisation commit required.

---

## `src/Captcha.php` — Function Signatures

```php
function captchaLoadQuestions(): array
```
Loads and returns the question bank from `config/captcha_questions.php` via `require`. Pure function.

```php
function captchaGetRandomQuestion(): array
```
Selects a random question using `random_int(0, count($questions) - 1)` (cryptographically secure). Stores the index in `$_SESSION['captcha_idx']`. Returns `['idx' => int, 'question' => string]`.

```php
function captchaValidate(string $userInput): bool
```
Reads and immediately clears `$_SESSION['captcha_idx']` (one-attempt policy). Returns `false` if session key is absent (replay protection). Compares `strtolower(trim($userInput))` against `array_map('strtolower', $answers)` via `in_array(..., true)` (strict). Returns `true` on match.

---

## Validation Order (both pages, on POST)

```
1. validateCsrfToken()      — CSRF first (CSRF → CAPTCHA → form logic)
2. captchaValidate()        — before any DB access
3. Form logic               — login / register
```

On CAPTCHA failure: append error, call `captchaGetRandomQuestion()` (new question), re-render form and exit. No login attempt, no account creation.

---

## `config/captcha_questions.php` — 50 Questions

Plain PHP file returning an array. Not executed as a side-effect file.

Categories: arithmetic (10), days/months/time (10), colours/nature (8), animal legs (7), plants/nature facts (4), planets/space (3), shapes/general knowledge (8).

Full list per STORY.md specification (exactly 50 entries).

---

## Integration Pattern

On GET: `captchaGetRandomQuestion()` → `$captcha['question']` passed to template.

On POST: CSRF → `captchaValidate($_POST['captcha_answer'] ?? '')` → on fail: error + new question + re-render. On pass: form logic proceeds.

---

## Self-Check

- [ ] `random_int()` not `rand()` in `captchaGetRandomQuestion()`
- [ ] `$_SESSION['captcha_idx']` cleared on every POST (pass or fail) in `captchaValidate()`
- [ ] Missing session idx → `false` in `captchaValidate()`
- [ ] Validation order: CSRF → CAPTCHA → form logic
- [ ] `in_array(strtolower(trim($input)), array_map('strtolower', $answers), true)` — strict
- [ ] `declare(strict_types=1)` in `src/Captcha.php`
- [ ] `config/captcha_questions.php` returns array (no side effects)
- [ ] All captcha question output via `htmlspecialchars(ENT_QUOTES, 'UTF-8')`
- [ ] 33→37 existing tests still pass (no regression)
- [ ] No `var_dump`/`print_r`/`die`
