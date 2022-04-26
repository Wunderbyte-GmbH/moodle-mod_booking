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

use html_writer;

/**
 * Modal form to create single option dates which are not part of the date series.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modaloptiondateform extends \core_form\dynamic_form {

    protected function get_context_for_dynamic_submission(): \context {
        return \context_system::instance();
    }

    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/site:config', \context_system::instance());
    }

    protected function get_custom_optiondates(): array {
        $rv = [];
        if (!empty($this->_ajaxformdata['option']) && is_array($this->_ajaxformdata['option'])) {
            foreach (array_values($this->_ajaxformdata['option']) as $idx => $option) {
                $rv["option[$idx]"] = clean_param($option, PARAM_CLEANHTML);
            }
        }
        return $rv;
    }

    public function set_data_for_dynamic_submission(): void {
        $this->set_data($this->get_custom_optiondates());
    }

    public function process_dynamic_submission() {
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*if ($this->get_data()->name === 'error') {
            // For testing exceptions.
            throw new \coding_exception('Name is error');
        }*/
        return $this->get_data();
    }

    public function definition() {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('static', 'aboutmodaloptiondateform', '', get_string('aboutmodaloptiondateform', 'mod_booking'));

        $repeatedoptiondates = [];

        // Options to store help button texts etc.
        $repeateloptions = [];

        $optiondatelabel = html_writer::tag('b', get_string('optiondate', 'booking') . ' {no}',
            array('class' => 'optiondatelabel'));
        $repeatedoptiondates[] = $mform->createElement('static', 'optiondatelabel', $optiondatelabel);

        $repeatedoptiondates[] = $mform->createElement('date_time_selector', 'optiondatestart',
            get_string('optiondatestart', 'booking'));
        $repeateloptions['optiondatestart']['helpbutton'] = ['optiondatestart', 'mod_booking'];

        $repeatedoptiondates[] = $mform->createElement('date_time_selector', 'optiondateend',
            get_string('optiondateend', 'booking'));
        $repeateloptions['optiondateend']['helpbutton'] = ['optiondateend', 'mod_booking'];

        $repeatedoptiondates[] = $mform->createElement('submit', 'deleteoptiondate', get_string('deleteoptiondate', 'mod_booking'));

        $numberofoptiondatestoshow = 1;
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*if ($existingoptiondates = $DB->get_records('booking_optiondates')) {
            $numberofoptiondatestoshow = count($existingoptiondates);
        }*/

        $this->repeat_elements($repeatedoptiondates, $numberofoptiondatestoshow,
            $repeateloptions, 'optiondates', 'addoptiondate', 1, get_string('addoptiondate', 'mod_booking'),
            true, 'deleteoptiondate');

        $this->add_action_buttons();
    }

    public function validation($data, $files) {
        $errors = [];

        return $errors;
    }

    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/mod/booking/editoptions.php');
    }
}
