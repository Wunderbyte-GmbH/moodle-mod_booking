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
 * No-op core_search engine backing the task-scoped engine session.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

/**
 * A null search engine: every operation is a no-op.
 *
 * Its only purpose is to satisfy `\core_search\document_factory::instance()` (which resolves the
 * document class from `manager::instance()->get_engine()`) while {@see task_search_session} is
 * active, so that a search area's own `get_document()` can be called without a configured
 * global-search engine.
 *
 * Only the abstract methods of {@see \core_search\engine} are implemented, all as no-ops.
 * `get_document_classname()` is deliberately NOT overridden: the base default falls back to
 * `\core_search\document` (there is no `bookingextension_agent\document`), which is exactly the
 * plain document class the areas expect.
 */
class null_search_engine extends \core_search\engine {
    /**
     * Never returns results, so the total count is always zero.
     *
     * @return int
     */
    public function get_query_total_count() {
        return 0;
    }

    /**
     * There is no server; the null engine is always "ready".
     *
     * @return bool
     */
    public function is_server_ready() {
        return true;
    }

    /**
     * Documents are never stored.
     *
     * @param \core_search\document $document
     * @param bool $fileindexing
     * @return bool Always false (document skipped).
     */
    public function add_document($document, $fileindexing = false) {
        return false;
    }

    /**
     * Queries never match anything.
     *
     * @param \stdClass $filters
     * @param \stdClass|array|bool $accessinfo
     * @param int $limit
     * @return array Always empty.
     */
    public function execute_query($filters, $accessinfo, $limit = 0) {
        return [];
    }

    /**
     * Nothing is stored, so nothing is deleted.
     *
     * @param string|null $areaid
     * @return void
     */
    public function delete($areaid = null) {
        // No-op: the null engine stores nothing.
    }
}
