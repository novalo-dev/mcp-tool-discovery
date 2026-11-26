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

namespace Novalo\McpToolDiscovery\Tests\Examples;

use PHPUnit\Framework\Assert;

/**
 * An example server started on a free local port for the time of a test.
 *
 * Every test that talks to an example over HTTP starts it the same way, so
 * the way lives here once. What the server wrote on its error output is
 * kept: when it stops before it listens, a missing dependency or a fatal
 * error is the message of the failure, not five seconds of waiting.
 */
final class LocalServer
{
    private bool $running = true;

    /**
     * @param resource $process
     */
    private function __construct(
        private $process,
        public readonly string $address,
        private readonly string $errors,
    ) {}

    /**
     * @param callable(string): list<string> $command the command, given the address to listen on
     * @param array<string, string>          $env     added to the environment of the test
     */
    public static function start(callable $command, array $env = []): self
    {
        // A free port, taken from the system rather than guessed.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        Assert::assertIsResource($probe);
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        $errors = (string) tempnam(sys_get_temp_dir(), 'mcp-server-errors-');
        $process = proc_open(
            $command($address),
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $errors, 'w']],
            $pipes,
            null,
            // A server that opens its own socket reads where from MCP_LISTEN;
            // php -S takes it on its command line and ignores this.
            ['MCP_LISTEN' => $address] + $env + getenv(),
        );
        Assert::assertIsResource($process);
        $server = new self($process, $address, $errors);

        for ($i = 0; $i < 100; ++$i) {
            $socket = @stream_socket_client('tcp://' . $address, $code, $text, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return $server;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50000);
        }
        $said = trim((string) file_get_contents($errors));
        $server->stop();
        Assert::fail('The server did not start.' . ($said === '' ? '' : "\n" . $said));
    }

    /** The address of the MCP endpoint every example serves. */
    public function url(): string
    {
        return 'http://' . $this->address . '/mcp';
    }

    /**
     * What the server wrote on its error output so far: PHP's error log,
     * when no file is set for it.
     */
    public function errors(): string
    {
        return (string) file_get_contents($this->errors);
    }

    public function stop(): void
    {
        if ($this->running) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->running = false;
        }
        is_file($this->errors) && unlink($this->errors);
    }

    /**
     * Removes a directory a server wrote in, with all it holds.
     *
     * Each example writes its own files there (notes, cached indexes,
     * sessions, grants), some of them hidden, so every one is removed
     * rather than the names a test expects.
     */
    public static function remove(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
