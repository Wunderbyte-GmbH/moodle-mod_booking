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
 * Dynamic semester cancel confirm form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use context;
use context_module;
use context_system;
use core_form\dynamic_form;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

/**
 * Add holidays form.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_confirmcancel extends dynamic_form {
    /** @var int $cmid */
    private $cmid = null;

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        $ajaxformdata = $this->_ajaxformdata;

        if (!empty($ajaxformdata['optionid'])) {
            $settings = singleton_service::get_instance_of_booking_option_settings($ajaxformdata['optionid']);
            return context_module::instance($settings->cmid);
        }

        return context_system::instance();
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {

        $context = $this->get_context_for_dynamic_submission();
        require_capability('mod/booking:updatebooking', $context);
    }


    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $data = (object)$this->_ajaxformdata;
        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        global $PAGE;

        $data = $this->get_data();
        $undo = $data->status == 1 ? true : false;
        $reason = $data->cancelreason ?? '';

        booking_option::cancelbookingoption($data->optionid, $reason, $undo);

        return $data;
    }

    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {

        $mform = $this->_form;

        $ajaxformdata = $this->_ajaxformdata;

        $mform->addElement('hidden', 'optionid', $ajaxformdata['optionid']);
        $mform->addElement('hidden', 'status', $ajaxformdata['status']);

        if ($ajaxformdata['status'] != 1) {
            $mform->addElement(
                'text',
                'cancelreason',
                get_string("cancelreason", "mod_booking"),
                ['size' => '40']
            );
        } else {
            $mform->addElement(
                'static',
                'undocancelreason',
                '',
                get_string("undocancelreason", "mod_booking")
            );
        }

    }

    /**
     * Server-side form validation.
     * @param array $data
     * @param array $files
     * @return array $errors
     */
    public function validation($data, $files): array {
        $errors = [];

        if ($data['status'] != 1) {
            if (empty($data['cancelreason'])) {
                $errors['cancelreason'] = get_string('nocancelreason', 'mod_booking');
            }
        }

        return $errors;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/semesters.php', ['id' => $this->cmid]);
    }
}
