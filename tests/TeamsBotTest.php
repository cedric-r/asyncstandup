<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/TeamsBot.php';

class TeamsBotTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $team;
    /** @var array<int, array<string, mixed>> */
    private array $questions;

    protected function setUp(): void
    {
        $this->team = [
            'id'           => 1,
            'name'         => 'Engineering',
            'timezone'     => 'UTC',
            'standup_time' => '09:00:00',
        ];
        $this->questions = [
            ['id' => 1, 'question' => 'What did you do yesterday?', 'is_blocker' => 0],
            ['id' => 2, 'question' => 'What will you do today?',    'is_blocker' => 0],
        ];
    }

    public function testBuildPromptCardHasAllQuestions(): void
    {
        $card = buildPromptCard($this->team, $this->questions, 'tok123');
        $body = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('q_1', $body);
        $this->assertStringContainsString('q_2', $body);
        $this->assertStringContainsString('What did you do yesterday?', $body);
    }

    public function testBuildPromptCardEmbeddsToken(): void
    {
        $card    = buildPromptCard($this->team, $this->questions, 'my-secret-token');
        $content = $card['attachments'][0]['content'];

        $found = false;
        foreach ($content['actions'] as $action) {
            if ($action['type'] === 'Action.Submit'
                && ($action['data']['token'] ?? '') === 'my-secret-token'
            ) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Token not found in Action.Submit data');
    }

    public function testSendDmPromptReturnsFalseWithNoConvRef(): void
    {
        $pdo  = createTestPdo();
        $user = ['id' => 1, 'teams_conversation_ref' => null];

        $result = sendDmPrompt($pdo, $user, $this->team, $this->questions, 'tok', []);

        $this->assertFalse($result);
    }
}
