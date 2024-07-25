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
 * The mod_booking taecher added event.
 *
 * @package mod_booking
 * @copyright 2014 David Bogner http://www.edulabs.org
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\event;

use mod_booking\singleton_service;
use moodle_url;

/**
 * The mod_booking report viewed event class.
 *
 * @package mod_booking
 * @since Moodle 2.7
 * @copyright 2014 David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class teacher_removed extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking_teachers';
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventteacherremoved', 'mod_booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {
        return "The user with id '$this->userid' removed teacher with user id
                '$this->relateduserid' from the booking option with the option id
        '$this->objectid'";
    }

    /**
     * Returns relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        global $CFG;

        $optionid = $this->objectid;
        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        return new moodle_url($CFG->wwwroot . '/mod/booking/view.php', [
            'id' => $optionsettings->cmid,
            'optionid' => $optionid,
            'whichview' => 'showonlyone',
        ]);
    }
}
