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

use Exception;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use dml_exception;
use MoodleQuickForm;
use stdClass;
use context_module;

/**
 * Class to handle one property of the booking_option_settings class.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moveoption extends field_base {
    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_MOVEOPTION;

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
    public static $header = MOD_BOOKING_HEADER_GENERAL;

    /**
     * An int value to define if this field is standard or used in a different context.
     * @var array
     */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /**
     * Additionally to the classname, there might be others keys which should instantiate this class.
     * @var array
     */
    public static $alternativeimportidentifiers = [];

    /**
     * This is an array of incompatible field ids.
     * @var array
     */
    public static $incompatiblefields = [];

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param ?mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {

        return parent::prepare_save_field($formdata, $newoption, $updateparam, '');
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @param array $fieldstoinstanciate
     * @param bool $applyheader
     * @return void
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ) {

        // Moving works only on saved booking option.
        if (empty($formdata['id']) && isset($formdata['cmid'])) {
            return;
        }

        global $DB;

        $allowedinstances = [
            0 => get_string('dontmove', 'mod_booking'),
        ];

        if (
            $records = $DB->get_records_sql(
                "SELECT cm.id cmid, b.name bookingname, c.fullname coursename
                FROM {course_modules} cm
                LEFT JOIN {course} c ON c.id = cm.course
                LEFT JOIN {booking} b ON b.id = cm.instance
                WHERE cm.module IN (
                    SELECT id
                    FROM {modules} m
                    WHERE m.name = 'booking'
                )"
            )
        ) {
            foreach ($records as $record) {
                // A user should only be able to move the option to a cm where she has access.
                $context = context_module::instance($record->cmid);
                if (
                    has_capability('mod/booking:updatebooking', $context)
                    || has_capability('mod/booking:addeditownoption', $context)
                ) {
                    $allowedinstances[$record->cmid] = "$record->bookingname ($record->coursename, ID: $record->cmid)";
                }
            }
        }

        if (!empty($allowedinstances)) {
            // Standardfunctionality to add a header to the mform (only if its not yet there).
            if ($applyheader) {
                fields_info::add_header_to_mform($mform, self::$header);
            }
            // If we have no instances, show an explanation text.
            $mform->addElement('select', 'moveoption', get_string('moveoption', 'mod_booking'), $allowedinstances);
            $mform->addHelpButton('moveoption', 'moveoption', 'mod_booking');
        }
        if (empty($alloptiontemplates)) {
            return;
        }
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        // If we are not importing, we override it.
        if (
            empty($data->importing)
            || !isset($data->moveoption)
        ) {
            // This will always be set to 0, as it can only be changed by the user once.
            $data->moveoption = 0;
        }
    }

    /**
     * Save data
     * @param stdClass $data
     * @param stdClass $option
     * @return array
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$data, stdClass &$option): array {
        global $DB;
        $changes = [];
        if (isset($data->moveoption) && !empty((int)$data->moveoption)) {
            $instance = new moveoption();
            $changes = $instance->check_for_changes($data, $instance, '', 'moveoption', $option->bookingid);

            try {
                $elements = get_course_and_cm_from_cmid((int)$data->moveoption);
                $cm = $elements[1];

                $optionid = $data->id;
                $bookingid = $cm->instance;
                $cmid = $cm->id;
                $context = context_module::instance($cmid);

                if (!empty($cm) && ($option->bookingid != $bookingid)) {
                    $option->cmid = $cmid;
                    $option->bookingid = $bookingid;
                    $data->cmid = $cmid;
                    $data->bookingid = $bookingid;

                    $DB->update_record('booking_options', ['id' => $optionid, 'bookingid' => $bookingid]);

                    // We also need to update the answers, as the also have a booking id.
                    $records = $DB->get_records('booking_answers', ['optionid' => $optionid]);
                    foreach ($records as $record) {
                        $bookingchange = [
                            'booking' => [
                            'oldbooking' => $record->bookingid,
                            ],
                        ];
                        booking_option::booking_history_insert(
                            MOD_BOOKING_STATUSPARAM_BOOKINGOPTION_MOVED,
                            $record->id,
                            $optionid,
                            $bookingid,
                            $record->userid,
                            $bookingchange
                        );
                        $DB->update_record('booking_answers', ['id' => $record->id, 'bookingid' => $bookingid]);
                    }

                    // We also need to update the optiondates, as the also have a booking id.
                    $records = $DB->get_records('booking_optiondates', ['optionid' => $optionid]);
                    foreach ($records as $record) {
                        $DB->update_record('booking_optiondates', ['id' => $record->id, 'bookingid' => $bookingid]);
                    }

                    // We also need to update the answers, as the also have a booking id.
                    $records = $DB->get_records('booking_teachers', ['optionid' => $optionid]);
                    foreach ($records as $record) {
                        $DB->update_record('booking_teachers', ['id' => $record->id, 'bookingid' => $bookingid]);
                    }

                    // We also need to update images, they have...
                    // ...contextid => module context of the booking instance...
                    // ...component: mod_booking, filearea: bookingoptionimage, itemid: optionid.
                    // Important: No context when retrieving the files.
                    $records = $DB->get_records(
                        'files',
                        [
                            'component' => 'mod_booking',
                            'filearea' => 'bookingoptionimage',
                            'itemid' => $optionid,
                        ],
                        /* 2 newest entries, one contains filename "." the other entry contains the actual file reference. */
                        'id DESC',
                        '*', // All columns.
                        0, // Start at first.
                        2 // Only 2 newest entries.
                    );
                    // Now we can set the new context.
                    foreach ($records as $record) {
                        $DB->update_record('files', ['id' => $record->id, 'contextid' => $context->id]);
                    }
                }
            } catch (Exception $e) {
                // We don't want to throw an error here but just ignore it.
                // Might occur when a cm is chosen that does not exist anymore.
                $changes = [];
            }
        }
        return $changes;
    }
}
