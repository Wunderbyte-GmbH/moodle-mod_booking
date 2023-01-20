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
 * Base class for booking subbookings information.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\subbookings;

use context_module;
use Exception;
use mod_booking\output\subbookingslist;
use MoodleQuickForm;
use stdClass;

/**
 * Class for additional information of booking subbookings.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbookings_info {

    /**
     * Add a list of subbookings and a modal to edit subbookings to an mform.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public static function add_subbookings_to_mform(MoodleQuickForm &$mform,
        array &$formdata = []) {

        global $PAGE;

        // Add header to Element.
        $mform->addElement('header', 'bookingsubbookingsheader', get_string('bookingsubbookingsheader', 'mod_booking'));

        if (!empty($formdata['optionid'])) {
            // Add a list of existing subbookings, including an edit and a delete button.
            self::add_list_of_existing_subbookings_for_this_option($mform, $formdata);

        } else {
            $mform->addElement('static', 'onlyaddsubbookingsonsavedoption', get_string('onlyaddsubbookingsonsavedoption', 'mod_booking'));
        }
    }

    /**
     * Get all booking subbookings.
     * @return array an array of booking subbookings (instances of class booking_subbooking).
     */
    public static function get_subbooking_types() {
        global $CFG;

        // First, we get all the available subbookings from our directory.
        $path = $CFG->dirroot . '/mod/booking/classes/subbookings/sb_types/*.php';
        $filelist = glob($path);

        $subbookings = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'mod_booking\subbookings\sb_types\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $subbookings[] = $instance;
            }
        }

        return $subbookings;
    }

    /**
     * Get booking subbooking by type.
     * @param string $subbookingtype
     * @return mixed
     */
    public static function get_subbooking(string $subbookingtype) {
        global $CFG;

        $filename = 'mod_booking\subbookings\sb_types\\' . $subbookingtype;

        // We instantiate all the classes, because we need some information.
        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * Prepare data to set data to form.
     *
     * @param object $data
     * @return object
     */
    public static function set_data_for_form(object &$data) {

        global $DB;

        if (empty($data->id)) {
            // Nothing to set, we return empty object.
            return new stdClass();
        }

        // If we have an ID, we retrieve the right subbooking from DB.
        $record = $DB->get_record('booking_subbooking_options', ['id' => $data->id]);

        $subbooking = self::get_subbooking($record->type);

        $data->optionid = $record->optionid;
        $data->subbooking_type = $record->type;

        // These function just add their bits to the object.
        $subbooking->set_defaults($data, $record);

        return (object)$data;

    }

    /**
     * Save all booking subbookings.
     * @param stdClass &$data reference to the form data
     * @return void
     */
    public static function save_subbooking(stdClass &$data) {

        global $USER;

        // We receive the form with the data depending on the used handlers.
        // As we know which handler to call, we only instantiate one subbooking.
        $subbooking = self::get_subbooking($data->subbooking_type);

        // subbooking has to be saved last, because it actually writes to DB.
        $subbooking->save_subbooking($data);

        // Every time we save the subbooking, we have to invalidate caches.
        // Trigger an event that booking option has been updated.

        $context = context_module::instance($data->cmid);
        $event = \mod_booking\event\bookingoption_updated::create(array('context' => $context, 'objectid' => $data->optionid,
                'userid' => $USER->id));
        $event->trigger();

        return;
    }

    /**
     * Delete a booking subbooking by its ID.
     * @param int $subbookingid the ID of the subbooking
     */
    public static function delete_subbooking(int $subbookingid) {
        global $DB;
        $DB->delete_records('booking_subbooking_options', ['id' => (int)$subbookingid]);
    }

    /**
     * This function adds a list of existing subbokings for this function.
     * Every line includes and edit and a delete no-submit-button.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    private static function add_list_of_existing_subbookings_for_this_option(MoodleQuickForm &$mform, array &$formdata = []) {

        global $DB, $PAGE;

        $optionid = $formdata['optionid'];
        $cmid = $formdata['cmid'];

        $subbookings = $DB->get_records('booking_subbooking_options', ['optionid' => $optionid]);

        $data = new subbookingslist($cmid, $optionid, $subbookings);
        $output = $PAGE->get_renderer('mod_booking');
        $html = $output->render_subbookingslist($data);
        $mform->addElement('html', $html);
    }

    /**
     * Retrieve all available subbookings and add a select form element to choose between.
     * This form also adds the corresponding form elements of the chosen type.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    public static function add_subbooking(MoodleQuickForm &$mform, array &$formdata) {
        // First, get all the type of subbookings there are.
        $subbookingtypes = self::get_subbooking_types();

        $subbookingsforselect = [];
        foreach ($subbookingtypes as $subbooking) {
            $fullclassname = get_class($subbooking); // With namespace.
            $classnameparts = explode('\\', $fullclassname);
            $shortclassname = end($classnameparts); // Without namespace.
            $subbookingsforselect[$shortclassname] = $subbooking->get_name_of_subbooking();
        }

        $mform->registerNoSubmitButton('btn_subbookingtype');
        $buttonargs = array('style' => 'visibility:hidden;');
        $categoryselect = [
            $mform->createElement('select', 'subbooking_type',
            get_string('bookingsubbooking', 'mod_booking'), $subbookingsforselect),
            $mform->createElement('submit',
                'btn_subbookingtype',
                get_string('bookingsubbooking', 'mod_booking'),
                $buttonargs) // $buttonargs)
        ];
        $mform->addGroup($categoryselect, 'subbooking_type', get_string('bookingsubbooking', 'mod_booking'), [' '], false);
        $mform->setType('btn_subbookingtype', PARAM_NOTAGS);

        if (isset($formdata['subbooking_type'])) {
            $subbooking = self::get_subbooking($formdata['subbooking_type']);
        } else {
            list($subbooking) = $subbookingtypes;
        }

        // Finally, after having chosen the right type of subbooking, we add the corresponding elements.
        $subbooking->add_subbooking_to_mform($mform, $formdata);
    }

    /**
     * This function checks if a subbooking blocks the main booking option.
     * A subbooking can use the option as container only and therby block its booking.
     * When this is the case, the subbooking can return a description (which could be a modal eg.)...
     * ... to render instead of booking-button.
     * If there is more than one blocking subbooking, this is recognized as well.
     *
     * @param object $settings
     * @return bool
     */
    public static function is_blocked(object $settings) {

        $isblocked = false;
        foreach ($settings->subbookings as $subbooking) {
            if ($subbooking->block == 1) {
                $isblocked = true;
            }
        }

        return $isblocked;
    }

    /**
     * This function checks if there are any not blocking subbookings in the main booking option.
     * While the not blocking subblocking doesn't prevent the blocking of the main option...
     * ... it still needs to announce the presence of options...
     * ... which may want to introduce a page in the booking process.
     * Blockings subbookings are handled by a different bo_condition.
     *
     * @param object $settings
     * @return bool
     */
    public static function not_blocked(object $settings) {

        $notblocked = false;
        foreach ($settings->subbookings as $subbooking) {
            if ($subbooking->block != 1) {
                $notblocked = true;
            }
        }

        return $notblocked;
    }

    /**
     * Load all the available subbookings for a specific ID and return them as array.
     * We return the instantiated classes to be able to call functions on them.
     *
     * @param integer $optionid
     * @return array
     */
    public static function load_subbookings(int $optionid) {

        global $DB;

        $records = $DB->get_records('booking_subbooking_options', ['optionid' => $optionid]);
        $returnarray = [];

        foreach ($records as $record) {
            $subbooking = self::get_subbooking($record->type);
            $subbooking->set_subbookingdata($record);
            $returnarray[] = $subbooking;
        }

        return $returnarray;
    }
}
