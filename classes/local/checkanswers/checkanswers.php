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

namespace mod_booking\local\checkanswers;
use core\task\manager;
use core_component;
use Exception;
use mod_booking\booking_answers;
use mod_booking\singleton_service;
use mod_booking\task\check_answers;
use stdClass;


/**
 * This class will check if booking answers are still valid.
 * There are a number of different checks in the subclasses.
 *
 */
class checkanswers {
    /**
     * CHECK_ALL
     *
     * @var int
     */
    public const CHECK_ALL = 1;

    /**
     * CHECK_COURSE_ENROLLMENT
     *
     * @var int
     */
    public const CHECK_COURSE_ENROLLMENT = 2;

    /**
     * CHECK_CM_VISIBILITY
     *
     * @var int
     */
    public const CHECK_CM_VISIBILITY = 3;

    /**
     * ACTION_DELETE
     *
     * @var int
     */
    public const ACTION_DELETE = 1;

    /**
     * Creates an ad-hoc task for each booking option.
     * @param int $contextid The context id of the booking instance.
     * @param int $check Which checks to perform.
     * @param int $action Which action to perform.
     */
    public static function create_bookinganswers_check_tasks(
        int $contextid,
        int $check = self::CHECK_ALL,
        int $action = self::ACTION_DELETE
    ) {
        global $DB;

        $sql = "SELECT bo.id
                FROM {booking_options} bo
                JOIN {booking} b ON bo.bookingid = b.id
                JOIN {course_modules} cm ON cm.instance = b.id AND cm.module = (
                    SELECT id FROM {modules} WHERE name = 'booking'
                )
                JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = " . CONTEXT_MODULE . "
                WHERE ctx.path LIKE (SELECT path FROM {context} WHERE id = :contextid) || '%'";

        $params = ['contextid' => $contextid];

        $bookingoptions = $DB->get_records_sql($sql, $params);

        foreach ($bookingoptions as $option) {
            $task = new check_answers();
            $task->set_custom_data(
                [
                    'optionid' => $option->id,
                    'check' => $check,
                    'action' => $action,
                ]
            );
            // For security, we schedule the taks five minutes in the future.
            // This will give the possiblity to cancel the task if needed.
            $task->set_next_run_time(strtotime('- 5 minutes'));
            manager::queue_adhoc_task($task);
        }
    }

    /**
     * Processes a single booking option and removes invalid booking answers.
     * This function will get the booking answers of all the booking instances affected and check them.
     * The check will return information about the result of the check.
     *
     * @param int $optionid The booking option ID.
     * @param int $action The check to perform.
     * @param int $action The action to take.
     *
     * @return array
     *
     */
    public static function process_booking_option(
        int $optionid,
        int $check,
        int $action
    ): array {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $ba = singleton_service::get_instance_of_booking_answers($settings);

        $log = [];

        foreach ($ba->answers as $answer) {
            $checkresult = self::check_answer($answer, $check);

            if (!empty($checkresult)) {
                $actionresult = self::perform_action($answer, $action);
            }
            $log[$answer->userid] = [
                'check' => $checkresult,
                'action' => $actionresult ?? false,
            ];
        }

        return $log;
    }

    /**
     * Get all possible checks and perform those which are needed.
     *
     * @param stdClass $answer
     * @param int $check
     *
     * @return array
     *
     */
    private static function check_answer(stdClass $answer, int $check, bool $breakonfirst = true): array {
        $checks = core_component::get_component_classes_in_namespace(
            'mod_booking',
            'local\\checkanswers\\checks'
        );

        $checks = array_keys($checks);

        $result = [];

        // Sort the classes based on their static $id property.
        usort($checks, function ($a, $b) {
            return $a::$id <=> $b::$id;
        });

        foreach ($checks as $checkclass) {
            if (
                $check == self::CHECK_ALL
                || $checkclass::get_id() == $check
            ) {
                if (!$checkclass::check_answer($answer)) {
                    $result[] = $checkclass::get_id();
                    if ($breakonfirst) {
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get all possible actions and perform the one needed.
     *
     * @param stdClass $answer
     * @param int $check
     *
     * @return array
     *
     */
    private static function perform_action(stdClass $answer, int $action): array {
        $actions = core_component::get_component_classes_in_namespace(
            'mod_booking',
            'local\\checkanswers\\actions'
        );

        $result = [];

        foreach ($actions as $actionclass => $namespace) {
            if (
                $actionclass::get_id() == $action
            ) {
                if ($actionclass::perform_action($answer)) {
                    $result[] = $actionclass::get_id();
                }
            }
        }
        return $result;
    }
}
