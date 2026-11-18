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

use Example\Notes\NotesTools;
use Novalo\McpToolDiscovery\Gate\CallResult;
use Novalo\McpToolDiscovery\Gate\ToolGate;
use Novalo\McpToolDiscovery\Tool\Tool;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The gate of a Symfony application: who sees a tool is the voters'
 * answer, what a call may carry and what it does are the tools' own rules.
 * It reads the caller from the security token of the current request, so
 * it is built for that request, like the Discovery that holds it.
 */
final class SecurityGate implements ToolGate
{
    public function __construct(private readonly AuthorizationCheckerInterface $security, private readonly NotesTools $tools) {}

    public function visible(Tool $tool): bool
    {
        return $this->security->isGranted(ToolVoter::SEE, $tool);
    }

    public function authorize(Tool $tool, array $arguments): ?CallResult
    {
        return $this->tools->authorize($tool, $arguments);
    }

    public function call(Tool $tool, array $arguments): CallResult
    {
        return $this->tools->call($tool, $arguments);
    }
}
