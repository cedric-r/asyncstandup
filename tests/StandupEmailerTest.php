<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for isTeamDue() in src/StandupEmailer.php.
 *
 * Team fixture: standup at 09:00 UTC (Europe/London, winter = UTC+0).
 * Window: abs(diff) < 60 seconds.
 */
class StandupEmailerTest extends TestCase
{
    /** @var array{standup_time: string, timezone: string} */
    private array $team;

    protected function setUp(): void
    {
        $this->team = [
            'standup_time' => '09:00:00',
            'timezone'     => 'Europe/London',
        ];
    }

    private function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2024-01-15 ' . $time, new DateTimeZone('UTC'));
    }

    public function testExactMatchReturnsTrue(): void
    {
        // nowUtc == standup_time → diff = 0 → inside window.
        self::assertTrue(isTeamDue($this->team, $this->utc('09:00:00')));
    }

    public function testOneSecondBeforeWindowReturnsFalse(): void
    {
        // 08:58:59 → diff = 61s → outside window (< 60 is strict).
        self::assertFalse(isTeamDue($this->team, $this->utc('08:58:59')));
    }

    public function test59SecondsBeforeWindowReturnsTrue(): void
    {
        // 08:59:01 → diff = 59s → inside window.
        self::assertTrue(isTeamDue($this->team, $this->utc('08:59:01')));
    }

    public function test59SecondsAfterWindowReturnsTrue(): void
    {
        // 09:00:59 → diff = 59s → inside window.
        self::assertTrue(isTeamDue($this->team, $this->utc('09:00:59')));
    }

    public function test60SecondsAfterWindowReturnsFalse(): void
    {
        // 09:01:00 → diff = 60s → outside window (< 60 is strict).
        self::assertFalse(isTeamDue($this->team, $this->utc('09:01:00')));
    }

    public function testDifferentTimezoneReturnsTrue(): void
    {
        // Team in America/New_York (UTC-5 in winter), standup at 09:00 local.
        // UTC equivalent = 14:00 UTC.
        $nyTeam = [
            'standup_time' => '09:00:00',
            'timezone'     => 'America/New_York',
        ];
        $nowUtc = new DateTimeImmutable('2024-01-15 14:00:00', new DateTimeZone('UTC'));

        self::assertTrue(isTeamDue($nyTeam, $nowUtc));
    }
}
