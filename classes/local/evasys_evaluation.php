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
 * Helperclass to Save and Load Form.
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
    public static function save_form(&$formdata, &$option) {
        global $DB;

        $trainers = implode(',', ($formdata->teachersforoption ?? []));

        $insertdata = new stdClass();
        $insertdata->optionid = $option->id;
        $insertdata->pollurl = "PLACEHOLDERSTRING";
        $insertdata->starttime = $formdata->evasys_evaluation_starttime;
        $insertdata->endtime = $formdata->evasys_evaluation_endtime;
        $insertdata->trainers = $trainers;
        $insertdata->organizers = $formdata->evasys_other_report_recipients;
        $insertdata->notifyparticipants = $formdata->evasys_notifyparticipants;

        $DB->insert_record('booking_evasys', $insertdata, false, false);
    }

    /**
     * Load data into form.
     *
     * @param $data
     * @param int $optionid
     * @return void
     * @throws dml_exception
     */
    public static function load_form(&$data, $optionid): void {
        // Load into Logic.
    }

    /**
     * [Description for get_questionares]
     *
     * @return array
     *
     */
    public static function get_questionares() {

    }

    /**
     * [Description for get_recipients]
     *
     * @return array
     *
     */
    public static function get_recipients() {

    }
}
