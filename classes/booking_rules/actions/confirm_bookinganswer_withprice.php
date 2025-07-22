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

namespace mod_booking\booking_rules\actions;

use mod_booking\booking_option;
use mod_booking\booking_rules\booking_rule_action;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * action how to identify concerned users by matching booking option field and user profile field.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class confirm_bookinganswer_withprice implements booking_rule_action {
    /**
     * Load json data from DB into the object.
     * @param stdClass $record a rule action record from DB
     */
    public function set_actiondata(stdClass $record) {
        $this->set_actiondata_from_json($record->rulejson);
    }

    /**
     * Load data directly from JSON.
     * @param string $json a json string for a booking rule
     */
    public function set_actiondata_from_json(string $json) {
        // Nothing to set.
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param array $repeateloptions
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, array &$repeateloptions) {
        // No form.
    }

    /**
     * Get the name of the rule action
     * @param bool $localized
     * @return string the name of the rule action
     */
    public function get_name_of_action($localized = true) {
        return get_string('confirmbookinganswerwithprice', 'mod_booking');
    }

    /**
     * Is the booking rule action compatible with the current form data?
     * @param array $ajaxformdata the ajax form data entered by the user
     * @return bool true if compatible, else false
     */
    public function is_compatible_with_ajaxformdata(array $ajaxformdata = []) {
        return false;
    }

    /**
     * Save the JSON for all sendmail_daysbefore rules defined in form.
     * @param stdClass $data form data reference
     */
    public function save_action(stdClass &$data): void {
        // Nothing to save.
    }

    /**
     * Sets the rule defaults when loading the form.
     * @param stdClass $data reference to the default values
     * @param stdClass $record a record from booking_rules
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        // Nothing to set.
    }

    /**
     * Execute the action.
     * The stdclass has to have the keys userid, optionid & cmid & nextruntime.
     * @param stdClass $record
     */
    public function execute(stdClass $record) {
        global $DB;

        // Check if booking option has confirmationonnotification enabled,
        // in this case we need to set some settings for the booking answer record
        // for the user who is going to reserve this (priced) option.

        $bookingoptionsettings = singleton_service::get_instance_of_booking_option_settings($record->optionid);
        if ($bookingoptionsettings->confirmationonnotification == 0) {
            return;
        }

        // Get sprecific booking answer record.
        $bookinganswer = $DB->get_record('booking_answers', [
            'optionid' => $record->optionid,
            'userid' => $record->userid,
            'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
        ]);

        // Update booking answer.
        booking_option::write_user_answer_to_db(
            $bookinganswer->bookingid,
            $bookinganswer->frombookingid,
            $bookinganswer->userid,
            $bookinganswer->optionid,
            MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            $bookinganswer->id,
            null,
            MOD_BOOKING_BO_SUBMIT_STATUS_CONFIRMATION,
            "",
            0
        );

        // Set json to null for all other users on waiting list for this optuion
        // in booking answer records if confirmationonnotification is equal to 2.
        if ($bookingoptionsettings->confirmationonnotification == 2) {
            // Get sprecific booking answer record.
            $bookinganswers = $DB->get_records('booking_answers', [
                'optionid' => $record->optionid,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
            ]);

            foreach ($bookinganswers as $ba) {
                if ($ba->userid == $record->userid) {
                    continue;
                }

                // Update booking answer.
                booking_option::write_user_answer_to_db(
                    $ba->bookingid,
                    $ba->frombookingid,
                    $ba->userid,
                    $ba->optionid,
                    MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    $ba->id,
                    null,
                    MOD_BOOKING_BO_SUBMIT_STATUS_UN_CONFIRM,
                    "",
                    0
                );
            }
        }
    }
}
