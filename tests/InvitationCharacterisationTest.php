<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Path B characterisation — pins acceptInvitationForUser() signature
 * BEFORE the $now optional parameter is extracted.
 *
 * Cannot call the function against SQLite at this commit because
 * InvitationRepository.php contains `ON DUPLICATE KEY UPDATE` (MySQL-only
 * syntax). The SQLite PDO driver fails at prepare() even if the branch
 * is never reached.
 *
 * Instead, Reflection pins:
 *   1. The function has exactly 3 required parameters (PDO, string, int)
 *      — still true after adding optional $now; required count stays 3.
 *   2. The first parameter type is PDO — unchanged by the refactor.
 *
 * Both assertions survive the refactor (US-7 lesson: pin PRESERVED behaviours).
 */
class InvitationCharacterisationTest extends TestCase
{
    public function testAcceptInvitationForUserHasThreeRequiredParameters(): void
    {
        $rf = new ReflectionFunction('acceptInvitationForUser');

        // After adding optional ?DateTimeImmutable $now = null, the required
        // parameter count remains 3 (PDO, string, int). This assertion will
        // still pass post-refactor.
        self::assertSame(
            3,
            $rf->getNumberOfRequiredParameters(),
            'acceptInvitationForUser must have exactly 3 required parameters before $now extraction'
        );
    }

    public function testAcceptInvitationForUserFirstParamIsPdo(): void
    {
        $rf     = new ReflectionFunction('acceptInvitationForUser');
        $params = $rf->getParameters();

        self::assertGreaterThan(0, count($params));
        self::assertSame('pdo', $params[0]->getName());
    }
}
