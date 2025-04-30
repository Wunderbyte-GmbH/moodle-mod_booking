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
 * The bookinganswer_notesedited event.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

use mod_booking\singleton_service;
use mod_booking\booking;
use stdClass;
use moodle_url;
use coding_exception;

/**
 * The bookinganswer_notesedited event.
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookinganswer_notesedited extends \core\event\base {
    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking_answers';
    }

    /**
     * Get name
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('notesedited', 'mod_booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {
        $data = (object) [
            'userid' => $this->userid,
            'relateduserid' => $this->data['relateduserid'],
            'objectid' => $this->objectid,
            'notesold' => $this->data['other']['notesold'],
            'notesnew' => $this->data['other']['notesnew'],
        ];
        $relateduser = singleton_service::get_instance_of_user((int) $data->relateduserid);

        $a = new stdClass();
        $a->relateduser = $relateduser->firstname . " " . $relateduser->lastname . " (ID: " . $data->relateduserid . ")";
        $a->notesold = $data->notesold;
        $a->notesnew = $data->notesnew;

        return get_string('noteseditedinfo', 'mod_booking', $a);
    }

    /**
     * Get_url.
     * @return moodle_url
     */
    public function get_url() {
        return new moodle_url('/mod/booking/report2.php');
    }

    /**
     * Custom validation.
     * @throws coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
