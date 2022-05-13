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
use stdClass;

/**
 * Class editteachersforoptiondate_form
 *
 * See PHPdocs in the parent class to understand the purpose of each method
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class editteachersforoptiondate_form extends \core_form\dynamic_form {

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = $this->_ajaxformdata['cmid'];
        return context_module::instance($cmid);
    }

    /**
     * Check access for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:addeditownoption', $this->get_context_for_dynamic_submission());
    }

    public function set_data_for_dynamic_submission(): void {
        $data = new stdClass();
        $this->set_data($data);
    }

    public function process_dynamic_submission() {
        return $this->get_data();
    }

    public function definition() {
        $mform = $this->_form;

        $options = [
            'tags' => false,
            'multiple' => true
        ];

        $existingteachers = []; // TODO.

        $mform->addElement('autocomplete', 'teachersforoptiondate', 'todo blabla....',
            $existingteachers, $options);
    }

    public function validation($data, $files) {
        $errors = [];
        return $errors;
    }

    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/mod/booking/optiondates_teachers_report.php');
    }
}
