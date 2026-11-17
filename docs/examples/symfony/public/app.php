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

/*
 * The notes server of http-server.php, as a Symfony application: the
 * library's parts are services of the container, the transport is one
 * controller, and the Discovery is built for each request. It answers
 * exactly as the other HTTP servers do, from the same http.php.
 *
 * Named app.php rather than index.php: hosts that add an empty index.php
 * to every folder of a package would overwrite it.
 *
 * Install: composer install, in docs/examples/symfony.
 * Run it:  php -S 127.0.0.1:8080 docs/examples/symfony/public/app.php
 * Call it: as the server of http-server.php, at http://127.0.0.1:8080/mcp.
 */

use Example\Notes\Symfony\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel('prod', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
