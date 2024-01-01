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
 * Booking option form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace mod_booking\form;

use context_module;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

/**
 * With this form the booking option form can be configured (reduced).
 *
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optionformconfig_form extends \moodleform {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        // Find module id for 'booking'.
        if (!$moduleid = $DB->get_field('modules', 'id', ['name' => 'booking'])) {
            return;
        }

        // Check if there is at least one booking instance.
        if ($firstcm = $DB->get_records('course_modules', ['module' => $moduleid], '', '*', 0, 1)) {

            $cm = reset($firstcm);
            $bookingid = $cm->instance;
            $cmid = $cm->id;
            if (!$context = context_module::instance($cmid)) {
                throw new moodle_exception('badcontext');
            }

            // Instantiate an option_form object, so we can get its elements.
            $optionformdummy = new option_form(
                null,
                [
                    'formmode' => 'expert',
                    'bookingid' => $bookingid,
                    'id' => 0,
                    'optionid' => 0,
                    'cmid' => $cmid,
                    'context' => $context,
                ]);

            if ($elements = $optionformdummy->_form->_elements) {

                foreach ($elements as $element) {

                    if (empty($element->_attributes['name']) && empty($element->_name)) {
                        continue;
                    }
                    if (empty($element->_attributes['name']) && !empty($element->_name)) {
                        $element->_attributes['name'] = $element->_name;
                    }

                    if ($element->_attributes['name'] == "local_entities_entityname") {
                        continue;
                    }

                    if ($element->_attributes['name'] == 'text') {
                        continue;
                    }
                    if ($element->_type == 'hidden' || $element->_type == "html") {
                        continue;
                    }
                    if (empty($element->_label) && empty($element->_text)) {
                        continue;
                    }
                    if (empty($element->_label) && !empty($element->_text)) {
                        $element->_label = $element->_text;
                    }

                    $mform->addElement('advcheckbox', 'cfg_' . $element->_attributes['name'], $element->_label .
                        " <span class='text-muted'>[" . $element->_type . "]</span>");
                    $mform->setType('cfg_' . $element->_attributes['name'], PARAM_INT);

                    if ($existingrecord = $DB->get_record('booking_optionformconfig',
                        ['elementname' => $element->_attributes['name']])) {
                        if ($existingrecord->active == 0 || $existingrecord->active == 1) {
                            $mform->setDefault('cfg_' . $element->_attributes['name'], $existingrecord->active);
                        } else {
                            $mform->setDefault('cfg_' . $element->_attributes['name'], 1);
                        }
                    } else {
                        $mform->setDefault('cfg_' . $element->_attributes['name'], 1);
                    }
                }
            }

            // Add "Save" and "Cancel" buttons.
            $this->add_action_buttons(true);

        } else {
            return;
        }
    }


    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     *
     */
    public function validation($data, $files) {

        // We need not validation in this form.
        $errors = [];
        return $errors;
    }

    /**
     * {@inheritDoc}
     * @see moodleform::get_data()
     */
    public function get_data() {
        $data = parent::get_data();
        return $data;
    }
}
