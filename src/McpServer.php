<?php

declare(strict_types=1);

/**
 * JSON-RPC 2.0 MCP server for AsyncStandUp.
 *
 * Reads newline-delimited JSON from STDIN and writes responses to STDOUT.
 * Implements the MCP protocol version 2024-11-05.
 */
class McpServer
{
    private PDO    $pdo;
    /** @var array<string, mixed>|null */
    private ?array $apiKey;

    /**
     * @param array<string, mixed>|null $apiKey Pre-resolved API key row (used in tests to
     *                                          bypass env-var authentication). When null,
     *                                          run() will authenticate from ASYNCSTANDUP_API_KEY.
     */
    public function __construct(PDO $pdo, ?array $apiKey = null)
    {
        $this->pdo    = $pdo;
        $this->apiKey = $apiKey;
    }

    /**
     * Main stdio loop — reads JSON-RPC requests from STDIN, writes responses to STDOUT.
     *
     * Authenticates once at startup from the ASYNCSTANDUP_API_KEY environment variable.
     * Runs until STDIN is closed (EOF).
     */
    public function run(): void
    {
        // Authenticate from env var (overrides any constructor-injected key).
        $rawKey       = (string) (getenv('ASYNCSTANDUP_API_KEY') ?: '');
        $keyHash      = hash('sha256', $rawKey);
        $stmt         = $this->pdo->prepare('SELECT * FROM api_keys WHERE key_hash = ?');
        $stmt->execute([$keyHash]);
        $row          = $stmt->fetch();
        $this->apiKey = $row !== false ? $row : null;

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $request = null;
            try {
                $request  = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $response = $this->handle($request);
            } catch (\JsonException $e) {
                $response = $this->errorResponse(null, -32700, 'Parse error');
            } catch (\Throwable $e) {
                $response = $this->errorResponse($request['id'] ?? null, -32603, $e->getMessage());
            }

            fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
            fflush(STDOUT);
        }
    }

    /**
     * Dispatch a single JSON-RPC request and return the response array.
     *
     * Public visibility is intentional — it allows direct unit testing of the
     * dispatch logic without needing to mock STDIN/STDOUT.
     *
     * @param array<string, mixed> $req
     * @return array<string, mixed>
     */
    public function handle(array $req): array
    {
        $id     = $req['id']     ?? null;
        $method = (string) ($req['method'] ?? '');
        $params = is_array($req['params'] ?? null) ? $req['params'] : [];

        return match ($method) {
            'initialize' => $this->handleInitialize($id),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $params),
            default      => $this->errorResponse($id, -32601, 'Method not found'),
        };
    }

    // -----------------------------------------------------------------------
    // Method handlers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function handleInitialize(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'protocolVersion' => '2024-11-05',
                'serverInfo'      => ['name' => 'AsyncStandUp', 'version' => '1.0.0'],
                'capabilities'    => ['tools' => new \stdClass()],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function handleToolsList(mixed $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => ['tools' => McpTools::getToolDefinitions()],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolsCall(mixed $id, array $params): array
    {
        if ($this->apiKey === null) {
            return $this->errorResponse($id, -32001, 'Unauthorized — set ASYNCSTANDUP_API_KEY env var');
        }

        $toolName  = (string) ($params['name']      ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $tools  = new McpTools($this->pdo, $this->apiKey);
        $result = $tools->call($toolName, $arguments);

        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    ],
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ];
    }
}
