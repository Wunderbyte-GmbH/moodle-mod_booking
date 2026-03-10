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
 * Store selected slot booking values in cache between prepage and final booking.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\mobile;

use cache;

/**
 * Cache helper for selected slot booking data.
 */
class slotbookingstore {
    /** @var int */
    protected $userid = 0;

    /** @var int */
    protected $optionid = 0;

    /** @var string */
    protected $cachekey = '';

    /** @var cache */
    protected $cache;

    /**
     * Constructor.
     *
     * @param int $userid user id
     * @param int $optionid option id
     */
    public function __construct(int $userid, int $optionid) {
        $this->userid = $userid;
        $this->optionid = $optionid;
        $this->cache = cache::make('mod_booking', 'customformuserdata');
        $this->cachekey = $userid . '_' . $optionid . '_slotbooking';
    }

    /**
     * Read selected slot data.
     *
     * @return object|false
     */
    public function get_slotbooking_data() {
        return $this->cache->get($this->cachekey);
    }

    /**
     * Persist selected slot data.
     *
     * @param object $data selected values
     * @return void
     */
    public function set_slotbooking_data(object $data): void {
        $this->cache->set($this->cachekey, $data);
    }

    /**
     * Delete selected slot data.
     *
     * @return void
     */
    public function delete_slotbooking_data(): void {
        $this->cache->delete($this->cachekey);
    }

    /**
     * Parse selected slot string into unix timestamps.
     *
     * @param object|array|null $data cached data
     * @return array{0:int,1:int}
     */
    public function get_selected_range($data): array {
        $ranges = $this->get_selected_ranges($data);
        if (empty($ranges)) {
            return [0, 0];
        }

        return $ranges[0];
    }

    /**
     * Parse selected slot string into unix timestamp ranges.
     *
     * @param object|array|null $data cached data
     * @return array<int, array{0:int,1:int}>
     */
    public function get_selected_ranges($data): array {
        if (empty($data)) {
            return [];
        }

        $slotselection = '';
        if (is_object($data) && !empty($data->slot_selection)) {
            $slotselection = (string)$data->slot_selection;
        } else if (is_array($data) && !empty($data['slot_selection'])) {
            $slotselection = (string)$data['slot_selection'];
        }

        if (empty($slotselection)) {
            return [];
        }

        $ranges = [];
        $entries = array_filter(array_map('trim', explode(',', $slotselection)), function ($entry) {
            return $entry !== '';
        });

        foreach ($entries as $entry) {
            if (strpos($entry, ':') === false) {
                continue;
            }

            [$start, $end] = array_map('intval', explode(':', $entry, 2));
            if ($start <= 0 || $end <= 0 || $end <= $start) {
                continue;
            }
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }

    /**
     * Parse teacher selection map from cache payload.
     *
     * @param object|array|null $data cached data
     * @return array<string, int[]>
     */
    public function get_selected_teachers_by_slot($data): array {
        if (empty($data)) {
            return [];
        }

        $raw = '';
        if (is_object($data) && isset($data->slot_teacher_selection)) {
            $raw = (string)$data->slot_teacher_selection;
        } else if (is_array($data) && isset($data['slot_teacher_selection'])) {
            $raw = (string)$data['slot_teacher_selection'];
        }

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = [];
        foreach ($decoded as $slotkey => $teacherids) {
            if (!is_string($slotkey) || strpos($slotkey, ':') === false || !is_array($teacherids)) {
                continue;
            }

            $clean = array_values(array_unique(array_filter(array_map('intval', $teacherids), static function (int $id): bool {
                return $id > 0;
            })));

            $result[$slotkey] = $clean;
        }

        return $result;
    }
}
