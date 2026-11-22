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

namespace Novalo\McpToolDiscovery\Tests\Sdk;

/**
 * Runs docs/examples/sdk/attributes.php, where the SDK finds the tools by
 * their #[McpTool] attribute and builds their class through its container.
 */
final class SdkAttributesTest extends RegistryServerTestCase
{
    protected static function script(): string
    {
        return dirname(__DIR__, 2) . '/docs/examples/sdk/attributes.php';
    }

    // What the SDK read from the method: the description from its doc
    // comment, the parameter's from its @param, the title from the attribute.
    public function testSearchGivesWhatTheSdkReadFromTheMethod(): void
    {
        $replies = self::session([
            self::INITIALIZE,
            self::INITIALIZED,
            ['method' => 'tools/call', 'params' => ['name' => 'notes_search_tools', 'arguments' => ['names' => ['notes_get']]]],
        ]);

        $found = $replies[3]['result']['structuredContent']['tools'][0];
        self::assertSame('Read a note', $found['title']);
        self::assertSame('Returns the full text of one note.', $found['description']);
        self::assertSame('the id of the note, as the list gives it', $found['inputSchema']['properties']['id']['description']);
    }
}
