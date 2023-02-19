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

namespace mod_booking\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use context;
use context_system;
use core_form\dynamic_form;
use mod_booking\booking_subbookit;
use mod_booking\singleton_service;
use mod_booking\subbookings\subbookings_info;
use moodle_url;
use stdClass;

/**
 * Add holidays form.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbooking_additionalperson_form extends dynamic_form {

    /** @param int $id */
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
        require_capability('moodle/site:config', context_system::instance());
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
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        global $PAGE;

        $data = $this->get_data();

        return $data;
    }

    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {

        global $OUTPUT;

        $formdata = $this->_ajaxformdata;
        $mform = $this->_form;

        $id = $formdata['id'];

        $mform->addElement('hidden', 'id', $id);

        $mform->addElement('static', 'subbookingaddpersondesc', '', get_string('subbooking_additionalperson_desc', 'mod_booking'));

        $mform->registerNoSubmitButton('btn_addperson');
        $buttonargs = array('style' => 'visibility:hidden;');
        $categoryselect = [
            $mform->createElement('select', 'subbooking_addpersons',
            get_string('subbooking_addpersons', 'mod_booking'), [1 => 1, 2 => 2, 3 => 3, 4 => 4]),
            $mform->createElement('submit',
                'btn_addperson',
                get_string('subbooking_addpersons', 'mod_booking'),
                $buttonargs)
        ];
        $mform->addGroup($categoryselect, 'subbooking_addpersons', get_string('subbooking_addpersons', 'mod_booking'), [' '], false);
        $mform->setType('btn_addperson', PARAM_NOTAGS);

        $bookedpersons = $formdata['subbooking_addpersons'] ?? 1;
        $counter = 1;
        while ($counter <= $bookedpersons) {
            $mform->addElement('static', 'person_' . $counter, '', get_string('personnr', 'mod_booking', $counter));
            $mform->addElement('text', 'person_firstname_' . $counter, get_string('firstname'));
            $mform->addElement('text', 'person_lastname_' . $counter, get_string('lastname'));
            $mform->addElement('text', 'person_age_' . $counter, get_string('age', 'mod_booking'));
            $mform->setType('person_age_' . $counter, PARAM_INT);
            $counter++;
        }

        $subbooking = subbookings_info::get_subbooking_by_area_and_id('subbooking', $formdata['id']);
        $settings = singleton_service::get_instance_of_booking_option_settings($subbooking->optionid);

        $html = booking_subbookit::render_bookit_button($settings, $subbooking->id);

        $mform->addElement('html', $html);

        // Buttons.
        // $this->add_action_buttons();
    }

    /**
     * Server-side form validation.
     * @param array $data
     * @param array $files
     * @return array $errors
     */
    public function validation($data, $files): array {
        $errors = [];

        return $errors;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/view.php', ['id' => $this->id]);
    }
}
