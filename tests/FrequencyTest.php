<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/StandupEmailer.php';

class FrequencyTest extends TestCase
{
    /**
     * @param int|null $frequencyDay
     */
    private function makeTeam(string $frequency, ?int $frequencyDay, string $standupTime, string $timezone): array
    {
        return [
            'frequency'     => $frequency,
            'frequency_day' => $frequencyDay,
            'standup_time'  => $standupTime . ':00',
            'timezone'      => $timezone,
        ];
    }

    public function testDailyTeamSkipsWeekend(): void
    {
        // 2026-08-15 is a Saturday (N=6)
        $nowUtc = new DateTimeImmutable('2026-08-15 09:00:00', new DateTimeZone('UTC'));
        $team   = $this->makeTeam('daily', null, '09:00', 'UTC');

        $this->assertFalse(isTeamDue($team, $nowUtc));
    }

    public function testWeeklyTeamFiresOnCorrectDay(): void
    {
        // 2026-08-17 is a Monday (N=1); standup at 09:00 UTC; now is exactly 09:00
        $nowUtc = new DateTimeImmutable('2026-08-17 09:00:00', new DateTimeZone('UTC'));
        $team   = $this->makeTeam('weekly', 1, '09:00', 'UTC');

        $this->assertTrue(isTeamDue($team, $nowUtc));
    }

    public function testWeeklyTeamSkipsWrongDay(): void
    {
        // 2026-08-18 is a Tuesday (N=2); team configured for Monday (1)
        $nowUtc = new DateTimeImmutable('2026-08-18 09:00:00', new DateTimeZone('UTC'));
        $team   = $this->makeTeam('weekly', 1, '09:00', 'UTC');

        $this->assertFalse(isTeamDue($team, $nowUtc));
    }
}
