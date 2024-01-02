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
 * Control and manage booking dates.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\option\fields;

use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use MoodleQuickForm;
use stdClass;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class availability extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_AVAILABILITY;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_POSTSAVE;

    /**
     * This identifies the header under which this particular field should be displayed.
     * @var string
     */
    public static $header = MOD_BOOKING_HEADER_AVAILABILITY;

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null): string {

        // Save the additional JSON conditions (the ones which have been added to the mform).
        bo_info::save_json_conditions_from_form($formdata);

        $newoption->availability = $formdata->availability;

        return '';
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @return void
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata, array $optionformconfig) {

        $optionid = $formdata['id'];

        // TODO: expert/simple mode needs to work with this too!
        // Add availability conditions.
        bo_info::add_conditions_to_mform($mform, $optionid);
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        global $DB;

        // Availability normally comes from settings, but it might come from the importer as well.
        if (!empty($data->importing)) {
            if (!empty($data->availability)) {
                $availability = $data->availability;
            } else {
                $availability = $settings->availability ?? "{}";
            }

            // On importing, we support the boavenrolledincourse key.
            if (!empty($data->boavenrolledincourse)) {

                $items = explode(',', $data->boavenrolledincourse);

                list($inorequal, $params) = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
                $sql = "SELECT id
                        FROM {course}
                        WHERE shortname $inorequal";
                $courses = $DB->get_records_sql($sql, $params);

                $data->bo_cond_enrolledincourse_courseids = array_keys($courses);
                $data->bo_cond_enrolledincourse_restrict = 1;
                unset($data->boavenrolledincourse);
            }
        } else {
            $availability = $settings->availability ?? "{}";
        }

        if (!empty($availability)) {
            $jsonobject = json_decode($availability);
            bo_info::set_defaults($data, $jsonobject);
        }

    }
}
