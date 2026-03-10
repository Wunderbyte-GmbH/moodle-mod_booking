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
 * The bookinganswer_slotmoved event.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

use coding_exception;
use moodle_url;

/**
 * Event raised when a slot booking answer is moved.
 */
class bookinganswer_slotmoved extends \core\event\base {
    /**
     * Init event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking_answers';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('slot_move_event_name', 'mod_booking');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        $oldslots = $this->normalise_slots($this->data['other']['oldslots'] ?? []);
        $newslots = $this->normalise_slots($this->data['other']['newslots'] ?? []);

        $oldslotmap = [];
        foreach ($oldslots as $slot) {
            $oldslotmap[$slot['start'] . ':' . $slot['end']] = true;
        }

        $newslotmap = [];
        foreach ($newslots as $slot) {
            $newslotmap[$slot['start'] . ':' . $slot['end']] = true;
        }

        $removed = [];
        foreach ($oldslots as $slot) {
            $key = $slot['start'] . ':' . $slot['end'];
            if (isset($newslotmap[$key])) {
                continue;
            }
            $removed[] = $slot;
        }

        $added = [];
        foreach ($newslots as $slot) {
            $key = $slot['start'] . ':' . $slot['end'];
            if (isset($oldslotmap[$key])) {
                continue;
            }
            $added[] = $slot;
        }

        // Fall back to full old/new slot sets if we cannot determine a proper delta.
        if (empty($removed) && empty($added)) {
            $removed = $oldslots;
            $added = $newslots;
        }

        $a = (object) [
            'adminid' => $this->userid,
            'userid' => $this->relateduserid,
            'optionid' => $this->data['other']['optionid'] ?? 0,
            'baid' => $this->objectid,
            'oldslots' => $this->format_slot_list($removed),
            'newslots' => $this->format_slot_list($added),
            'slotcount' => max(count($removed), count($added)),
            'reason' => (string)($this->data['other']['reason'] ?? ''),
        ];

        $stringid = $a->slotcount === 1 ? 'slot_move_event_description_single' : 'slot_move_event_description_multi';
        return get_string($stringid, 'mod_booking', $a);
    }

    /**
     * Normalize slot arrays from event payload.
     *
     * @param mixed $slots
     * @return array<int, array{start:int,end:int}>
     */
    private function normalise_slots($slots): array {
        if (!is_array($slots)) {
            return [];
        }

        $normalized = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $start = (int)($slot['start'] ?? 0);
            $end = (int)($slot['end'] ?? 0);
            if ($start <= 0 || $end <= $start) {
                continue;
            }

            $normalized[$start . ':' . $end] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        return array_values($normalized);
    }

    /**
     * Format one or more slots for event description text.
     *
     * @param array<int, array{start:int,end:int}> $slots
     * @return string
     */
    private function format_slot_list(array $slots): string {
        if (empty($slots)) {
            return '-';
        }

        $items = [];
        foreach ($slots as $slot) {
            $items[] = userdate((int)$slot['start'], get_string('strftimedatetime', 'langconfig'))
                . ' - '
                . userdate((int)$slot['end'], get_string('strftimedatetime', 'langconfig'));
        }

        return implode('; ', $items);
    }

    /**
     * URL shown in logs.
     *
     * @return moodle_url
     */
    public function get_url() {
        return new moodle_url('/mod/booking/report.php', [
            'id' => $this->contextinstanceid,
            'optionid' => (int)($this->data['other']['optionid'] ?? 0),
        ]);
    }

    /**
     * Validate required event data.
     *
     * @throws coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new coding_exception('The \'relateduserid\' must be set.');
        }

        if (!isset($this->data['other']['optionid'])) {
            throw new coding_exception('The \'other[optionid]\' must be set.');
        }
    }
}
