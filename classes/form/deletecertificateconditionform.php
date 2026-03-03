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
 * Delete certificate condition form
 *
 * @package mod_booking
 * @copyright 2026 Your Name <you@example.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

use core_form\dynamic_form;
use context;
use context_system;
use mod_booking\local\certificate_conditions\certificate_conditions;
use moodle_url;

/**
 * Dynamic form to delete a certificate condition.
 */
class deletecertificateconditionform extends dynamic_form {
    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;
        $ajaxformdata = $this->_ajaxformdata;

        if (!empty($ajaxformdata['id'])) {
            $mform->addElement('hidden', 'id', $ajaxformdata['id']);
        }

        $mform->addElement('html', '<div><p>'
            . get_string('deletecertificateconditionconfirmtext', 'mod_booking')
            . '</p><p class="text-danger font-weight-bold">'
            . $ajaxformdata['name']
            . '</p></div>');
    }

    /**
     * Process data for dynamic submission
     * @return object $data
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();
        // Delete the condition by helper method
        certificate_conditions::delete_condition((int)$data->id);
        return $data;
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
     * Form validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        return [];
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

}
