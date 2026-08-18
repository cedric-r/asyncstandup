<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/TeamsMessageBuilder.php';

class TeamsChannelSummaryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $summaryData;

    protected function setUp(): void
    {
        $this->summaryData = [
            'team'             => ['id' => 1, 'name' => 'Engineering'],
            'date'             => '2026-08-18',
            'members'          => [
                [
                    'display_name' => 'Alice',
                    'submitted'    => true,
                    'submitted_at' => '09:12',
                    'answers'      => [
                        ['question' => 'Yesterday?', 'answer' => 'Done PR', 'is_blocker' => 0],
                    ],
                ],
                [
                    'display_name' => 'Bob',
                    'submitted'    => false,
                    'answers'      => [],
                ],
            ],
            'participation_pct' => 50,
            'avg_mood'          => 3.5,
        ];
    }

    public function testBuildSummaryCardStructure(): void
    {
        $card = buildSummaryCard($this->summaryData);

        $this->assertSame('message', $card['type']);
        $this->assertSame(
            'application/vnd.microsoft.card.adaptive',
            $card['attachments'][0]['contentType']
        );

        $body = json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Engineering', $body);
        $this->assertStringContainsString('50%', $body);
    }

    public function testBuildSummaryCardIncludesMoodWhenPresent(): void
    {
        $card = buildSummaryCard($this->summaryData);
        $body = json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('3.5/5', $body);
    }

    public function testBuildSummaryCardOmitsMoodWhenNull(): void
    {
        $this->summaryData['avg_mood'] = null;
        $card = buildSummaryCard($this->summaryData);
        $body = json_encode($card, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Avg mood', $body);
    }
}
