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

namespace mod_booking\form;

use context;
use context_system;
use core_form\dynamic_form;
use mod_booking\local\certificate_conditions\certificate_conditions;
use mod_booking\local\certificate_conditions\filters_info;
use mod_booking\local\certificate_conditions\logics_info;
use mod_booking\local\certificate_conditions\actions_info;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Dynamic certificate condition form.
 *
 * @package mod_booking
 * @copyright 2026 Your Name
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certificateconditionsform extends dynamic_form {
    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;
        $ajaxformdata = $this->_ajaxformdata;

        // If we open an existing condition, load persisted selector defaults first.
        if (!empty($ajaxformdata['id'])) {
            $mform->addElement('hidden', 'id', $ajaxformdata['id']);
            $this->prepare_ajaxformdata($ajaxformdata);
            $this->_ajaxformdata = $ajaxformdata;
        }

        $mform->addElement('hidden', 'contextid');
        $mform->setType('contextid', PARAM_INT);

        $mform->addElement('text', 'name', get_string('certificateconditionname', 'mod_booking'), ['size' => '50']);
        $mform->setType('name', PARAM_TEXT);

        $mform->addElement('advcheckbox', 'isactive', get_string('certificateconditionisactive', 'mod_booking'));
        $active = (isset($ajaxformdata['isactive']) && empty($ajaxformdata['isactive'])) ? 0 : 1;
        $mform->setDefault('isactive', $active);

        // Filter block.
        filters_info::add_filters_to_mform($mform, $ajaxformdata);
        $mform->addElement('html', '<hr>');

        // Logic block.
        logics_info::add_logics_to_mform($mform, $ajaxformdata);
        $mform->addElement('html', '<hr>');

        // Action block.
        actions_info::add_actions_to_mform($mform, $ajaxformdata);
    }

    /**
     * Process data for dynamic submission
     * @return object $data
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();
        certificate_conditions::save_certificate_condition($data);
        return $data;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        if (!empty($this->_ajaxformdata['id'])) {
            $data = (object)$this->_ajaxformdata;
            $data = certificate_conditions::set_data_for_form($data);
        } else {
            $data = (object)$this->_ajaxformdata;
        }
        $this->set_data($data);
    }

    /**
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = [];
        if (empty($data['name'])) {
            $errors['name'] = get_string('error:entervalue', 'mod_booking');
        }

        if (empty($data['certificatefiltertype']) || $data['certificatefiltertype'] === '0') {
            $errors['certificatefiltertype'] = get_string('error:entervalue', 'mod_booking');
        }

        if (empty($data['certificatelogictype']) || $data['certificatelogictype'] === '0') {
            $errors['certificatelogictype'] = get_string('error:entervalue', 'mod_booking');
        }

        if (empty($data['certificateactiontype']) || $data['certificateactiontype'] === '0') {
            $errors['certificateactiontype'] = get_string('error:entervalue', 'mod_booking');
        }

        return $errors;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/edit_certificateconditions.php');
    }

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
        $ajaxformdata = $this->_ajaxformdata;
        $contextid = $ajaxformdata['contextid'] ?? context_system::instance()->id;
        $context = context::instance_by_id($contextid);
        require_capability('mod/booking:editcertificateconditions', $context);
    }

    /**
     * Prepare the ajax form data with all the information we need.
     *
     * @param array $ajaxformdata
     * @return void
     */
    private function prepare_ajaxformdata(array &$ajaxformdata) {
        global $DB;

        $id = $ajaxformdata['id'] ?? 0;

        if ($id <= 0) {
            return;
        }

        // If we have an ID, we retrieve the right condition from DB.
        $record = $DB->get_record('booking_cert_cond', ['id' => $id]);

        if (!$record) {
            return;
        }

        $jsonobject = json_decode((string)$record->filterjson);
        if (empty($ajaxformdata['certificatefiltertype'])) {
            $ajaxformdata['certificatefiltertype'] = $jsonobject->filtername ?? '0';
        }

        $jsonobject = json_decode((string)$record->logicjson);
        if (empty($ajaxformdata['certificatelogictype'])) {
            $ajaxformdata['certificatelogictype'] = $jsonobject->logicname ?? '0';
        }

        $jsonobject = json_decode((string)$record->actionjson);
        if (empty($ajaxformdata['certificateactiontype'])) {
            $ajaxformdata['certificateactiontype'] = $jsonobject->actionname ?? '0';
        }

        if (!isset($ajaxformdata['name'])) {
            $ajaxformdata['name'] = $record->name;
        }

        if (!isset($ajaxformdata['contextid'])) {
            $ajaxformdata['contextid'] = (int)$record->contextid;
        }

        if (!isset($ajaxformdata['isactive'])) {
            $ajaxformdata['isactive'] = $record->isactive;
        }
    }

    /**
     * Definition after data.
     * @return void
     */
    public function definition_after_data() {
        // Reserved for dynamic follow-up updates after set_data().
    }

}
