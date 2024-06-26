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

use context_course;
use core_course_external;
use mod_booking\booking_option_settings;
use mod_booking\option\fields_info;
use mod_booking\option\field_base;
use mod_booking\singleton_service;
use mod_booking\utils\wb_payment;
use moodle_exception;
use moodle_url;
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
    public static $alternativeimportidentifiers = [
        'enroltocourseshortname',
        'courseid',
    ];

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

        // First, we check, if user chose to automatically create a new moodle course.
        if (isset($data['courseid']) && $data['courseid'] == -1) {
            if (wb_payment::pro_version_is_activated()) {

                // URLs needed for error message.
                $bookingcustomfieldsurl = new moodle_url('/mod/booking/customfield.php');
                $settingsurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingbooking']);

                // Object for string.
                $a = new stdClass;
                $a->bookingcustomfieldsurl = $bookingcustomfieldsurl->out(false);
                $a->settingsurl = $settingsurl->out(false);

                if (get_config('booking', 'newcoursecategorycfield') == "-1" ||
                    empty(get_config('booking', 'newcoursecategorycfield'))) {
                    $errors['courseid'] = get_string('error:newcoursecategorycfieldmissing', 'mod_booking', $a);
                } else {
                    // A custom field for the category for automatically created new Moodle courses has been set.
                    $newcoursecategorycfield = get_config('booking', 'newcoursecategorycfield');

                    // So now we need to check, if a value for that custom field was set to in option form.
                    if (empty($data["customfield_$newcoursecategorycfield"])) {
                        $errors["customfield_$newcoursecategorycfield"] =
                            get_string('error:coursecategoryvaluemissing', 'mod_booking');
                    }
                }
            } else {
                $errors['courseid'] = get_string('infotext:prolicensenecessary', 'mod_booking');
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

        $options = [
            'tags' => false,
            'multiple' => false,
            'ajax' => 'mod_booking/form_courses_selector',
            'noselectionstring' => get_string('nocourseselected', 'mod_booking'),
            'valuehtmlcallback' => function($value) {
                if (isset($coursearray[$value])) {
                    return $coursearray[$value];
                } else {
                    global $DB, $OUTPUT;
                    // Check if the course is currently being duplicated.
                    $sql = "SELECT c.id, c.fullname, c.shortname
                            FROM {course} c
                            JOIN {backup_controllers} bc
                            ON c.id = bc.itemid
                            JOIN {task_adhoc} ta
                            ON ta.customdata LIKE " . $DB->sql_concat("'%backupid%'", "bc.backupid", "'%'") .
                            "WHERE bc.operation = 'restore' AND c.id = :courseid";
                    $params = ['courseid' => $value];
                    $duplicatingcourse = $DB->get_record_sql($sql, $params);

                    if (empty($duplicatingcourse)) {
                        // Check if the course exists.
                        $sql = "SELECT c.id, c.fullname, c.shortname
                                FROM {course} c
                                WHERE c.id = :courseid";
                        $params = ['courseid' => $value];
                        $courserecord = $DB->get_record_sql($sql, $params);
                        if (empty($courserecord)) {
                            // The course does not exist.
                            return get_string('nocourseselected', 'mod_booking');
                        } else {
                            // The course exists, so show it.
                            return $OUTPUT->render_from_template(
                                'mod_booking/form-course-selector-suggestion', $courserecord);
                        }
                    } else {
                        return get_string('courseduplicating', 'mod_booking');
                    }
                }
            },
        ];

        $mform->addElement('autocomplete', 'courseid', get_string("connectedmoodlecourse", "booking"), [], $options);
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
                } else {
                    throw new moodle_exception(
                        'courseshortnamenotfound',
                        'mod_booking',
                        '',
                        $data->enroltocourseshortname,
                        'Course not found: ' . $data->enroltocourseshortname);
                }

            }
        } else {
            $key = fields_info::get_class_name(static::class);
            // Normally, we don't call set data after the first time loading.
            if (isset($data->{$key})) {
                return;
            }

            // If the setting to duplicate the Moodle course is turned on...
            // ... we duplicate it and use the ID of the new course copy.
            if (get_config('booking', 'duplicatemoodlecourses') && !empty($data->oldcopyoptionid)) {
                $newcourseid = self::copy_moodle_course($data->oldcopyoptionid);
            }

            // If there is no $newcourseid, then the old courseid ($settings->{$key}) will be taken.
            $value = $newcourseid ?? $settings->{$key} ?? null;
            $data->{$key} = $value;
        }
    }

    /**
     * Helper function to copy a Moodle course.
     * @param int $oldcopyoptionid the id of the duplicated booking option
     *                             containing the course to copy
     * @return int $newcourseid the id of the new Moodle course
     * @throws coding_exception
     */
    private static function copy_moodle_course(int $oldcopyoptionid) {

        $oldsettings = singleton_service::get_instance_of_booking_option_settings($oldcopyoptionid);
        $oldcourseid = $oldsettings->courseid;

        // At first, we check the capabilities.
        $context = context_course::instance($oldcourseid);
        $copycaps = \core_course\management\helper::get_course_copy_capabilities();
        require_all_capabilities($copycaps, $context);

        // Get an object with the old course data.
        $oldcourse = get_course($oldcourseid);

        // Gather copy data.
        $copydata = new stdClass;
        $copydata->courseid = $oldcourseid;
        $copydata->fullname = $oldcourse->fullname . " (" . get_string('copy', 'mod_booking') . ")";
        $copydata->shortname = $oldcourse->shortname . "_" . strtolower(get_string('copy', 'mod_booking'));
        $copydata->category = $oldcourse->category;
        $copydata->visible = $oldcourse->visible;
        $copydata->startdate = $oldcourse->startdate;
        $copydata->enddate = $oldcourse->enddate;
        $copydata->idnumber = '';
        $copydata->userdata = "0"; // This might be a feature in a future version.
        $copydata->keptroles = [];
        // Roles ($copydata->keptroles = [roleid1, roleid2,...]) are also not yet included.

        // Now, we create an adhoc task to copy the course.
        $newcourseid = self::create_copy($copydata);

        // We return the ID of the new course copy.
        return (int) $newcourseid ?? null;
    }

    /**
     * Creates a course copy.
     *
     * @param \stdClass $copydata Course copy data from process_formdata
     * @return int $newcourseid the id of the new course
     */
    private static function create_copy(stdClass $copydata): int {
        global $CFG, $USER;
        $copyids = [];

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        // Create the initial backupcontoller.
        $bc = new \backup_controller(\backup::TYPE_1COURSE, $copydata->courseid, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_COPY, $USER->id, \backup::RELEASESESSION_YES);
        $copyids['backupid'] = $bc->get_backupid();

        // Create the initial restore contoller.
        list($fullname, $shortname) = \restore_dbops::calculate_course_names(
            0, get_string('copyingcourse', 'backup'), get_string('copyingcourseshortname', 'backup'));
        $newcourseid = \restore_dbops::create_new_course($fullname, $shortname, $copydata->category);
        $rc = new \restore_controller($copyids['backupid'], $newcourseid, \backup::INTERACTIVE_NO,
            \backup::MODE_COPY, $USER->id, \backup::TARGET_NEW_COURSE, null,
            \backup::RELEASESESSION_NO, $copydata);
        $copyids['restoreid'] = $rc->get_restoreid();

        $bc->set_status(\backup::STATUS_AWAITING);
        $bc->get_status();
        $rc->save_controller();

        // Create the ad-hoc task to perform the course copy.
        $asynctask = new \core\task\asynchronous_copy_task();
        $asynctask->set_blocking(false);
        $asynctask->set_custom_data($copyids);
        \core\task\manager::queue_adhoc_task($asynctask);

        // Clean up the controller.
        $bc->destroy();

        return $newcourseid;
    }
}
