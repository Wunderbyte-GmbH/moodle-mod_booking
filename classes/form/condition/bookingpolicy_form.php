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

namespace mod_booking\form\condition;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use cache;
use context;
use context_system;
use core_form\dynamic_form;
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
class bookingpolicy_form extends dynamic_form {

    /** @var int $id */
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
        require_capability('mod/booking:conditionforms', context_system::instance());
    }


    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        global $USER;

        $data = new stdClass();

        $formdata = $this->_ajaxformdata;

        // Todo: get these values.
        $optionid = $formdata['id'];

        $cache = cache::make('mod_booking', 'conditionforms');
        $userid = $data->userid ?? $USER->id;
        $cachekey = $userid . '_' . $optionid . '_bookingpolicy';

        if ($cachedata = $cache->get($cachekey)) {
            $data->bookingpolicy_checkbox = $cachedata->bookingpolicy_checkbox;
        }

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {

        global $USER;

        $data = $this->get_data();

        $cache = cache::make('mod_booking', 'conditionforms');
        $userid = $data->userid ?? $USER->id;
        $cachekey = $userid . '_' . $data->id . '_bookingpolicy';

        if ($data->bookingpolicy_checkbox == 1) {
            $cache->set($cachekey, $data);
        } else {
            $cache->delete($cachekey);
        }

        return $data;
    }

    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {

        $formdata = $this->_ajaxformdata;
        $mform = $this->_form;

        $id = $formdata['id'];

        // We have to pass by the option settings.
        $settings = singleton_service::get_instance_of_booking_option_settings((int)$id);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);

        $mform->addElement('hidden', 'id', $id);
        $mform->addElement('html', $bookingsettings->bookingpolicy);

        $mform->addElement('advcheckbox', 'bookingpolicy_checkbox', '', get_string('bookingpolicyagree', 'mod_booking'));
    }

    /**
     * Server-side form validation.
     * @param array $data
     * @param array $files
     * @return array $errors
     */
    public function validation($data, $files): array {
        $errors = [];

        if ($data['bookingpolicy_checkbox'] != 1) {
            $errors['bookingpolicy_checkbox'] = get_string('bookingpolicynotchecked', 'mod_booking');
        }

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
