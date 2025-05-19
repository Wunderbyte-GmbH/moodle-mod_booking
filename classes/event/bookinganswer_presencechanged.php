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
 * The bookinganswer_presencechanged event.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

use mod_booking\singleton_service;
use mod_booking\booking;
use stdClass;

/**
 * The bookinganswer_presencechanged event class.
 *
 * @property-read array $other { Extra information about event. Acesss an instance of the booking module }
 * @since Moodle 2.7
 * @copyright 2024 Wunderbyte
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookinganswer_presencechanged extends \core\event\base {
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
        return get_string('presencechanged', 'booking');
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
            'presenceold' => $this->data['other']['presenceold'],
            'presencenew' => $this->data['other']['presencenew'],
        ];
        $ruser = singleton_service::get_instance_of_user((int) $data->relateduserid);
        $possiblepresences = booking::get_array_of_possible_presence_statuses();

        $a = new stdClass();
        $a->relateduser = $ruser->firstname . " " . $ruser->lastname . " (ID: " . $data->relateduserid . ")";
        $a->presenceold = $possiblepresences[$data->presenceold];
        $a->presencenew = $possiblepresences[$data->presencenew];

        return get_string('presencechangedinfo', 'mod_booking', $a);
    }

    /**
     * Get_url
     *
     * @return \moodle_url
     *
     */
    public function get_url() {
        return new \moodle_url('/mod/booking/view.php', ['id' => $this->contextinstanceid]);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }
}
