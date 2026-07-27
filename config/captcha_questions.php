<?php

/**
 * AsyncStandUp CAPTCHA question bank — 50 questions.
 *
 * Each entry: ['q' => string (question), 'a' => string[] (accepted answers)].
 * Validation is case-insensitive; leading/trailing whitespace is trimmed.
 *
 * This file returns a plain array — it has no side effects.
 */
return [
    // ── Arithmetic ───────────────────────────────────────────────────────────
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

    // ── Days / months / time ─────────────────────────────────────────────────
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

    // ── Colours / nature ─────────────────────────────────────────────────────
    ['q' => 'What colour is the sky on a clear day?',               'a' => ['blue']],
    ['q' => 'What colour is grass?',                                'a' => ['green']],
    ['q' => 'What colour is a ripe banana?',                        'a' => ['yellow']],
    ['q' => 'What colour is snow?',                                 'a' => ['white']],
    ['q' => 'What colour is a tomato?',                             'a' => ['red']],
    ['q' => 'What colour is coal?',                                 'a' => ['black']],
    ['q' => 'What colour is an orange (the fruit)?',                'a' => ['orange']],
    ['q' => 'What colour is the sun?',                              'a' => ['yellow', 'white']],

    // ── Animal legs ───────────────────────────────────────────────────────────
    ['q' => 'How many legs does a dog have?',                       'a' => ['4', 'four']],
    ['q' => 'How many legs does a human have?',                     'a' => ['2', 'two']],
    ['q' => 'How many legs does a spider have?',                    'a' => ['8', 'eight']],
    ['q' => 'How many legs does a cat have?',                       'a' => ['4', 'four']],
    ['q' => 'How many legs does an ant have?',                      'a' => ['6', 'six']],
    ['q' => 'How many wings does a bird have?',                     'a' => ['2', 'two']],
    ['q' => 'How many legs does a horse have?',                     'a' => ['4', 'four']],

    // ── Plants / nature facts ─────────────────────────────────────────────────
    ['q' => 'What fruit grows on a cherry tree?',                   'a' => ['cherry', 'cherries']],
    ['q' => 'What fruit grows on an apple tree?',                   'a' => ['apple', 'apples']],
    ['q' => 'What do bees produce?',                                'a' => ['honey']],
    ['q' => 'What do cows produce that we drink?',                  'a' => ['milk']],

    // ── Planets / space ───────────────────────────────────────────────────────
    ['q' => 'What planet do we live on?',                           'a' => ['earth']],
    ['q' => 'What is the closest star to Earth?',                   'a' => ['sun', 'the sun']],
    ['q' => 'How many planets are in our solar system?',            'a' => ['8', 'eight']],

    // ── Shapes / general knowledge ────────────────────────────────────────────
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
