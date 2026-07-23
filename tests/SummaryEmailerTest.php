<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for isSummaryDue() in src/SummaryEmailer.php.
 *
 * Summary fires at standup_time + 1 hour. Same boundary cases as StandupEmailerTest
 * but nowUtc is offset by exactly +1 hour.
 *
 * Team fixture: standup 09:00 Europe/London (UTC+0 winter) → summary at 10:00 UTC.
 */
class SummaryEmailerTest extends TestCase
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
        // 10:00:00 UTC = standup 09:00 + 1h → diff = 0.
        self::assertTrue(isSummaryDue($this->team, $this->utc('10:00:00')));
    }

    public function testOneSecondBeforeWindowReturnsFalse(): void
    {
        // 09:58:59 → diff = 61s from summary time → outside window.
        self::assertFalse(isSummaryDue($this->team, $this->utc('09:58:59')));
    }

    public function test59SecondsBeforeWindowReturnsTrue(): void
    {
        // 09:59:01 → diff = 59s → inside window.
        self::assertTrue(isSummaryDue($this->team, $this->utc('09:59:01')));
    }

    public function test59SecondsAfterWindowReturnsTrue(): void
    {
        // 10:00:59 → diff = 59s → inside window.
        self::assertTrue(isSummaryDue($this->team, $this->utc('10:00:59')));
    }

    public function test60SecondsAfterWindowReturnsFalse(): void
    {
        // 10:01:00 → diff = 60s → outside window (< 60 is strict).
        self::assertFalse(isSummaryDue($this->team, $this->utc('10:01:00')));
    }

    public function testDifferentTimezoneReturnsTrue(): void
    {
        // Team in America/New_York (UTC-5 winter), standup 09:00 local → 14:00 UTC.
        // Summary = 14:00 + 1h = 15:00 UTC.
        $nyTeam = [
            'standup_time' => '09:00:00',
            'timezone'     => 'America/New_York',
        ];
        $nowUtc = new DateTimeImmutable('2024-01-15 15:00:00', new DateTimeZone('UTC'));

        self::assertTrue(isSummaryDue($nyTeam, $nowUtc));
    }
}
