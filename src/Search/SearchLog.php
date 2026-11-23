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

namespace Novalo\McpToolDiscovery\Search;

use Novalo\McpToolDiscovery\Tool\Tool;

/**
 * Where the host keeps what its clients searched and what they ran after.
 *
 * The library writes nothing itself: what is kept, for how long and where is
 * the host's decision, as the words of a request may hold what a person
 * wrote. Read together, the two calls tell which requests found nothing,
 * which words no tool uses, and which tool the client ran instead of the
 * first one found: what synonyms, new titles or the benchmark need next.
 *
 * Both calls are made during the client's request, so a log should be cheap
 * and should not throw: an exception is not caught, and fails the call.
 */
interface SearchLog
{
    /**
     * A search a mode ran over the tools the caller may see, with its answer.
     *
     * The result's searchId is set whenever the request held a word the
     * tools use; ran() receives it back when the client keeps it.
     */
    public function searched(SearchQuery $query, SearchResult $result): void;

    /**
     * A tool the caller may see, authorized by the host, that ran.
     *
     * Never called for a tool the caller may not see or one the host
     * refused, so the log learns nothing the gate keeps from the caller.
     *
     * @param string|null $searchId the search_id the client gave back with the
     *        run, null when it gave none or one no search could have made
     */
    public function ran(Tool $tool, bool $succeeded, ?string $searchId): void;
}
