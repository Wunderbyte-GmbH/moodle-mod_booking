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
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use core_component;
use dml_exception;
use coding_exception;
use core_form\dynamic_form;
use context;
use context_module;
use context_system;
use Exception;
use mod_booking\message_controller;

defined('MOODLE_INTERNAL') || die();
require_once("$CFG->libdir/formslib.php");
require_once($CFG->dirroot . '/mod/booking/lib.php');

use mod_booking\booking_option;
use mod_booking\option\fields_info;
use mod_booking\singleton_service;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Class to send mails to teachers.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_mail_to_teachers extends dynamic_form {
    /**
     * {@inheritDoc}
     * @see moodleform::definition()
     */
    public function definition() {
        $mform = $this->_form;

        $submitdata = $this->_ajaxformdata;

        $mform->addElement(
            'text',
            'subject',
            get_string('subject'),
            ['size' => '64']
        );
        $mform->addElement('editor', 'emailbody', get_string('emailbody', 'booking'));

        if (isset($submitdata['checkedids'])) {
            $mform->addElement('hidden', 'checkedids', $submitdata['checkedids']);
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

        $errors = [];

        return $errors;
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        $cmid = $this->_ajaxformdata['cmid'] ?? 0;

        if (empty($cmid)) {
            return context_system::instance();
        }

        return context_module::instance($cmid);
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
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
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission() {

        // Get data from form.
        $data = $this->get_data();
        $checkedids = explode(",", $data->checkedids);
        // Apply values to each of the bookingoptions.
        $alreadysentto = [];
        foreach ($checkedids as $bookingoptionid) {
            $settings = singleton_service::get_instance_of_booking_option_settings($bookingoptionid);
            $boteachers = $settings->teachers;
            foreach ($boteachers as $teacherid => $teacher) {
                // Because it's a bulk operation, make sure, teacher didn't recieve mail yet.
                if (in_array($teacherid, $alreadysentto)) {
                    continue;
                }
                $alreadysentto[] = $teacherid;
                try {
                    // Use message controller to send the message.
                    $messagecontroller = new message_controller(
                        MOD_BOOKING_MSGCONTRPARAM_SEND_NOW,
                        MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE,
                        $settings->cmid,
                        $bookingoptionid,
                        $teacherid,
                        null,
                        null,
                        null,
                        $data->subject ?? '',
                        $data->emailbody['text'] ?? '',
                    );
                    $messagecontroller->send_or_queue();
                } catch (Exception $e) {
                    continue;
                }
            }
        }

        return $data;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/editoption.php');
    }
}
