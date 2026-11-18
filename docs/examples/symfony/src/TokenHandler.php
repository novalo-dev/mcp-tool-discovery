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

use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

use const Example\Notes\TOKENS;

/**
 * Who a bearer token names, for the firewall. A real application checks an
 * OAuth access token here (Symfony ships handlers for OpenID Connect); the
 * firewall then knows the caller before the controller runs, and nothing
 * else reads the header.
 */
final class TokenHandler implements AccessTokenHandlerInterface
{
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $user = TOKENS[$accessToken] ?? null;
        if ($user === null) {
            throw new BadCredentialsException('Invalid token.');
        }

        return new UserBadge($user);
    }
}
