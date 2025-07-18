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
use mod_booking\option\dates_handler;
use mod_booking\option\time_handler;
use stdClass;

/**
 * Modal form to create single option dates which are not part of the date series.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modaloptiondateform extends \core_form\dynamic_form {
    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('static', 'aboutmodaloptiondateform', '', get_string('aboutmodaloptiondateform', 'mod_booking'));

        $repeatedoptiondates = [];

        // Options to store help button texts etc.
        $repeateloptions = [];

        $optiondatelabel = html_writer::tag(
            'b',
            get_string('optiondate', 'booking') . ' {no}',
            ['class' => 'optiondatelabel']
        );
        $repeatedoptiondates[] = $mform->createElement('static', 'optiondatelabel', $optiondatelabel);

        $repeatedoptiondates[] = $mform->createElement(
            'date_time_selector',
            'optiondatestart',
            get_string('optiondatestart', 'booking'),
            time_handler::set_timeintervall(),
        );
        // Info: Help buttons in repeat_elements groups are causing problems with Moodle 4.0.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $repeateloptions['optiondatestart']['helpbutton'] = ['optiondatestart', 'mod_booking']; */

        $repeatedoptiondates[] = $mform->createElement(
            'date_time_selector',
            'optiondateend',
            get_string('optiondateend', 'booking'),
            time_handler::set_timeintervall(),
        );
        // Info: Help buttons in repeat_elements groups are causing problems with Moodle 4.0.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $repeateloptions['optiondateend']['helpbutton'] = ['optiondateend', 'mod_booking']; */

        $repeatedoptiondates[] = $mform->createElement('submit', 'deleteoptiondate', get_string('deleteoptiondate', 'mod_booking'));

        $numberofoptiondatestoshow = 1;
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*if ($existingoptiondates = $DB->get_records('booking_optiondates')) {
            $numberofoptiondatestoshow = count($existingoptiondates);
        }*/

        $this->repeat_elements(
            $repeatedoptiondates,
            $numberofoptiondatestoshow,
            $repeateloptions,
            'optiondates',
            'addoptiondate',
            1,
            get_string('addoptiondate', 'mod_booking'),
            true,
            'deleteoptiondate'
        );
    }

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return \context_system
     */
    protected function get_context_for_dynamic_submission(): \context {
        return \context_system::instance();
    }

    /**
     * Check_access_for_dynamic_submission
     *
     * @return void
     *
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('moodle/site:config', \context_system::instance());
    }

    /**
     * Get custom optiondates.
     *
     * @return array
     *
     */
    protected function get_custom_optiondates(): array {
        $rv = [];
        if (!empty($this->_ajaxformdata['option']) && is_array($this->_ajaxformdata['option'])) {
            foreach (array_values($this->_ajaxformdata['option']) as $idx => $option) {
                $rv["option[$idx]"] = clean_param($option, PARAM_CLEANHTML);
            }
        }
        return $rv;
    }

    /**
     * Set_data_for_dynamic_submission
     *
     * @return void
     *
     */
    public function set_data_for_dynamic_submission(): void {
        $this->set_data($this->get_custom_optiondates());
    }

    /**
     * Process dynamic submission.
     *
     * @return array
     *
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();
        // Transform optiondates into an array of objects.
        $optiondatesarray = $this->transform_data_to_optiondates_array($data);

        return $optiondatesarray;
    }

    /**
     * Transform optiondates data from form to an array of optiondates objects.
     * @param stdClass $data data from form
     * @return array
     */
    protected function transform_data_to_optiondates_array(stdClass $data): array {
        $optiondatesarray = [];
        $resultarray = [];

        if (
            !empty($data->optiondatestart) && is_array($data->optiondatestart)
            && !empty($data->optiondateend) && is_array($data->optiondateend)
        ) {
            foreach ($data->optiondatestart as $idx => $optiondatestart) {
                $optiondate = new stdClass();
                $randomid = bin2hex(random_bytes(4));
                $optiondate->dateid = 'customdate-' . $randomid;
                $optiondate->starttimestamp = $optiondatestart;
                $optiondate->endtimestamp = $data->optiondateend[$idx];

                // If dates are on the same day, then show date only once.
                $optiondate->string = dates_handler::prettify_optiondates_start_end(
                    $optiondate->starttimestamp,
                    $optiondate->endtimestamp,
                    current_language()
                );

                $optiondatesarray[] = $optiondate;
            }
        }
        $resultarray['dates'] = $optiondatesarray;
        return $resultarray;
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

        foreach ($data['optiondatestart'] as $idx => $optiondatestart) {
            if ($optiondatestart >= $data['optiondateend'][$idx]) {
                $errors["optiondatestart[$idx]"] = get_string('erroroptiondatestart', 'booking');
                $errors["optiondateend[$idx]"] = get_string('erroroptiondateend', 'booking');
            }
        }

        return $errors;
    }

    /**
     * Get page url for dynamic submission
     *
     * @return \moodle_url
     *
     */
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return new \moodle_url('/mod/booking/editoptions.php');
    }
}
