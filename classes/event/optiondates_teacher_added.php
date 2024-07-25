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
 * The optiondates_teacher_added event.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\event;

/**
 * The optiondates_teacher_added event.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondates_teacher_added extends \core\event\base {

    /**
     * Init
     *
     * @return void
     *
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'booking_optiondates_teachers';
    }

    /**
     * Get name
     *
     * @return string
     *
     */
    public static function get_name() {
        return get_string('optiondatesteacheradded', 'mod_booking');
    }

    /**
     * Get description
     *
     * @return string
     *
     */
    public function get_description() {
        return "Teacher with id '{$this->relateduserid}' was added to one specific date "
            . "in teaching journal of option with id '{$this->objectid}' by user with id '{$this->userid}'.";
    }

    /**
     * Get_url
     *
     * @return \moodle_url
     *
     */
    public function get_url() {
        return new \moodle_url('/mod/booking/optiondates_teachers_report.php',
                ['cmid' => $this->other['cmid'], 'optionid' => $this->objectid]);
    }
}
