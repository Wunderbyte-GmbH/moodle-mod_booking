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
 * @author David Ala
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use mod_booking\singleton_service;
use stdClass;


/**
 * Helperclass for Logic.
 */
class evasys_evaluation {
    /**
     * Save data from form into DB.
     *
     * @param /stdClass $formdata
     * @param /stdClass $option
     * @return void
     *
     */
    public function save_form(&$formdata, &$option) {
        global $DB;
        $insertdata = self::map_form_to_record($formdata, $option);
        if (empty($formdata->evasys_id)) {
            $DB->insert_record('booking_evasys', $insertdata, false, false);
        } else {
            $DB->update_record('booking_evasys', $insertdata);
        }
    }

    /**
     * Load Data to form.
     *
     * @param object $data
     *
     * @return void
     *
     */
    public function load_form(&$data): void {
        global $DB;
        $record = $DB->get_record('booking_evasys', ['optionid' => $data->optionid], '*', IGNORE_MISSING);
        if (empty($record)) {
            return;
        }
        self::map_record_to_form($data, $record);
    }

    /**
     * [Description for get_questionares]
     *
     * @return array
     *
     */
    public function get_questionares() {
        // TODO Other Ticket.
    }

    /**
     * [Description for get_recipients]
     *
     * @return array
     *
     */
    public function get_recipients() {
        // TODO Other Ticket.
    }

    /**
     * Maps DB to Form to DB for saving.
     *
     * @param /stdClass $formdata
     * @param /stdClass $option
     *
     * @return object
     *
     */
    private static function map_form_to_record($formdata, $option) {
        global $USER;
        $insertdata = new stdClass();
        $now = time();
        $insertdata->optionid = $option->id;
        $insertdata->pollurl = $formdata->evasys_questionaire;
        $insertdata->starttime = $formdata->evasys_evaluation_starttime;
        $insertdata->endtime = $formdata->evasys_evaluation_endtime;
        $insertdata->trainers = implode(',', ($formdata->teachersforoption ?? []));
        $insertdata->organizers = implode(',', ($formdata->evasys_other_report_recipients ?? []));
        $insertdata->notifyparticipants = $formdata->evasys_notifyparticipants;
        $insertdata->usermodified = $USER->id;

        if (empty($formdata->evasys_id)) {
            $insertdata->timecreated = $now;
        } else {
            $insertdata->id = $formdata->evasys_id;
            $insertdata->timemodified = $now;
        }
        return $insertdata;
    }

    /**
     * Maps Data of DB record to Formdata.
     *
     * @param /stdClass $data
     * @param /stdClass $record
     *
     * @return void
     *
     */
    private static function map_record_to_form($data, $record) {
        $data->evasys_questionaire = $record->pollurl;
        $data->evasys_evaluation_starttime = $record->starttime;
        $data->evasys_evaluation_endtime = $record->endtime;
        $data->trainers = explode(',', $record->trainers);
        $data->evasys_other_report_recipients = explode(',', $record->organizers);
        $data->evasys_notifyparticipants = $record->notifyparticipants;
        $data->evasys_id = $record->id;
        $data->evasys_timecreated = $record->timecreated;
    }
}
