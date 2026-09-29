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

namespace bookingextension_todolist\event;

use core\event\base;

/**
 * Event fired when a todo list item is unchecked.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class todolist_item_unchecked extends base {
    /**
     * Init event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'bookingextension_todolist_item';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event:todolist_item_unchecked', 'bookingextension_todolist');
    }

    /**
     * Human-readable event description.
     *
     * @return string
     */
    public function get_description(): string {
        $itemtext = trim((string)($this->other['itemtext'] ?? ''));
        if ($itemtext === '') {
            $itemtext = '#' . (int)$this->objectid;
        }

        $a = (object)[
            'userid' => (int)$this->relateduserid,
            'itemtext' => $itemtext,
            'optionid' => (int)($this->other['optionid'] ?? 0),
        ];
        return get_string('event:todolist_item_unchecked_desc', 'bookingextension_todolist', $a);
    }
}
