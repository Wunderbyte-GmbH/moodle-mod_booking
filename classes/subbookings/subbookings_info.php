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
        $path = $CFG->dirroot . '/mod/booking/classes/subbookings/subbookings/*.php';
        $filelist = glob($path);

        $subbookings = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'mod_booking\subbookings\subbookings\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();
                $subbookings[] = $instance;
            }
        }

        return $subbookings;
    }

    /**
     * Get booking subbooking by name.
     * @param string $subbookingname
     * @return mixed
     */
    public static function get_subbooking(string $subbookingname) {
        global $CFG;

        $filename = 'mod_booking\subbookings\subbookings\\' . $subbookingname;

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

        $jsonobject = json_decode($record->json);

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

        // We receive the form with the data depending on the used handlers.
        // As we know which handler to call, we only instantiate one subbooking.
        $subbooking = self::get_subbooking($data->subbooking_type);

        // subbooking has to be saved last, because it actually writes to DB.
        $subbooking->save_subbooking($data);

        // self::execute_booking_subbookings();

        return;
    }

    /**
     * Execute all booking subbookings.
     */
    public static function execute_booking_subbookings() {
        global $DB;
        if ($records = $DB->get_records('booking_subbookings')) {
            foreach ($records as $record) {
                if (!$subbooking = self::get_subbooking($record->subbookingname)) {
                    continue;
                }
                // Important: Load the subbooking data from JSON into the subbooking instance.
                $subbooking->set_subbookingdata($record);
                // Now the subbooking can be executed.
                $subbooking->execute();
            }
        }
    }

    /**
     * After an option has been added or updated,
     * we need to check if any subbookings need to be applied or changed.
     * @param int $optionid
     */
    public static function execute_subbookings_for_option(int $optionid) {
        global $DB;

        // Only fetch subbookings which need to be reapplied. At the moment, it's just one.
        // Eventbased subbookings don't have to be reapplied.
        if ($records = $DB->get_records('booking_subbookings', ['subbookingname' => 'subbooking_daysbefore'])) {
            foreach ($records as $record) {
                if (!$subbooking = self::get_subbooking($record->subbookingname)) {
                    continue;
                }
                // Important: Load the subbooking data from JSON into the subbooking instance.
                $subbooking->set_subbookingdata($record);
                // Now the subbooking can be executed.
                $subbooking->execute($optionid);
            }
        }
    }

    /**
     * After a user has been added or updated,
     * we need to check if any subbookings need to be applied or changed.
     * @param int $userid
     */
    public static function execute_subbookings_for_user(int $userid) {
        global $DB;
        // Only fetch subbookings which need to be reapplied. At the moment, it's just one.
        // Eventbased subbookings don't have to be reapplied.
        if ($records = $DB->get_records('booking_subbookings', ['subbookingname' => 'subbooking_daysbefore'])) {
            foreach ($records as $record) {
                if (!$subbooking = self::get_subbooking($record->subbookingname)) {
                    continue;
                }
                // Important: Load the subbooking data into the subbooking instance.
                $subbooking->set_subbookingdata($record);
                // Now the subbooking can be executed.
                $subbooking->execute(null, $userid);
            }
        }
    }

    /**
     * Delete a booking subbooking by its ID.
     * @param int $subbookingid the ID of the subbooking
     */
    public static function delete_subbooking(int $subbookingid) {
        global $DB;
        $DB->delete_records('booking_subbookings', ['id' => (int)$subbookingid]);
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

        $subbookings = $DB->get_records('booking_subbooking_options', ['optionid' => $optionid]);

        $data = new subbookingslist($subbookings);
        $data->optionid = $formdata['optionid'];
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
        // $buttonargs = array('style' => 'visibility:hidden;');
        $categoryselect = [
            $mform->createElement('select', 'subbooking_type',
            get_string('bookingsubbooking', 'mod_booking'), $subbookingsforselect),
            $mform->createElement('submit', 'btn_subbookingtype', get_string('bookingsubbooking', 'mod_booking')) // $buttonargs)
        ];
        $mform->addGroup($categoryselect, 'subbooking_type', get_string('bookingsubbooking', 'mod_booking'), [' '], false);
        $mform->setType('btn_subbookingtype', PARAM_NOTAGS);

        if (isset($ajaxformdata['subbooking_type'])) {
            $subbooking = self::get_subbooking($ajaxformdata['subbooking_type']);
        } else {
            list($subbooking) = $subbookingtypes;
        }

        // Finally, after having chosen the right type of subbooking, we add the corresponding elements.
        $subbooking->add_subbooking_to_mform($mform);
    }
}
