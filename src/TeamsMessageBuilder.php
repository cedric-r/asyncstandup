<?php

declare(strict_types=1);

/**
 * Build a Teams Incoming Webhook payload containing an Adaptive Card summary.
 *
 * Input $summaryData shape:
 *   team             => array (row from teams table — must include 'name')
 *   date             => 'YYYY-MM-DD'
 *   members          => array of:
 *       display_name  => string
 *       submitted     => bool
 *       submitted_at  => string|null  ('HH:MM' or null)
 *       answers       => array of ['question'=>string, 'answer'=>string, 'is_blocker'=>0|1]
 *   participation_pct => int
 *   avg_mood          => float|null   (null → mood footer omitted)
 *
 * @param array<string, mixed> $summaryData
 * @return array<string, mixed>  Outer 'message' wrapper ready for json_encode + POST
 */
function buildSummaryCard(array $summaryData): array
{
    $teamName = (string) ($summaryData['team']['name'] ?? 'Team');
    $date     = (string) ($summaryData['date'] ?? date('Y-m-d'));
    $members  = is_array($summaryData['members']) ? $summaryData['members'] : [];
    $pct      = (int) ($summaryData['participation_pct'] ?? 0);
    $rawMood  = $summaryData['avg_mood'] ?? null;
    $avgMood  = $rawMood !== null ? (float) $rawMood : null;

    // Sort: submitted members first.
    usort($members, static fn($a, $b) => (int) $b['submitted'] <=> (int) $a['submitted']);

    $responded = count(array_filter($members, static fn($m) => $m['submitted']));
    $total     = count($members);

    $dateFormatted = (new DateTimeImmutable($date))->format('l j F Y');

    // -----------------------------------------------------------------------
    // Build body items
    // -----------------------------------------------------------------------
    $bodyItems = [
        [
            'type'    => 'TextBlock',
            'text'    => "📋 Daily Standup Summary — {$teamName}",
            'weight'  => 'Bolder',
            'size'    => 'Medium',
            'wrap'    => true,
        ],
        [
            'type'     => 'TextBlock',
            'text'     => "{$dateFormatted} · {$responded}/{$total} responded ({$pct}%)",
            'isSubtle' => true,
            'spacing'  => 'None',
            'wrap'     => true,
        ],
    ];

    // Per-member items inside a separator container.
    $memberItems = [];
    foreach ($members as $member) {
        $name      = (string) ($member['display_name'] ?? '—');
        $submitted = (bool) ($member['submitted'] ?? false);

        if ($submitted) {
            $rawAt       = $member['submitted_at'] ?? null;
            $submittedAt = $rawAt !== null ? '  ·  ' . $rawAt : '';
            $colItems = [
                [
                    'type'    => 'TextBlock',
                    'text'    => "✅ {$name}{$submittedAt}",
                    'weight'  => 'Bolder',
                    'wrap'    => true,
                ],
            ];

            foreach ($member['answers'] as $ans) {
                $q      = (string) ($ans['question'] ?? '');
                $a      = (string) ($ans['answer']   ?? '');
                $prefix = (!empty($ans['is_blocker']) && $a !== '') ? '⚠️ ' : '';
                $colItems[] = [
                    'type' => 'TextBlock',
                    'text' => "  {$prefix}{$q}: {$a}",
                    'wrap' => true,
                ];
            }

            $memberItems[] = [
                'type'    => 'ColumnSet',
                'columns' => [[
                    'type'  => 'Column',
                    'width' => 'stretch',
                    'items' => $colItems,
                ]],
            ];
        } else {
            $memberItems[] = [
                'type'     => 'TextBlock',
                'text'     => "❌ {$name} — No response",
                'isSubtle' => true,
                'wrap'     => true,
            ];
        }
    }

    $bodyItems[] = [
        'type'      => 'Container',
        'separator' => true,
        'items'     => $memberItems,
    ];

    // Mood footer.
    if ($avgMood !== null) {
        $bodyItems[] = [
            'type'    => 'TextBlock',
            'text'    => '😊 Avg mood: ' . number_format($avgMood, 1) . '/5',
            'spacing' => 'Medium',
            'wrap'    => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Assemble Adaptive Card + Teams Incoming Webhook outer wrapper
    // -----------------------------------------------------------------------
    return [
        'type'        => 'message',
        'attachments' => [[
            'contentType' => 'application/vnd.microsoft.card.adaptive',
            'content'     => [
                '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                'type'    => 'AdaptiveCard',
                'version' => '1.4',
                'body'    => $bodyItems,
            ],
        ]],
    ];
}
