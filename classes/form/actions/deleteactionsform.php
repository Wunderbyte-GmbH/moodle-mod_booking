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

namespace mod_booking\form\actions;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use context;
use context_system;
use core_form\dynamic_form;
use mod_booking\bo_actions\actions_info;
use moodle_url;
use stdClass;

/**
 * Dynamic actions form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @package mod_booking
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deleteactionsform extends dynamic_form {

    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $mform = $this->_form;

        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        $mform->addElement('hidden', 'id', $formdata['id'] ?? 0);
        $mform->addElement('hidden', 'optionid', $formdata['optionid'] ?? 0);
        $mform->addElement('hidden', 'cmid', $formdata['cmid'] ?? 0);

        $mform->addElement('static', 'deleteaction', get_string('reallydeleteaction', 'mod_booking'));

    }

    /**
     * Process data for dynamic submission
     * @return object $data
     */
    public function process_dynamic_submission() {
        $data = parent::get_data();

        actions_info::delete_action($data);

        return $data;
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $data = new stdClass();

        $this->set_data($data);

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
        $errors = [];

        return $errors;
    }


    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/editoptions.php');
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
        require_capability('moodle/site:config', context_system::instance());
    }
}
