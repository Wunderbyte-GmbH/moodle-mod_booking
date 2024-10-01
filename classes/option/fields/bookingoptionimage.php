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

use context_user;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
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
class bookingoptionimage extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_OPTIONIMAGES;

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
        $returnvalue = null): array {

        parent::prepare_save_field($formdata, $newoption, $updateparam, '');

        $mockdata = new stdClass();
        $mockdata->id = $formdata->id; // Just any id to make sure settings are applied.
        $mockdata->cmid = $formdata->cmid;

        return [];
    }

    /**
     * The save data function is very specific only for those values that should be saved...
     * ... after saving the option. This is so, when we need an option id for saving (because of other table).
     * @param stdClass $formdata
     * @param stdClass $option
     * @param int $index
     * @return array
     * @throws \dml_exception
     */
    public static function save_data(stdClass &$formdata, stdClass &$option, int $index = 0): array {
        global $USER;

        $cmid = $formdata->cmid;
        $optionid = $option->id;
        $changes = [];

        $context = context_module::instance($cmid);

        if ($draftimageid = $formdata->bookingoptionimage ?? false ?? false ?? false) {
            $fs = get_file_storage();
            $usercontext = context_user::instance($USER->id);
            $newfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftimageid, 'id');
            $oldfiles = $fs->get_area_files($context->id, 'mod_booking', 'bookingoptionimage', $optionid, 'id');
            $newhashes = [];
            $oldhashes = [];
            foreach ($newfiles as $file) {
                if (empty($file->get_filesize())) {
                    continue;
                }
                $newhashes[$file->get_filename()] = $file->get_contenthash();
            }

            foreach ($oldfiles as $file) {
                if (empty($file->get_filesize())) {
                    continue;
                }
                $oldhashes[$file->get_filename()] = $file->get_contenthash();
            }
            if ($oldhashes != $newhashes) {

                $changes = [ 'changes' => [
                    'fieldname' => 'bookingoptionimage',
                    'oldvalue' => array_keys($oldhashes)[0], // There is only one bookingoptionimage accepted, so no need for array.
                    'newvalue' => array_keys($newhashes)[0],
                    ],
                ];
            }
            file_save_draft_area_files($draftimageid, $context->id, 'mod_booking', 'bookingoptionimage',
                    $optionid, ['subdirs' => false, 'maxfiles' => 1]);
        }
        return $changes;
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

        global $CFG;

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        if ($applyheader) {
            fields_info::add_header_to_mform($mform, self::$header);
        }

        // Upload an image for the booking option.
        $mform->addElement('filemanager',
                        'bookingoptionimage',
                        get_string('bookingoptionimage', 'mod_booking'),
                        null,
                        ['subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'maxfiles' => 1, 'accepted_types' => ['image']]
                    );
    }

    /**
     * Standard function to transfer stored value to form.
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     * @throws dml_exception
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings) {

        global $CFG, $COURSE;

        // Get an unused draft itemid which will be used for this form.
        $draftitemid = file_get_submitted_draft_itemid('bookingoptionimage');

        if (!empty($data->id)) {
            $context = context_module::instance($data->cmid);

            // Copy the existing files which were previously uploaded
            // into the draft area used by this form.
            file_prepare_draft_area(
                // The $draftitemid is the target location.
                $draftitemid,

                // The combination of contextid / component / filearea / itemid
                // form the virtual bucket that files are currently stored in
                // and will be copied from.
                $context->id,
                'mod_booking',
                'bookingoptionimage',
                $data->id,
                [
                    'subdirs' => 0,
                    'maxbytes' => $CFG->maxbytes,
                    'maxfiles' => 1,
                ]
            );

            if (!empty($draftitemid)) {
                $data->bookingoptionimage = $draftitemid;
            }
        }
    }
}
