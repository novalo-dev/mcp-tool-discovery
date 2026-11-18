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

namespace Example\Notes\Symfony;

use Novalo\McpToolDiscovery\Tool\Tool;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may know a tool exists, decided where a Symfony application decides
 * every other permission. The gate asks it through isGranted(), so the
 * role hierarchy and any other voter of the application apply to tools too.
 *
 * @extends Voter<string, Tool>
 */
final class ToolVoter extends Voter
{
    public const SEE = 'MCP_TOOL_SEE';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::SEE && $subject instanceof Tool;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Only an admin may delete, as in NotesGate; every other tool is
        // for anyone the firewall let in.
        return $subject->name !== 'notes_delete' || $this->decisions->decide($token, ['ROLE_ADMIN']);
    }
}
