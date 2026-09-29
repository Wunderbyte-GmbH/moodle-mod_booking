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

namespace bookingextension_todolist\local;

use mod_booking\singleton_service;

/**
 * Helper methods for todo list logic.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class todolist_helper {
    /**
     * Parse textarea value into trimmed non-empty lines.
     *
     * @param string $rawtext
     * @return array
     */
    public static function parse_lines(string $rawtext): array {
        $lines = preg_split('/\R/u', $rawtext);
        $result = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $result[] = $line;
        }

        return $result;
    }

    /**
     * Get todo items for an option.
     *
     * @param int $optionid
     * @return array
     */
    public static function get_items_for_option(int $optionid): array {
        global $DB;

        return $DB->get_records('bookingextension_todolist_item', ['optionid' => $optionid], 'sortorder ASC, id ASC');
    }

    /**
     * Return only item texts from records in display order.
     *
     * @param array $records
     * @return array
     */
    public static function texts_from_records(array $records): array {
        return array_values(array_map(static fn($record) => trim((string)$record->text), $records));
    }

    /**
     * Check if option has any completed items.
     *
     * @param int $optionid
     * @return bool
     */
    public static function has_completed_items(int $optionid): bool {
        global $DB;

        return $DB->record_exists('bookingextension_todolist_item', ['optionid' => $optionid, 'status' => 1]);
    }

    /**
     * Check if all todo items are completed.
     * Returns false when there are no items.
     *
     * @param int $optionid
     * @return bool
     */
    public static function todolist_completed(int $optionid): bool {
        global $DB;

        $all = $DB->count_records('bookingextension_todolist_item', ['optionid' => $optionid]);
        if ($all === 0) {
            return false;
        }

        $completed = $DB->count_records('bookingextension_todolist_item', ['optionid' => $optionid, 'status' => 1]);
        return $all === $completed;
    }

    /**
     * Keep compatibility hook used by save/toggle flows.
     *
     * Todo list completion is now derived directly from item records
     * (no persisted column on booking_options).
     *
     * @param int $optionid
     * @return void
     */
    public static function refresh_option_todolist_status(int $optionid): void {
        // Intentionally left blank: status is computed on demand.
    }

    /**
     * Save textarea list by preserving unchanged rows and applying minimal changes.
     *
     * @param int $optionid
     * @param array $lines
     * @param int $userid
     * @return void
     */
    public static function replace_items(int $optionid, array $lines, int $userid): void {
        global $DB;

        $existingrecords = array_values(self::get_items_for_option($optionid));
        $existingtexts = self::texts_from_records($existingrecords);
        $linecount = count($lines);

        // Find unchanged items in order and keep those DB rows untouched except sortorder.
        $anchors = self::build_lcs_anchors($existingtexts, $lines);

        $handledold = [];
        $time = time();
        $prevoldanchor = -1;
        $prevnewanchor = -1;

        foreach ($anchors as $anchor) {
            $oldanchor = (int)$anchor['old'];
            $newanchor = (int)$anchor['new'];

            self::sync_unanchored_segment(
                $existingrecords,
                $lines,
                $optionid,
                $userid,
                $time,
                $prevoldanchor + 1,
                $oldanchor - 1,
                $prevnewanchor + 1,
                $newanchor - 1,
                $handledold
            );

            $anchoredrecord = $existingrecords[$oldanchor];
            if ((int)$anchoredrecord->sortorder !== $newanchor) {
                $anchoredrecord->sortorder = $newanchor;
                $DB->update_record('bookingextension_todolist_item', $anchoredrecord);
            }
            $handledold[$oldanchor] = true;

            $prevoldanchor = $oldanchor;
            $prevnewanchor = $newanchor;
        }

        self::sync_unanchored_segment(
            $existingrecords,
            $lines,
            $optionid,
            $userid,
            $time,
            $prevoldanchor + 1,
            count($existingrecords) - 1,
            $prevnewanchor + 1,
            $linecount - 1,
            $handledold
        );

        self::refresh_option_todolist_status($optionid);
    }

    /**
     * Synchronize one unmatched segment between two kept anchors.
     *
     * @param array $existingrecords
     * @param array $lines
     * @param int $optionid
     * @param int $userid
     * @param int $time
     * @param int $oldstart
     * @param int $oldend
     * @param int $newstart
     * @param int $newend
     * @param array $handledold
     * @return void
     */
    private static function sync_unanchored_segment(
        array $existingrecords,
        array $lines,
        int $optionid,
        int $userid,
        int $time,
        int $oldstart,
        int $oldend,
        int $newstart,
        int $newend,
        array &$handledold
    ): void {
        global $DB;

        $oldindexes = [];
        if ($oldstart <= $oldend) {
            for ($i = $oldstart; $i <= $oldend; $i++) {
                $oldindexes[] = $i;
            }
        }

        $newindexes = [];
        if ($newstart <= $newend) {
            for ($i = $newstart; $i <= $newend; $i++) {
                $newindexes[] = $i;
            }
        }

        $shared = min(count($oldindexes), count($newindexes));

        // Reuse existing rows first: update text when needed and always set new sortorder.
        for ($i = 0; $i < $shared; $i++) {
            $oldidx = $oldindexes[$i];
            $newidx = $newindexes[$i];
            $record = $existingrecords[$oldidx];
            $newtext = $lines[$newidx];

            $changed = false;
            if (trim((string)$record->text) !== $newtext) {
                $record->text = $newtext;
                $record->status = 0;
                $record->completed_by = null;
                $record->completed_at = null;
                $changed = true;
            }
            if ((int)$record->sortorder !== $newidx) {
                $record->sortorder = $newidx;
                $changed = true;
            }

            if ($changed) {
                $DB->update_record('bookingextension_todolist_item', $record);
            }
            $handledold[$oldidx] = true;
        }

        // Remove leftover old rows in this segment.
        for ($i = $shared; $i < count($oldindexes); $i++) {
            $oldidx = $oldindexes[$i];
            $record = $existingrecords[$oldidx];
            $DB->delete_records('bookingextension_todolist_item', ['id' => (int)$record->id]);
            $handledold[$oldidx] = true;
        }

        // Insert additional new rows for this segment.
        for ($i = $shared; $i < count($newindexes); $i++) {
            $newidx = $newindexes[$i];
            $record = (object)[
                'optionid' => $optionid,
                'text' => $lines[$newidx],
                'sortorder' => $newidx,
                'status' => 0,
                'completed_by' => null,
                'completed_at' => null,
                'created_by' => $userid,
                'created_at' => $time,
            ];
            $DB->insert_record('bookingextension_todolist_item', $record);
        }
    }

    /**
     * Build list of unchanged anchors (old index + new index) using LCS.
     *
     * @param array $oldtexts
     * @param array $newtexts
     * @return array
     */
    private static function build_lcs_anchors(array $oldtexts, array $newtexts): array {
        $n = count($oldtexts);
        $m = count($newtexts);
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                if ($oldtexts[$i] === $newtexts[$j]) {
                    $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i + 1][$j], $dp[$i][$j + 1]);
                }
            }
        }

        $anchors = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($oldtexts[$i] === $newtexts[$j]) {
                $anchors[] = ['old' => $i, 'new' => $j];
                $i++;
                $j++;
                continue;
            }

            if ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $anchors;
    }

    /**
     * Build render context for Mustache template.
     *
     * @param int $optionid
     * @param bool $cancheck
     * @param int $cmid
     * @return array
     */
    public static function get_template_context(int $optionid, bool $cancheck, int $cmid): array {
        $records = self::get_items_for_option($optionid);
        $items = [];

        foreach ($records as $record) {
            $checked = ((int)$record->status === 1);
            $items[] = [
                'id' => (int)$record->id,
                'optionid' => $optionid,
                'text' => format_string((string)$record->text),
                'checked' => $checked,
                'checkedattr' => $checked ? 'checked' : '',
                'readonly' => !$cancheck,
            ];
        }

        return [
            'cmid' => $cmid,
            'optionid' => $optionid,
            'cancheck' => $cancheck,
            'readonly' => !$cancheck,
            'hasitems' => !empty($items),
            'items' => $items,
            'sesskey' => sesskey(),
        ];
    }

    /**
     * Render todo list as plain text for placeholders.
     *
     * @param int $optionid
     * @return string
     */
    public static function format_as_plaintext(int $optionid): string {
        $records = self::get_items_for_option($optionid);
        if (empty($records)) {
            return '';
        }

        $lines = [];
        foreach ($records as $record) {
            $prefix = ((int)$record->status === 1) ? "[x]" : "[ ]";
            $lines[] = $prefix . ' ' . trim((string)$record->text);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Check if todo list is enabled for option.
     *
     * @param int $optionid
     * @return bool
     */
    public static function is_enabled_for_option(int $optionid): bool {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $json = (string)($settings->json ?? '');
        if ($json === '') {
            return false;
        }

        $jsonobject = json_decode($json);
        if (!is_object($jsonobject) || !isset($jsonobject->enable_todolist)) {
            return false;
        }

        return !empty($jsonobject->enable_todolist);
    }
}
