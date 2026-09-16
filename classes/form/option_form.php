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
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use dml_exception;
use coding_exception;
use core_form\dynamic_form;
use context;
use context_module;
use context_system;

defined('MOODLE_INTERNAL') || die();
require_once("$CFG->libdir/formslib.php");

use mod_booking\local\option_edit_access;
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
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_form extends dynamic_form {
    /**
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        $cmid = $formdata['cmid'] ?? 0;
        $optionid = $formdata['id'] ?? $formdata['optionid'] ?? 0;

        if (!empty($cmid)) {
            // We need context on this.
            $context = context_module::instance($cmid);
        } else if (empty($cmid) && !empty($optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $formdata['cmid'] = $settings->cmid;
            $context = context_module::instance($settings->cmid);
        } else {
            $context = context_system::instance();
        }

        $formdata['context'] = $context;

        $mform = &$this->_form;

        // Add all available fields in the right order.
        $classes = fields_info::instance_form_definition($mform, $formdata);

        if (!empty($classes)) {
            $this->add_action_buttons(true, get_string('save'));
        } else {
            $mform->addElement('html', '<div class="alert alert-warning">' .
                get_string('error:formcapabilitymissing', 'mod_booking') .
                '</div>');
        }
    }

    /**
     * Data preprocessing.
     *
     * @param array $defaultvalues
     *
     * @return void
     *
     */
    protected function data_preprocessing(&$defaultvalues) {

        // Custom lang strings.
        if (!isset($defaultvalues['descriptionformat'])) {
            $defaultvalues['descriptionformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['description'])) {
            $defaultvalues['description'] = '';
        }

        if (!isset($defaultvalues['notificationtextformat'])) {
            $defaultvalues['notificationtextformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['notificationtext'])) {
            $defaultvalues['notificationtext'] = '';
        }

        if (!isset($defaultvalues['beforebookedtext'])) {
            $defaultvalues['beforebookedtext'] = '';
        }

        if (!isset($defaultvalues['beforecompletedtext'])) {
            $defaultvalues['beforecompletedtext'] = '';
        }

        if (!isset($defaultvalues['aftercompletedtext'])) {
            $defaultvalues['aftercompletedtext'] = '';
        }
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
        global $DB;
        $errors = parent::validation($data, $files);

        fields_info::validation($data, $files, $errors);

        return $errors;
    }

    /**
     * Definition after data.
     * @return void
     * @throws coding_exception
     */
    public function definition_after_data() {

        $mform = $this->_form;
        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        fields_info::definition_after_data($mform, $formdata);
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        // This context is validated with require_login() in external_api::validate_context(). If the
        // setting "editoptionsrequirecourselogin" is disabled, a site login is enough (like on editoptions.php).
        // The page context and the capabilities are then set and checked in check_access_for_dynamic_submission().
        if (get_config('booking', 'editoptionsrequirecourselogin') === '0') {
            return context_system::instance();
        }

        return $this->get_option_context();
    }

    /**
     * Returns the context of the booking instance the option belongs to.
     * @return context
     */
    private function get_option_context(): context {

        $cmid = $this->_ajaxformdata['cmid'] ?? 0;

        if (empty($cmid)) {
            return context_system::instance();
        }

        return context_module::instance($cmid);
    }

    /**
     * Check access for dynamic submission.
     *
     * The ids in the ajax data are sent by the client, so they must not be trusted:
     * "optionid" loads the option and "id" is the option that gets saved. We require them to be equal,
     * so the teacher/capability check below applies to the option that is actually written.
     * booking_option_form_ids_match_cm() then makes sure the option and the booking instance belong to the
     * course module whose context is used for the capability checks (an attacker could otherwise use the
     * capabilities of a booking instance they may edit to change an option of another instance).
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        global $CFG, $PAGE;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $context = $this->get_option_context();

        $formdata = $this->_ajaxformdata ?? [];
        $id = max(0, (int) ($formdata['id'] ?? $formdata['optionid'] ?? 0));
        $optionid = max(0, (int) ($formdata['optionid'] ?? $formdata['id'] ?? 0));
        if ($id !== $optionid) {
            throw new moodle_exception('invalidcontext', 'error');
        }

        if ($context->contextlevel == CONTEXT_MODULE) {
            [$course, $cm] = get_course_and_cm_from_cmid($context->instanceid, 'booking');
            if (
                !booking_option_form_ids_match_cm(
                    $cm,
                    $optionid,
                    (int) ($formdata['bookingid'] ?? 0),
                    (int) ($formdata['copyoptionid'] ?? 0)
                )
            ) {
                throw new moodle_exception('invalidcontext', 'error');
            }
            if ($PAGE->context->id != $context->id) {
                // Only the site login was validated, so we set the course module for the page here.
                $PAGE->set_cm($cm, $course);
            }
        }

        // Capability updatebooking may edit any option of the instance; editownoption only if teacher of this
        // option; addoption only for a new option; duplicateownoption only when a new option is saved (the
        // duplicate). Same rule as on editoptions.php, see option_edit_access::can_submit_option_form().
        if (
            !option_edit_access::can_submit_option_form(
                $context,
                $optionid,
                (int) ($formdata['copyoptionid'] ?? 0)
            )
        ) {
            throw new required_capability_exception($context, 'mod/booking:editownoption', 'nopermissions', '');
        }
    }


    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $data = (object)$this->_ajaxformdata ?? $this->_customdata;

        $data->id = $this->_ajaxformdata['optionid'];

        fields_info::set_data($data);

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission() {

        // Get data from form.
        $data = $this->get_data();

        // Pass data to update.
        $context = $this->get_option_context();

        $result = booking_option::update($data, $context);

        // A new option gets its id in update(). The returned data is used to reload the form, where id and optionid must match.
        $data->optionid = $data->id;

        return $data;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {

        // Strangely, we get the ajax formdata here without a key.
        $cmid = $this->_ajaxformdata['cmid'] ?? 0;
        $optionid = $this->_ajaxformdata['id'] ?? 0;
        return new moodle_url('/mod/booking/editoption.php', ['id' => $cmid, 'optionid' => $optionid]);
    }
}
