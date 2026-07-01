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
 * The bookinganswer_slotcancelled event.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

use coding_exception;
use moodle_url;

/**
 * Event raised when a slot booking answer is cancelled.
 */
class bookinganswer_slotcancelled extends \core\event\base {
    /**
     * Init event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'booking_answers';
    }

    /**
     * Event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('slot_cancelled_event_name', 'mod_booking');
    }

    /**
     * Event description.
     *
     * @return string
     */
    public function get_description() {
        $a = (object)[
            'adminid' => $this->userid,
            'userid' => $this->relateduserid,
            'optionid' => (int)($this->data['other']['optionid'] ?? 0),
            'baid' => $this->objectid,
            'slotcount' => (int)($this->data['other']['slotcount'] ?? 0),
        ];

        return get_string('slot_cancelled_event_description', 'mod_booking', $a);
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
