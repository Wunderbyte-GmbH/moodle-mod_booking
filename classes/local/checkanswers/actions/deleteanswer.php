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

namespace mod_booking\local\checkanswers\actions;
use mod_booking\local\checkanswers\checkanswers;
use mod_booking\singleton_service;
use stdClass;


/**
 * This action will delete the given answer of the user.
 *
 */
class deleteanswer {
    /**
     * ID of this check class.
     *
     * @var int
     */
    public static int $id = checkanswers::ACTION_DELETE;

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
     * Performs an action on a given answer.
     *
     * @param stdClass $answer
     *
     * @return bool
     *
     */
    public static function perform_action(stdClass $answer) {
        $settings = singleton_service::get_instance_of_booking_option_settings($answer->optionid);
        $option = singleton_service::get_instance_of_booking_option($settings->cmid, $answer->optionid);
        return $option->user_delete_response($answer->userid, false, false, false, false);
    }
}
