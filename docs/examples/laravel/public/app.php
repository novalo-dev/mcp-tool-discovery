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

use Illuminate\Http\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->handleRequest(Request::capture());
