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
use context_module;
use core_form\dynamic_form;
use moodle_url;

/**
 * Dynamic confirmation form to delete one sync rule.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_rule_delete_form extends dynamic_form {
    /**
     * Define form fields.
     */
    public function definition() {
        $mform = $this->_form;
        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        $mform->addElement('hidden', 'optionid', (int)($formdata['optionid'] ?? 0));
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'cmid', (int)($formdata['cmid'] ?? 0));
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'ruleid', (int)($formdata['ruleid'] ?? 0));
        $mform->setType('ruleid', PARAM_INT);

        $mform->addElement('static', 'confirmtext', '', get_string('syncruledeleteconfirm', 'mod_booking'));
    }

    /**
     * Process form submission.
     *
     * @return object
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();

        $result = \mod_booking\local\sync\booking_enrolment::delete_rule((int)$data->optionid, (int)$data->ruleid);
        $data->feedbackmessage = get_string('syncruledeleted', 'mod_booking', (int)$result['deletedanswers']);

        return $data;
    }

    /**
     * Set data for dynamic submission.
     */
    public function set_data_for_dynamic_submission(): void {
        $data = (object)($this->_ajaxformdata ?? $this->_customdata ?? []);
        $this->set_data($data);
    }

    /**
     * Validation.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array
     */
    public function validation($data, $files) {
        return [];
    }

    /**
     * Form page URL.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/subscribeusers.php');
    }

    /**
     * Context for dynamic submission.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = (int)($this->_ajaxformdata['cmid'] ?? $this->_customdata['cmid'] ?? 0);
        return context_module::instance($cmid);
    }

    /**
     * Permission check.
     */
    protected function check_access_for_dynamic_submission(): void {
        $context = $this->get_context_for_dynamic_submission();
        require_capability('mod/booking:bookforothers', $context);
    }
}
