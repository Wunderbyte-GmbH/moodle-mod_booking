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
 * The cartstore class handles the in and out of the cache.
 *
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\checkanswers\checks;
use context_course;
use mod_booking\local\checkanswers\checkanswers;
use mod_booking\singleton_service;
use stdClass;


/**
 * This class will check if booking answers are still valid.
 * There are a number of different checks in the subclasses.
 *
 */
class enrolledincourse {
    /**
     * ID of this check class.
     *
     * @var int
     */
    public static int $id = checkanswers::CHECK_COURSE_ENROLLMENT;

    /**
     * Returns the id of this Class.
     *
     * @return int
     *
     */
    public static function get_id() {
        return self::$id;
    }

    /**
     * Check if the user is still enrolled in the course.
     *
     * @param stdClass $answer
     *
     * @return bool
     *
     */
    public static function check_answer(stdClass $answer) {

        $settings = singleton_service::get_instance_of_booking_option_settings($answer->optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);

        return is_enrolled(context_course::instance($bookingsettings->course), $answer->userid);
    }
}
