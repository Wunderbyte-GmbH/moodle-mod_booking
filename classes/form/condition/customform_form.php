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
 * Dynamic change semester form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form\condition;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");
require_once("$CFG->dirroot/mod/booking/lib.php");

use context;
use context_system;
use core_form\dynamic_form;
use mod_booking\bo_availability\conditions\customform;
use mod_booking\local\mobile\customformstore;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

/**
 * Add holidays form.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customform_form extends dynamic_form {

    /** @var int $id */
    private $id = null;

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:conditionforms', context_system::instance());
    }


    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        global $USER;

        $data = new stdClass();

        $formdata = $this->_ajaxformdata;

        // Todo: get these values.
        $optionid = $formdata['id'];
        $userid = $formdata['userid'] ?? $USER->id;

        $customformstore = new customformstore($userid, $optionid);
        $cachedata = $customformstore->get_customform_data();

        foreach ((array)$cachedata as $key => $value) {
            if (str_contains($key, 'customform_') !== false) {
                $data->{$key} = $value;
            }
        }

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {

        global $USER;

        $data = $this->get_data();

        $userid = $data->userid ?? $USER->id;

        $customformstore = new customformstore($userid, $data->id);
        $customformstore->set_customform_data($data);

        return $data;
    }

    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {

        $formdata = $this->_ajaxformdata;
        $mform = $this->_form;

        $id = $formdata['id'];
        $userid = $formdata['userid'];

        // We have to pass by the option settings.
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$id);

        $mform->addElement('hidden', 'id', $id);
        $mform->addElement('hidden', 'userid', $userid);

        $availability = json_decode($settings->availability);

        // Right now, we can only have one condition of type custom field.
        foreach ($availability as $condition) {
            if ($condition->id == MOD_BOOKING_BO_COND_JSON_CUSTOMFORM) {
                $customform = $condition;
            }
        }

        foreach ($customform->formsarray as $formkey => $formvalue) {
            $formelements = [];

            $mform = $this->_form;

            $counter = 1;
            foreach ($formvalue as $formelementkey => $formelementvalue) {
                // We might need custom solutions, therefore we have the switch here.
                switch ($formelementvalue->formtype) {
                    case 'static':
                        $identifier = 'customform_' . $formelementvalue->formtype . '_' . $counter;
                        $mform->addElement(
                            'static',
                            $identifier,
                            format_string($formelementvalue->label),
                            $formelementvalue->value
                        );
                        break;
                    case 'advcheckbox':
                        $identifier = 'customform_' . $formelementvalue->formtype . '_' . $counter;
                        $mform->addElement(
                            'advcheckbox',
                            $identifier,
                            '',
                            format_string($formelementvalue->label) ?? "Label " . $counter
                        );
                        break;
                    case 'shorttext':
                        $identifier = 'customform_' . $formelementvalue->formtype . '_' . $counter;
                        $mform->addElement(
                            'text',
                            $identifier,
                            format_string($formelementvalue->label) ?? "Label " . $counter
                        );
                        $mform->setDefault('customform_shorttext_' . $counter, $formelementvalue->value);
                        $mform->setType('customform_shorttext_' . $counter, PARAM_TEXT);
                        break;
                    case 'select':
                        // Create the array.
                        $identifier = 'customform_' . $formelementvalue->formtype . '_' . $counter;
                        $lines = explode(PHP_EOL, $formelementvalue->value);
                        $options = [];
                        foreach ($lines as $line) {
                            $linearray = explode(' => ', $line);
                            if (count($linearray) > 1) {
                                $options[$linearray[0]] = format_string($linearray[1]);
                                if (count($linearray) > 2) {
                                    $ba = singleton_service::get_instance_of_booking_answers($settings);
                                    $expectedvalue = $linearray[0];
                                    $filteredba = array_filter(
                                        $ba->usersonlist,
                                        function ($userbookings) use ($identifier, $expectedvalue) {
                                            return isset($userbookings->$identifier)
                                                    && $userbookings->$identifier === $expectedvalue;
                                        }
                                    );
                                    $leftover = $linearray[2] - count($filteredba);
                                    if ($leftover == 0) {
                                        unset($options[$linearray[0]]);
                                    } else {
                                        $options[$linearray[0]] .= ', ' . $leftover  .
                                            ' ' . get_string('bocondcustomformstillavailable', 'mod_booking');
                                    }
                                }
                            } else {
                                $options[] = format_string($line);
                            }
                        }
                        $mform->addElement(
                            'select',
                            $identifier,
                            format_string($formelementvalue->label) ?? "Label " . $counter,
                            $options
                        );
                        break;
                    case 'url':
                        $identifier = 'customform_' . $formelementvalue->formtype . '_' . $counter;
                        $mform->addElement(
                            'text',
                            $identifier,
                            format_string($formelementvalue->label) ?? "Label " . $counter
                        );
                        $mform->setDefault('customform_url_' . $counter, $formelementvalue->value);
                        break;
                    case 'mail':
                        $identifier = 'customform_' . $formelementvalue->formtype . '_' . $counter;
                        $mform->addElement(
                            'text',
                            $identifier,
                            format_string($formelementvalue->label) ?? "Label " . $counter
                        );
                        $mform->setDefault('customform_mail_' . $counter, $formelementvalue->value);
                        break;
                }

                $counter++;
            }

            $dataarray['data']['formsarray'][] = $formelements;
        }
    }

    /**
     * Server-side form validation.
     * @param array $data
     * @param array $files
     * @return array $errors
     */
    public function validation($data, $files): array {
        $errors = [];

        if ($data['id']) {
            $id = $data['id'];
        } else {
            return $errors;
        }

        // We have to pass by the option settings.
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$id);
        $customform = customform::return_formelements($settings);
        $customformstore = new customformstore($data['userid'], $id);
        return $customformstore->validation($customform, $data);
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/view.php', ['id' => $this->id]);
    }
}
