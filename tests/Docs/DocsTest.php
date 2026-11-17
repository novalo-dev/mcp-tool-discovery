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

namespace Novalo\McpToolDiscovery\Tests\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the documentation true to the code. Hosts copy the snippets of the
 * README and docs/ as they are: a renamed class, method or named argument
 * that the docs still show fails in their project, not in ours. The snippets
 * are read, never run, since they call host code (a user, a tool registry)
 * that does not exist here.
 */
final class DocsTest extends TestCase
{
    private const NS = 'Novalo\McpToolDiscovery\\';

    public function testEveryPhpSnippetParses(): void
    {
        $wrong = [];
        foreach (self::snippets() as $where => $code) {
            try {
                // The open tag alone is one token: an empty snippet shows nothing.
                if (\count(token_get_all("<?php\n" . $code, TOKEN_PARSE)) < 2) {
                    $wrong[] = $where . ': empty';
                }
            } catch (\ParseError $e) {
                $wrong[] = $where . ': ' . $e->getMessage() . ' on line ' . ($e->getLine() - 1);
            }
        }

        self::assertSame([], $wrong);
    }

    public function testEveryLibraryNameASnippetUsesExists(): void
    {
        $classes = self::libraryClasses();
        $wrong = [];

        foreach (self::snippetsByFile() as $file => $blocks) {
            // A variable keeps the class it was built from across the blocks
            // of one page, as a reader follows the page from top to bottom.
            $types = [];
            foreach ($blocks as $i => $code) {
                $where = $file . ' snippet ' . ($i + 1);
                $tokens = self::tokens($code);
                foreach ($tokens as $k => $token) {
                    if (\is_array($token) && T_USE === $token[0]) {
                        $name = self::next($tokens, $k);
                        // The exact case: PHP forgives it once a class is
                        // loaded, but Composer's autoloader on a case-sensitive
                        // file system does not find the file.
                        if (null !== $name && \is_array($name[1]) && T_NAME_QUALIFIED === $name[1][0] && str_starts_with($name[1][1], self::NS)
                            && !\in_array($name[1][1], $classes, true)) {
                            $wrong[] = $where . ': no class ' . $name[1][1];
                        }

                        continue;
                    }
                    if (!\is_array($token) || T_STRING !== $token[0] || !isset($classes[$token[1]])) {
                        continue;
                    }
                    $class = $classes[$token[1]];
                    $before = self::previous($tokens, $k);
                    $after = self::next($tokens, $k);
                    if (null !== $before && \is_array($before[1]) && T_NEW === $before[1][0] && null !== $after && '(' === $after[1]) {
                        $wrong = [...$wrong, ...self::checkArguments($where, $class, '__construct', $tokens, $after[0])];
                        $assigned = self::previous($tokens, $before[0]);
                        $variable = null !== $assigned && '=' === $assigned[1] ? self::previous($tokens, $assigned[0]) : null;
                        if (null !== $variable && \is_array($variable[1]) && T_VARIABLE === $variable[1][0]) {
                            $types[$variable[1][1]] = $class;
                        }
                    } elseif (null !== $after && \is_array($after[1]) && T_DOUBLE_COLON === $after[1][0]) {
                        $wrong = [...$wrong, ...self::checkMember($where, $class, $tokens, $after[0])];
                    }
                }
                foreach ($tokens as $k => $token) {
                    if (!\is_array($token) || T_VARIABLE !== $token[0] || !isset($types[$token[1]])) {
                        continue;
                    }
                    $arrow = self::next($tokens, $k);
                    if (null !== $arrow && \is_array($arrow[1]) && T_OBJECT_OPERATOR === $arrow[1][0]) {
                        $wrong = [...$wrong, ...self::checkMember($where, $types[$token[1]], $tokens, $arrow[0])];
                    }
                }
            }
        }

        self::assertSame([], $wrong);
    }

    public function testEveryRelativeLinkLeadsToAFileAndItsHeading(): void
    {
        $root = self::root();
        $wrong = [];

        foreach (self::pages() as $page) {
            $text = (string) preg_replace('/^```.*?^```/ms', '', (string) file_get_contents($root . '/' . $page));
            preg_match_all('/\]\(([^)\s]+)\)/', $text, $links);
            foreach ($links[1] as $link) {
                if (1 === preg_match('#^[a-z]+:#', $link)) {
                    continue;
                }
                [$path, $anchor] = array_pad(explode('#', $link, 2), 2, null);
                $target = '' === $path ? $page : self::normalise(\dirname($page) . '/' . $path);
                if (!file_exists($root . '/' . $target)) {
                    $wrong[] = $page . ': ' . $link . ' leads to no file';
                } elseif (null !== $anchor && str_ends_with($target, '.md') && !\in_array($anchor, self::headings($root . '/' . $target), true)) {
                    $wrong[] = $page . ': ' . $link . ' leads to no heading';
                }
            }
        }

        self::assertSame([], $wrong);
    }

    /**
     * Named arguments, then the member itself, for `Class::member` or
     * `$built->member` at position $k.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<string>
     */
    private static function checkMember(string $where, string $class, array $tokens, int $k): array
    {
        $member = self::next($tokens, $k);
        if (null === $member || !\is_array($member[1]) || T_STRING !== $member[1][0]) {
            return [];
        }
        $name = $member[1][1];
        $open = self::next($tokens, $member[0]);
        if (null === $open || '(' !== $open[1]) {
            // A constant or an enum case, as in Revision::V2025_11_25.
            return \defined($class . '::' . $name) ? [] : [$where . ': no ' . $class . '::' . $name];
        }
        if (!method_exists($class, $name)) {
            return [$where . ': no method ' . $class . '::' . $name . '()'];
        }

        return self::checkArguments($where, $class, $name, $tokens, $open[0]);
    }

    /**
     * The named arguments of the call whose parenthesis opens at $open.
     *
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return list<string>
     */
    private static function checkArguments(string $where, string $class, string $method, array $tokens, int $open): array
    {
        if (!method_exists($class, $method)) {
            return [];
        }
        $parameters = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), (new \ReflectionMethod($class, $method))->getParameters());
        $wrong = [];
        $depth = 0;
        $count = \count($tokens);
        for ($k = $open; $k < $count; ++$k) {
            $token = $tokens[$k];
            if (\in_array($token, ['(', '[', '{'], true)) {
                ++$depth;

                continue;
            }
            if (\in_array($token, [')', ']', '}'], true)) {
                if (0 === --$depth) {
                    break;
                }

                continue;
            }
            if (1 !== $depth || !\is_array($token) || T_STRING !== $token[0]) {
                continue;
            }
            $colon = self::next($tokens, $k);
            if (null !== $colon && ':' === $colon[1] && !\in_array($token[1], $parameters, true)) {
                $wrong[] = $where . ': ' . $class . '::' . $method . '() has no parameter ' . $token[1];
            }
        }

        return $wrong;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return null|array{int, array{int, string, int}|string}
     */
    private static function next(array $tokens, int $k): ?array
    {
        for (++$k; isset($tokens[$k]); ++$k) {
            if (!\is_array($tokens[$k]) || T_WHITESPACE !== $tokens[$k][0]) {
                return [$k, $tokens[$k]];
            }
        }

        return null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     *
     * @return null|array{int, array{int, string, int}|string}
     */
    private static function previous(array $tokens, int $k): ?array
    {
        for (--$k; $k >= 0; --$k) {
            if (!\is_array($tokens[$k]) || T_WHITESPACE !== $tokens[$k][0]) {
                return [$k, $tokens[$k]];
            }
        }

        return null;
    }

    /**
     * @return list<array{int, string, int}|string>
     */
    private static function tokens(string $code): array
    {
        return array_values(array_filter(
            token_get_all("<?php\n" . $code),
            static fn($t): bool => !\is_array($t) || !\in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true),
        ));
    }

    /**
     * Short name => class, for every class, interface and enum of src/.
     *
     * @return array<string, class-string>
     */
    private static function libraryClasses(): array
    {
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen(self::root() . '/src/'), -4);
            $class = self::NS . str_replace('/', '\\', $relative);
            if (class_exists($class) || interface_exists($class)) {
                $classes[$file->getBasename('.php')] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return array<string, string> "page snippet n" => code
     */
    private static function snippets(): array
    {
        $all = [];
        foreach (self::snippetsByFile() as $file => $blocks) {
            foreach ($blocks as $i => $code) {
                $all[$file . ' snippet ' . ($i + 1)] = $code;
            }
        }

        return $all;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function snippetsByFile(): array
    {
        $all = [];
        foreach (self::pages() as $page) {
            preg_match_all('/^```php\n(.*?)^```/ms', (string) file_get_contents(self::root() . '/' . $page), $blocks);
            $all[$page] = $blocks[1];
        }

        return $all;
    }

    /**
     * The pages a reader starts from: the root documents and docs/.
     *
     * @return list<string>
     */
    private static function pages(): array
    {
        $root = self::root();
        $pages = array_map(
            static fn(string $path): string => substr($path, \strlen($root) + 1),
            [...(glob($root . '/*.md') ?: []), ...(glob($root . '/docs/*.md') ?: [])],
        );
        $pages[] = 'llms.txt';

        return $pages;
    }

    /**
     * The anchors GitHub gives the headings of a page.
     *
     * @return list<string>
     */
    private static function headings(string $file): array
    {
        $text = (string) preg_replace('/^```.*?^```/ms', '', (string) file_get_contents($file));
        preg_match_all('/^#{1,6}\s+(.+)$/m', $text, $titles);

        return array_map(
            static fn(string $title): string => str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', strtolower(trim($title)))),
            $titles[1],
        );
    }

    private static function normalise(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('' !== $part && '.' !== $part) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
