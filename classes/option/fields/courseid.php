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

use core_course_external;
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
class courseid extends field_base {

    /**
     * This ID is used for sorting execution.
     * @var int
     */
    public static $id = MOD_BOOKING_OPTION_FIELD_COURSEID;

    /**
     * Some fields are saved with the booking option...
     * This is normal behaviour.
     * Some can be saved only post save (when they need the option id).
     * @var int
     */
    public static $save = MOD_BOOKING_EXECUTION_NORMAL;

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
     * @param mixed $returnvalue
     * @return string // If no warning, empty string.
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null): string {

        global $DB;

        /* Create a new course and put it either in a new course category
        or in an already existing one. */
        if (!empty($formdata->courseid) && $formdata->courseid == -1) {
            $categoryid = 1; // By default, we use the first category.
            if (!empty(get_config('booking', 'newcoursecategorycfield'))) {
                // FEATURE add more settingfields add customfield_ to ...
                // ... settingsvalue from customfields allwo only Textfields or Selects.
                $cfforcategory = 'customfield_' . get_config('booking', 'newcoursecategorycfield');
                $category = new stdClass();
                $category->name = $formdata->{$cfforcategory};

                if (!empty($category->name)) {
                    $categories = core_course_external::get_categories([
                            ['key' => 'name', 'value' => $category->name],
                    ]);

                    if (empty($categories)) {
                        $category->idnumber = $category->name;
                        $categories = [
                                ['name' => $category->name, 'idnumber' => $category->idnumber, 'parent' => 0],
                        ];
                        $createdcats = core_course_external::create_categories($categories);
                        $categoryid = $createdcats[0]['id'];
                    } else {
                        $categoryid = $categories[0]['id'];
                    }
                }
            }

            // Create course.
            $fullnamewithprefix = '';
            if (!empty($formdata->titleprefix)) {
                $fullnamewithprefix .= $formdata->titleprefix . ' - ';
            }
            $fullnamewithprefix .= $formdata->text;

            // Courses need to have unique shortnames.
            $i = 1;
            $shortname = $fullnamewithprefix;
            while ($DB->get_record('course', ['shortname' => $shortname])) {
                $shortname = $fullnamewithprefix . '_' . $i;
                $i++;
            };
            $newcourse['fullname'] = $fullnamewithprefix;
            $newcourse['shortname'] = $shortname;
            $newcourse['categoryid'] = $categoryid;

            $courses = [$newcourse];
            $createdcourses = core_course_external::create_courses($courses);
            $newoption->courseid = $createdcourses[0]['id'];
            $formdata->courseid = $newoption->courseid;
        }

        return parent::prepare_save_field($formdata, $newoption, $updateparam, 0);
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {

        global $DB;
        // Minus 1 (-1) means we need to create a new course, that's ok.
        if (!empty($data['courseid']) && $data['courseid'] != -1) {
            if (!$DB->record_exists('course', ['id' => $data['courseid']])) {
                $errors['courseid'] = get_string('coursedoesnotexist', 'mod_booking', $data['courseid']);
            }
        }

        return $errors;
    }

    /**
     * Instance form definition
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @return void
     */
    public static function instance_form_definition(MoodleQuickForm &$mform, array &$formdata, array $optionformconfig) {

        // Standardfunctionality to add a header to the mform (only if its not yet there).
        fields_info::add_header_to_mform($mform, self::$header);

        $coursearray = [];
        $coursearray[0] = get_string('donotselectcourse', 'mod_booking');
        $totalcount = 1;
        // TODO: Using  moodle/course:viewhiddenactivities is not 100% accurate for finding teacher/non-editing teacher at least.
        $allcourses = get_courses_search([], 'c.shortname ASC', 0, 9999999,
            $totalcount, ['enrol/manual:enrol']);

        $coursearray[-1] = get_string('newcourse', 'booking');
        foreach ($allcourses as $id => $courseobject) {
            $coursearray[$id] = $courseobject->shortname;
        }
        $options = [
            'noselectionstring' => get_string('donotselectcourse', 'mod_booking'),
        ];
        $mform->addElement('autocomplete', 'courseid', get_string("connectedmoodlecourse", "booking"), $coursearray, $options);
        $mform->addHelpButton('courseid', 'connectedmoodlecourse', 'mod_booking');
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

        if (!empty($data->importing)) {
            // We might import the courseid with a different key.
            if (!empty($data->coursenumber) && is_numeric($data->coursenumber)) {

                $data->courseid = $data->coursenumber;
            }

            // We also support the enroltocourseshortname.
            if (!empty($data->enroltocourseshortname)) {

                if ($courseid = $DB->get_field('course', 'id', ['shortname' => $data->enroltocourseshortname])) {
                    $data->courseid = $courseid;
                    unset($data->enroltocourseshortname);
                }

            }
        } else {
            $key = fields_info::get_class_name(static::class);
            // Normally, we don't call set data after the first time loading.
            if (isset($data->{$key})) {
                return;
            }

            $value = $settings->{$key} ?? null;
            $data->{$key} = $value;
        }
    }
}
