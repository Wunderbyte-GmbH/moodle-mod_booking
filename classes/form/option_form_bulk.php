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
 * Option form
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use core_component;
use dml_exception;
use coding_exception;
use core_form\dynamic_form;
use context;
use context_module;
use context_system;
use mod_booking\customfield\booking_handler;
use mod_booking\option\fields\customfields;
use mod_booking\option\fields\price;
use mod_booking\price as Mod_bookingPrice;

defined('MOODLE_INTERNAL') || die();
require_once("$CFG->libdir/formslib.php");
require_once($CFG->dirroot . '/mod/booking/lib.php');

use mod_booking\booking_option;
use mod_booking\option\fields_info;
use mod_booking\singleton_service;
use moodle_exception;
use moodle_url;
use required_capability_exception;
use stdClass;

/**
 * Class to handle option form
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_form_bulk extends dynamic_form {
    /**
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;

        $submitdata = $this->_ajaxformdata;

        // Select to fetch all fields.
        $fields = core_component::get_component_classes_in_namespace(
            "mod_booking",
            'option\fields'
        );
        $options = [];

        // Array of things to include.
        $includedclasses = [
            'moveoption',
            'addtocalendar',
            'availability',
            'canceluntil',
            'courseid',
            'disablebookingusers',
            'disablecancel',
            'easy_availability_previouslybooked',
            "easy_bookingclosingtime",
            "easy_bookingopeningtime",
            "elective",
            "enrolmentstatus",
            "entities",
            "howmanyusers",
            "institution",
            "invisible",
            "maxanswers",
            "maxoverbooking",
            "minanswers",
            "notificationtext",
            "pollurl",
            "price",
            "removeafterminutes",
            "responsiblecontact",
            "shoppingcart",
            "teachers",
            "titleprefix",
            "waitforconfirmation",
        ];

        foreach (array_keys($fields) as $field) {
            $name = $field::return_classname_name();
            if (
                in_array(MOD_BOOKING_OPTION_FIELD_NECESSARY, $field::$fieldcategories)
                || !in_array($name, $includedclasses)
            ) {
                continue;
            }

            $options[$field] = get_string($name, 'mod_booking');
        }

        // Add customfields.
        $customfields = booking_handler::get_customfields();
        foreach ($customfields as $customfield) {
            $options[$customfield->shortname] = format_string($customfield->name);
        }

        $mform->addElement('select', 'choosefields', get_string('selectfieldofbookingoption', 'mod_booking'), $options);
        $mform->registerNoSubmitButton('btn_bookingruletemplates');
        $mform->addElement(
            'submit',
            'btn_bookingruletemplates',
            get_string('bookingruletemplates', 'mod_booking')
        );

        if (isset($submitdata['checkedids'])) {
            // On second load of mform, these keys will be lost.
            $mform->addElement('hidden', 'checkedids', $submitdata['checkedids']);
        }

        if (isset($submitdata['choosefields'])) {
            $index = 1;
            $fieldskey = 'selectedfields_';

            // Todo: Check if this field is already appended, if so, skip it.
            // Make sure to apply customformdata.
            foreach ($submitdata as $key => $value) {
                if (strpos($key, $fieldskey) !== false) {
                    $mform->addElement('hidden', $key, $value);
                    $index = str_replace($fieldskey, '', $key);
                    $index++;
                }
            }
            // Always append the current field.
            $mform->addElement('hidden', $fieldskey . $index, $submitdata['choosefields']);
        }
    }

    /**
     * Definition after data.
     * @return void
     * @throws coding_exception
     */
    public function definition_after_data() {

        $mform = $this->_form;
        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        if (!empty($formdata['choosefields'])) {
            foreach ($formdata as $key => $value) {
                if ((strpos($key, 'selectedfields_') !== false) || $key === 'choosefields') {
                    if (class_exists($value)) {
                        $this->apply_instance_form_definition($mform, $formdata, $value);
                    } else {
                        $customfields = booking_handler::get_customfields();
                        $shortnames = array_map(function ($obj) {
                            return $obj->shortname;
                        }, $customfields);
                        if (in_array($value, $shortnames)) {
                            $fieldname = $value;
                            $customfields = new customfields();
                            $formdata['id'] = 0;
                            $customfields::instance_form_definition($mform, $formdata, [], [$fieldname]);
                        }
                    }
                }
            }
            // Since Headers are not always rendered correctly, we avoid them.
            // Even though classes shouldn't return header elements, we still make sure to remove those appended in special cases.
            foreach ($mform->_elements as $index => $element) {
                if (get_class($element) == "MoodleQuickForm_header") {
                    unset($mform->_elements[$index]);
                }
            };
        }
    }

    /**
     * Apply instance form definition of the given class.
     *
     * @param mixed $mform
     * @param mixed $formdata
     * @param mixed $classname
     *
     * @return [type]
     *
     */
    private function apply_instance_form_definition(&$mform, $formdata, $classname) {
        if (!class_exists($classname)) {
            return;
        }
            $formdata['id'] = 0; // Just any ID.
            $classname::instance_form_definition($mform, $formdata, [], [], false);
    }

    /**
     * Validation function.
     * @param array $data
     * @param array $files
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function validation($data, $files) {

        $errors = [];

        return $errors;
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        $cmid = $this->_ajaxformdata['cmid'] ?? 0;

        if (empty($cmid)) {
            return context_system::instance();
        }

        return context_module::instance($cmid);
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
    }


    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $data = (object) $this->_ajaxformdata;

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission() {

        // Get data from form.
        $data = $this->get_data();
        $checkedids = explode(",", $data->checkedids);
        // Apply values to each of the bookingoptions.

        foreach ($checkedids as $bookingoptionid) {
            $settings = singleton_service::get_instance_of_booking_option_settings($bookingoptionid);
            $data->cmid = $settings->cmid ?? $data->cmid;
            $data->id = $bookingoptionid;
            $copy = clone($data);
            fields_info::set_data($copy);
            foreach ($data as $key => $value) {
                $copy->{$key} = $value;
            }
            booking_option::update($copy);
        }

        return $data;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/editoption.php');
    }
}
