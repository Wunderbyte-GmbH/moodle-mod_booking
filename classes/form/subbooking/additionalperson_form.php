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

namespace mod_booking\form\subbooking;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use cache;
use cache_helper;
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
class additionalperson_form extends dynamic_form {
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
        $subbooking = subbookings_info::get_subbooking_by_area_and_id('subbooking', $formdata['id']);

        // Todo: get these values.
        $userid = $USER->id; // Might be a different user!
        $optionid = $subbooking->optionid;
        $subbookingid = $subbooking->id;

        $cache = cache::make('mod_booking', 'subbookingforms');
        $cachekey = $userid . '_' . $optionid . '_' . $subbookingid;

        $data = $cache->get($cachekey);

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        global $PAGE, $USER;

        $data = $this->get_data();

        self::store_data_in_cache($data);

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
        $subbooking = subbookings_info::get_subbooking_by_area_and_id('subbooking', $id);

        // We know this is already a filled out form when we have this key.
        if (isset($formdata["subbooking_addpersons"])) {
            $data = (object)$formdata;

            self::store_data_in_cache($data);
        } else {
            // We still might find sth in cache.
            $data = self::get_data_from_cache($id);
        }

        $mform->addElement('hidden', 'id', $id);

        $mform->addElement(
            'static',
            'subbookingaddpersondescription',
            '',
            $subbooking->description ?? get_string('subbookingadditionalperson_desc', 'mod_booking')
        );

        $mform->registerNoSubmitButton('btn_addperson');
        $buttonargs = ['style' => 'visibility:hidden;'];
        $categoryselect = [
            $mform->createElement(
                'select',
                'subbooking_addpersons',
                get_string('subbookingaddpersons', 'mod_booking'),
                [0 => 0, 1 => 1, 2 => 2, 3 => 3, 4 => 4]
            ),
            $mform->createElement(
                'submit',
                'btn_addperson',
                get_string('subbookingaddpersons', 'mod_booking'),
                $buttonargs
            ),
        ];
        $mform->addGroup($categoryselect, 'subbooking_addpersons', get_string('subbookingaddpersons', 'mod_booking'), ' ', false);
        $mform->setType('btn_addperson', PARAM_NOTAGS);

        $bookedpersons = $formdata['subbooking_addpersons'] ?? $data->subbooking_addpersons ?? 0;
        $counter = 1;
        while ($counter <= $bookedpersons) {
            $mform->addElement('static', 'person_' . $counter, '', get_string('personnr', 'mod_booking', $counter));
            $mform->addElement('text', 'person_firstname_' . $counter, get_string('firstname'));
            $mform->addElement('text', 'person_lastname_' . $counter, get_string('lastname'));
            $mform->addElement('text', 'person_age_' . $counter, get_string('age', 'mod_booking'));
            $mform->setType('person_age_' . $counter, PARAM_INT);
            $counter++;
        }

        // We only show the "Add to cart button" when we actually have sth to add to the cart.
        if ($bookedpersons > 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($subbooking->optionid);
            $html = booking_subbookit::render_bookit_button($settings, $subbooking->id);
            $mform->addElement('html', $html);
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

        $counter = 1;
        while ($data["subbooking_addpersons"] >= $counter) {
            if (empty($data['person_firstname_' . $counter])) {
                $errors['person_firstname_' . $counter] = get_string('error:entervalue', 'mod_booking');
            }
            if (empty($data['person_lastname_' . $counter])) {
                $errors['person_lastname_' . $counter] = get_string('error:entervalue', 'mod_booking');
            }
            if (empty($data['person_age_' . $counter])) {
                $errors['person_age_' . $counter] = get_string('error:entervalue', 'mod_booking');
            }
            $counter++;
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

    /**
     * Helper function to store data in cache.
     *
     * @param object $data
     * @param ?object $user
     * @return void
     */
    public static function store_data_in_cache($data, ?object $user = null) {

        global $USER;

        if (!$user) {
            $user = $USER;
        }

        $subbooking = subbookings_info::get_subbooking_by_area_and_id('subbooking', $data->id);

        // Todo: get these values.
        $userid = $user->id; // Might be a different user!
        $optionid = $subbooking->optionid;
        $subbookingid = $subbooking->id;

        $cache = cache::make('mod_booking', 'subbookingforms');
        $cachekey = $userid . '_' . $optionid . '_' . $subbookingid;

        $cache->set($cachekey, $data);
    }

    /**
     * Helper function to store data in cache.
     *
     * @param int $subbookingid
     * @param object|null $user
     * @return object
     */
    public static function get_data_from_cache($subbookingid, ?object $user = null) {

        global $USER;

        if (!$user) {
            $user = $USER;
        }

        $subbooking = subbookings_info::get_subbooking_by_area_and_id('subbooking', $subbookingid);

        // Todo: get these values.
        $userid = $user->id; // Might be a different user!
        $optionid = $subbooking->optionid;
        $subbookingid = $subbooking->id;

        $cache = cache::make('mod_booking', 'subbookingforms');
        $cachekey = $userid . '_' . $optionid . '_' . $subbookingid;

        return $cache->get($cachekey);
    }
}
