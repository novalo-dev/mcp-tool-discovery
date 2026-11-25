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

namespace Novalo\McpToolDiscovery\Tests\Unit;

use Novalo\McpToolDiscovery\Search\Cache\FileIndexCache;
use Novalo\McpToolDiscovery\Search\Learning\FileLearningStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileStoresTest extends TestCase
{
    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->made) as $path) {
            if (is_dir($path)) {
                array_map('unlink', glob($path . '/*') ?: []);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function temporary(string $name): string
    {
        $path = sys_get_temp_dir() . '/mcp-tool-discovery-' . $name . '-' . bin2hex(random_bytes(6));
        $this->made[] = $path;

        return $path;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paths(): iterable
    {
        yield 'empty' => [''];
        yield 'null byte' => ["/var/cache/mcp\0index"];
    }

    #[DataProvider('paths')]
    public function testAnIndexCacheRefusesAPathThatCanNeverWork(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileIndexCache($path);
    }

    #[DataProvider('paths')]
    public function testALearningStoreRefusesAPathThatCanNeverWork(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileLearningStore($path);
    }

    public function testAnIndexCacheThatCannotWriteTellsTheHostAndGoesOn(): void
    {
        // A file where the directory should be: mkdir() fails even for root.
        $file = $this->temporary('file');
        file_put_contents($file, '');
        $reasons = [];
        $cache = new FileIndexCache($file . '/index', static function (string $reason) use (&$reasons): void {
            $reasons[] = $reason;
        });

        $cache->set('key', ['a' => 1]);

        self::assertNull($cache->get('key'));
        self::assertSame(['The index cache cannot create the directory ' . $file . '/index.'], $reasons);
    }

    public function testAnIndexCacheThatWritesSaysNothing(): void
    {
        $reasons = [];
        $cache = new FileIndexCache($this->temporary('index'), static function (string $reason) use (&$reasons): void {
            $reasons[] = $reason;
        });

        $cache->set('key', ['a' => 1]);

        self::assertSame(['a' => 1], $cache->get('key'));
        self::assertSame([], $reasons);
    }

    public function testALearningStoreThatCannotWriteTellsTheHostAndGoesOn(): void
    {
        // A directory where the file should be: fopen() fails even for root.
        $directory = $this->temporary('directory');
        mkdir($directory);
        $reasons = [];
        $store = new FileLearningStore($directory, static function (string $reason) use (&$reasons): void {
            $reasons[] = $reason;
        });

        $store->record(['file'], 'get_file');

        self::assertSame([], $store->load());
        self::assertSame(['The learning store cannot open ' . $directory . '.'], $reasons);
    }

    public function testALearningStoreTellsTheHostItsFileHoldsNoCounts(): void
    {
        $file = $this->temporary('learning');
        file_put_contents($file, 'not json');
        $reasons = [];
        $store = new FileLearningStore($file, static function (string $reason) use (&$reasons): void {
            $reasons[] = $reason;
        });

        self::assertSame([], $store->load());
        $store->record(['file'], 'get_file');

        // Started again, so the next run reads a file it can use.
        self::assertSame(['file' => ['get_file' => 1]], (new FileLearningStore($file))->load());
        self::assertSame(array_fill(0, 2, 'The learning store found no JSON object in ' . $file . '.'), $reasons);
    }

    public function testALearningStoreThatWorksSaysNothing(): void
    {
        $file = $this->temporary('learning');
        $reasons = [];
        $store = new FileLearningStore($file, static function (string $reason) use (&$reasons): void {
            $reasons[] = $reason;
        });

        $store->record(['file'], 'get_file');

        self::assertSame(['file' => ['get_file' => 1]], (new FileLearningStore($file))->load());
        self::assertSame([], $reasons);
    }
}
