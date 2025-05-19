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
 * Already booked condition (item has been booked).
 *
 * @package mod_booking
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_actions\action_types;

use coding_exception;
use context_module;
use mod_booking\bo_actions\booking_action;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookotheroptions extends booking_action {
    /**
     * Apply action.
     * @param stdClass $actiondata
     * @param ?int $userid
     * @return int // Status. 0 is do nothing, 1 aborts after application right away.
     */
    public function apply_action(stdClass $actiondata, int $userid = 0) {

        global $USER;
        if (!empty($userid)) {
            $user = singleton_service::get_instance_of_user($userid);
        } else {
            $user = $USER;
        }

        foreach ($actiondata->bookotheroptionsselect as $optionid) {
            $option = singleton_service::get_instance_of_booking_option($actiondata->cmid, $optionid);
            $option->user_submit_response(
                $user,
                0,
                0,
                $actiondata->bookotheroptionsforce,
                MOD_BOOKING_VERIFIED
            );
        }

        return 1; // We want to abort all other after actions.
    }

    /**
     * Add action to mform
     *
     * @param mixed $mform
     *
     * @return void
     *
     */
    public static function add_action_to_mform(&$mform) {
        global $DB;

        $mform->addElement('text', 'boactionname', get_string('boactionname', 'mod_booking'));

        $select = "SELECT bo.id optionid, bo.titleprefix, bo.text optionname, b.name instancename, c.shortname as coursename
                    FROM {booking_options} bo
                    LEFT JOIN {booking} b
                        ON bo.bookingid = b.id
                    LEFT JOIN {course} c ON b.course = c.id";
        $bookingoptionarray = [];
        if (
            $bookingoptionrecords = $DB->get_records_sql($select)
        ) {
            foreach ($bookingoptionrecords as $bookingoptionrecord) {
                if (!empty($bookingoptionrecord->titleprefix)) {
                    $bookingoptionarray[$bookingoptionrecord->optionid] =
                        "$bookingoptionrecord->titleprefix - $bookingoptionrecord->optionname " .
                            "($bookingoptionrecord->instancename, $bookingoptionrecord->coursename)";
                } else {
                    $bookingoptionarray[$bookingoptionrecord->optionid] =
                        "$bookingoptionrecord->optionname ($bookingoptionrecord->instancename, $bookingoptionrecord->coursename)";
                }
            }
        };

        $options = [
            'multiple' => true,
            'tags' => false,
            'noselectionstring' => get_string('choosedots'),
        ];

        $mform->addElement(
            'autocomplete',
            'bookotheroptionsselect',
            get_string('bookotheroptionsselect', 'mod_booking'),
            $bookingoptionarray,
            $options
        );
        $mform->setType('bookotheroptionsselect', PARAM_INT);

        $options = [
            MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_FORCE => get_string(
                'bookotheroptionsforcebooking',
                'booking'
            ),
            MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_NOOVERBOOKING => get_string(
                'bookotheroptionsnooverbooking',
                'booking'
            ),
            MOD_BOOKING_BO_SUBMIT_STATUS_BOOKOTHEROPTION_CONDITIONS_BLOCKING => get_string(
                'bookotheroptionsconditionsblock',
                'booking'
            ),
        ];
        $mform->addElement(
            'select',
            'bookotheroptionsforce',
            get_string('bookotheroptionsforce', 'mod_booking'),
            $options
        );
    }
}
