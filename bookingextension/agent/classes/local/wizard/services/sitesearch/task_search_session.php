<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Task-scoped core_search engine session (process-memory singleton seeding).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

/**
 * Makes `\core_search\base::get_document()` callable without a configured global-search engine by
 * seeding the protected `\core_search\manager::$instance` singleton with a manager wrapping the
 * {@see null_search_engine} — Core's own fixture pattern
 * (`search/tests/fixtures/testable_core_search.php:52`), reduced to pure process-memory seeding.
 *
 * Usage is ALWAYS bracketed:
 *
 *     task_search_session::begin();
 *     try {
 *         // ... $area->get_document($record) works here ...
 *     } finally {
 *         task_search_session::end();
 *     }
 *
 * Isolation guarantees (blueprint §11.26):
 * - Pure process-memory seeding — not a single DB, config or cache write happens.
 * - Parallel web requests and other cron workers are separate processes and see nothing.
 * - A process crash leaves zero traces.
 * - The only sharing point is follow-up tasks in the same cron process — covered by the mandatory
 *   `finally { end(); }`, which restores the previously active manager (usually none) and clears
 *   the document-factory statics.
 *
 * ⚠️ set_config pitfall: Core's fixture calls `set_config('enableglobalsearch', true)`
 * (`testable_core_search.php:59`). In PHPUnit that is rolled back; in production it would PERSIST
 * (site-wide search box + Core cron indexing!). This session must therefore NEVER call
 * `set_config()` — only the in-memory singleton is touched.
 */
class task_search_session extends \core_search\manager {
    /**
     * Stack of managers that were active when begin() was called (null = none). A stack keeps
     * nested sessions safe: every end() restores exactly what its begin() displaced.
     *
     * @var array
     */
    private static array $previousstack = [];

    /**
     * Start a session: remember any pre-existing manager singleton, then seed the singleton with a
     * session manager wrapping the null engine (via the constructor — this bypasses
     * `manager::instance()` and thus its schema-check/`lastschemacheck` config writes).
     *
     * @return void
     */
    public static function begin(): void {
        self::$previousstack[] = static::$instance;
        static::$instance = new static(new null_search_engine());
        // The document factory caches the document class per engine plugin; make sure a class
        // cached for a previously active engine is not reused for ours (and vice versa).
        \core_search\document_factory::clean_static();
    }

    /**
     * End a session: restore the manager singleton that begin() displaced (or null) and clear the
     * document-factory statics so no document class cached against the null engine leaks out.
     *
     * Safe to call without a matching begin() (no-op restore to null won't happen: it simply
     * returns), so a `finally { end(); }` can never corrupt state.
     *
     * @return void
     */
    public static function end(): void {
        if (self::$previousstack === []) {
            return;
        }
        static::$instance = array_pop(self::$previousstack);
        \core_search\document_factory::clean_static();
    }
}
