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
 * Per-area indexing cursor state for the incremental site-content indexer.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\sitesearch;

/**
 * Reads/writes `{bx_agent_search_state}` — one cursor row per (areakey, emodel, edims).
 *
 * The cursor is the `modified` timestamp of the last successfully indexed document of an area
 * (Core's `…_lastindexrun` pattern, `search/classes/manager.php:1281`). It is runtime state written
 * by the index task, deliberately separate from the admin-written governance config (different
 * writers, different lifecycle — blueprint §2.1 Nachtrag).
 */
class site_content_state_repository {
    /** State table (UNIQUE areakey, emodel, edims). */
    private const TABLE = 'bx_agent_search_state';

    /**
     * Cursor column. Named `indexcursor` because `cursor` is a MySQL/MariaDB reserved word and
     * Moodle DML emits unquoted column names.
     */
    private const CURSOR_FIELD = 'indexcursor';

    /**
     * The stored cursor for an area variant, or 0 when the area was never indexed.
     *
     * @param string $areakey Search area id (e.g. 'mod_page-activity').
     * @param string $emodel Embedding model.
     * @param int $edims Embedding dimensions.
     * @return int
     */
    public function get_cursor(string $areakey, string $emodel, int $edims): int {
        global $DB;
        $record = $DB->get_record(self::TABLE, [
            'areakey' => $areakey,
            'emodel' => $emodel,
            'edims' => $edims,
        ]);
        return $record ? (int)$record->{self::CURSOR_FIELD} : 0;
    }

    /**
     * Upsert the cursor for an area variant.
     *
     * @param string $areakey Search area id.
     * @param string $emodel Embedding model.
     * @param int $edims Embedding dimensions.
     * @param int $cursor Modified timestamp of the last successfully indexed document.
     * @return void
     */
    public function set_cursor(string $areakey, string $emodel, int $edims, int $cursor): void {
        global $DB;
        $params = ['areakey' => $areakey, 'emodel' => $emodel, 'edims' => $edims];
        $record = $DB->get_record(self::TABLE, $params);
        if ($record) {
            $record->{self::CURSOR_FIELD} = $cursor;
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
            return;
        }
        $DB->insert_record(self::TABLE, (object)($params + [
            self::CURSOR_FIELD => $cursor,
            'timemodified' => time(),
        ]));
    }

    /**
     * Remove the cursor row of an area variant (disable/prune path).
     *
     * @param string $areakey Search area id.
     * @param string $emodel Embedding model.
     * @param int $edims Embedding dimensions.
     * @return void
     */
    public function delete(string $areakey, string $emodel, int $edims): void {
        global $DB;
        $DB->delete_records(self::TABLE, [
            'areakey' => $areakey,
            'emodel' => $emodel,
            'edims' => $edims,
        ]);
    }
}
