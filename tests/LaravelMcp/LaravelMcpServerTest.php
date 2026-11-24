<?php

/*
 * This file is part of novalo/mcp-tool-discovery.
 *
 * (c) Novalo <https://novalo.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Novalo\McpToolDiscovery\Tests\LaravelMcp;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Runs docs/examples/laravel-mcp, the notes server written with laravel/mcp
 * whose tools/list and tools/call the library answers. It needs Laravel and
 * laravel/mcp installed in docs/examples/laravel-mcp, so it is a test suite
 * of its own, run by its own CI job.
 *
 * One application serves every request of a test, callers in turn, as
 * Octane does: what a caller sees must come from the request, never from a
 * singleton.
 */
final class LaravelMcpServerTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../docs/examples/laravel-mcp';

    private Application $app;

    private string $data;

    public static function setUpBeforeClass(): void
    {
        if (!is_file(self::EXAMPLE . '/vendor/autoload.php')) {
            self::markTestSkipped('Run composer install in docs/examples/laravel-mcp first.');
        }
        require_once self::EXAMPLE . '/vendor/autoload.php';
    }

    protected function setUp(): void
    {
        $this->data = sys_get_temp_dir() . '/laravel-mcp-' . bin2hex(random_bytes(6));
        $_SERVER['MCP_NOTES_DATA'] = $this->data;
        $app = require self::EXAMPLE . '/bootstrap/app.php';
        self::assertInstanceOf(Application::class, $app);
        $this->app = $app;
    }

    protected function tearDown(): void
    {
        $this->app->flush();
        unset($_SERVER['MCP_NOTES_DATA']);
        if (is_dir($this->data)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->data, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->data);
        }
    }

    public function testARequestThatNamesNoOneIsRefused(): void
    {
        [$status] = $this->ask(null, 'tools/list');

        self::assertSame(401, $status);
    }

    public function testTheClientSeesTheModeInsteadOfTheTools(): void
    {
        [$status, $answer] = $this->ask('reader-token', 'tools/list');

        self::assertSame(200, $status);
        self::assertSame(['notes_search_tools', 'notes_run_read_tool', 'notes_run_write_tool'], array_column($answer['result']['tools'], 'name'));
    }

    public function testTheHandshakeGivesTheInstructionsOfTheMode(): void
    {
        [$status, $answer] = $this->ask('reader-token', 'initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']]);

        self::assertSame(200, $status);
        $instructions = $answer['result']['instructions'] ?? '';
        self::assertIsString($instructions);
        self::assertStringStartsWith('Notes kept for the team.', $instructions);
        // The mode lists these three, so the instructions name them.
        self::assertStringContainsString('notes_search_tools', $instructions);
        self::assertStringContainsString('notes_run_write_tool', $instructions);
    }

    public function testAToolThatShouldRegisterHidesIsNeverFoundNorRun(): void
    {
        $delete = ['action' => 'delete', 'object' => 'note'];
        self::assertContains('notes_delete', $this->found('admin-token', $delete));
        self::assertNotContains('notes_delete', $this->found('reader-token', $delete));

        $result = $this->runner('reader-token', 'notes_run_write_tool', 'notes_delete', ['id' => 1]);
        self::assertTrue($result['isError'] ?? false);
        self::assertStringContainsString('Back up the server', $this->text($this->runner('reader-token', 'notes_run_read_tool', 'notes_get', ['id' => 1])));
    }

    public function testAToolRunsThroughTheRunnerAsLaravelMcpRunsIt(): void
    {
        $added = $this->runner('admin-token', 'notes_run_write_tool', 'notes_add', ['text' => 'Renew the certificate.']);
        self::assertFalse($added['isError'] ?? false);
        $id = json_decode($this->text($added), true);
        self::assertIsArray($id);
        self::assertSame('Renew the certificate.', $this->text($this->runner('reader-token', 'notes_run_read_tool', 'notes_get', ['id' => $id['id']])));

        // The tool's own validation answers, with the Request of the tool's
        // call, not of the runner's.
        $invalid = $this->runner('reader-token', 'notes_run_read_tool', 'notes_get', ['id' => 'one']);
        self::assertTrue($invalid['isError'] ?? false);
        self::assertStringContainsString('must be an integer', $this->text($invalid));

        self::assertSame('Note 1 deleted.', $this->text($this->runner('admin-token', 'notes_run_write_tool', 'notes_delete', ['id' => 1])));
    }

    public function testEachRunnerRefusesTheOtherKindOfTool(): void
    {
        $result = $this->runner('admin-token', 'notes_run_read_tool', 'notes_add', ['text' => 'x']);

        self::assertTrue($result['isError'] ?? false);
        self::assertSame(['Back up the server before the upgrade.'], array_column((array) json_decode($this->text($this->runner('admin-token', 'notes_run_read_tool', 'notes_list', [])), true), 'text'));
    }

    public function testARevisionWithoutHandshakeIsAnsweredInItsShape(): void
    {
        [$status, $answer] = $this->ask('admin-token', 'tools/list', ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ]], ['HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28', 'HTTP_MCP_METHOD' => 'tools/list']);

        self::assertSame(200, $status);
        self::assertSame('complete', $answer['result']['resultType'] ?? null);
        self::assertArrayHasKey('ttlMs', $answer['result']);
        self::assertSame(3, count($answer['result']['tools']));
    }

    public function testAForgedCursorIsAnInvalidParameter(): void
    {
        [$status, $answer] = $this->ask('admin-token', 'tools/list', ['cursor' => 'forged']);

        self::assertSame(400, $status);
        self::assertSame(-32602, $answer['error']['code'] ?? null);
    }

    /**
     * @param array<string, mixed> $search
     *
     * @return list<mixed>
     */
    private function found(string $token, array $search): array
    {
        [, $answer] = $this->ask($token, 'tools/call', ['name' => 'notes_search_tools', 'arguments' => $search]);

        return array_column($answer['result']['structuredContent']['tools'] ?? [], 'name');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function runner(string $token, string $runner, string $tool, array $arguments): array
    {
        [$status, $answer] = $this->ask($token, 'tools/call', ['name' => $runner, 'arguments' => ['name' => $tool, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]]);
        self::assertSame(200, $status);
        self::assertIsArray($answer['result'] ?? null);

        return $answer['result'];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function text(array $result): string
    {
        $text = $result['content'][0]['text'] ?? null;
        self::assertIsString($text);

        return $text;
    }

    /**
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     *
     * @return array{int, array<mixed>}
     */
    private function ask(?string $token, string $method, array $params = [], array $headers = ['HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25']): array
    {
        $kernel = $this->app->make(Kernel::class);
        $request = Request::create('/mcp', 'POST', server: ($token === null ? [] : ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]) + $headers + [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ], content: (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        // Octane forgets the guards between two requests; so does this.
        $this->app->make(AuthManager::class)->forgetGuards();
        $answer = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($answer) ? $answer : []];
    }
}
