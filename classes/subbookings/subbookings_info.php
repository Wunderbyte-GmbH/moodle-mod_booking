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
use mod_booking\booking_option_settings;
use mod_booking\output\subbookingslist;
use mod_booking\utils\wb_payment;
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
    public static function add_subbookings_to_mform(
        MoodleQuickForm &$mform,
        array &$formdata = []
    ) {

        if (get_config('booking', 'showsubbookings') && wb_payment::pro_version_is_activated()) {
            // Add header to Element.
            $mform->addElement(
                'header',
                'bookingsubbookingsheader',
                '<i class="fa fa-fw fa-sitemap" aria-hidden="true"></i>&nbsp;'
                    . get_string('bookingsubbookingsheader', 'mod_booking')
            );

            if (!empty($formdata['optionid'])) {
                // Add a list of existing subbookings, including an edit and a delete button.
                self::add_list_of_existing_subbookings_for_this_option($mform, $formdata);
            } else {
                $mform->addElement(
                    'static',
                    'onlyaddsubbookingsonsavedoption',
                    get_string('onlyaddsubbookingsonsavedoption', 'mod_booking')
                );
            }
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
            $filename = 'mod_booking\\subbookings\\sb_types\\' . $path['filename'];

            // NOTE: In the future we'll activate additional subbookings.
            // But right now, we ONLY use the additional person booking.
            // So we use the next 3 lines to skip anything else.
            if (
                !in_array(
                    $path['filename'],
                    [
                        'subbooking_additionalperson',
                        'subbooking_additionalitem',
                    ]
                )
            ) {
                continue;
            }

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
     * @return booking_subbooking
     */
    public static function get_subbooking(string $subbookingtype) {
        global $CFG;

        $filename = 'mod_booking\\subbookings\\sb_types\\' . $subbookingtype;

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
     * @param stdClass $data reference to the form data
     * @return void
     */
    public static function save_subbooking(stdClass &$data) {

        global $USER;

        // We receive the form with the data depending on the used handlers.
        // As we know which handler to call, we only instantiate one subbooking.
        $subbooking = self::get_subbooking($data->subbooking_type);

        // Subbooking has to be saved last, because it actually writes to DB.
        $subbooking->save_subbooking($data);

        // Every time we save the subbooking, we have to invalidate caches.
        // Trigger an event that booking option has been updated.

        $context = context_module::instance($data->cmid);
        $event = \mod_booking\event\bookingoption_updated::create([
                                                                    'context' => $context,
                                                                    'objectid' => $data->optionid,
                                                                    'userid' => $USER->id,
                                                                    'relateduserid' => $USER->id,
                                                                ]);
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
     * This function adds a list of existing subbookings for this function.
     * Every line includes and edit and a delete no-submit-button.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @return void
     */
    private static function add_list_of_existing_subbookings_for_this_option(MoodleQuickForm &$mform, array &$formdata = []) {

        global $DB, $PAGE;

        $optionid = $formdata['id'] ?? $formdata['optionid'] ?? 0;
        $cmid = $formdata['cmid'] ?? 0;

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
        $buttonargs = ['style' => 'visibility:hidden;'];
        $categoryselect = [
            $mform->createElement(
                'select',
                'subbooking_type',
                get_string('bookingsubbooking', 'mod_booking'),
                $subbookingsforselect
            ),
            $mform->createElement(
                'submit',
                'btn_subbookingtype',
                get_string('bookingsubbooking', 'mod_booking'),
                $buttonargs
            ),
        ];
        $mform->addGroup($categoryselect, 'subbooking_type', get_string('bookingsubbooking', 'mod_booking'), [' '], false);
        $mform->setType('btn_subbookingtype', PARAM_NOTAGS);

        if (isset($formdata['subbooking_type'])) {
            $subbooking = self::get_subbooking($formdata['subbooking_type']);
        } else {
            [$subbooking] = $subbookingtypes;
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
     * Blocking subbookings are handled by a different bo_condition.
     *
     * @param booking_option_settings $settings
     * @param mixed $userid
     *
     * @return [type]
     *
     */
    public static function has_soft_subbookings(booking_option_settings $settings, $userid) {

        foreach ($settings->subbookings as $subbooking) {
            if ($subbooking->is_blocking($settings, $userid)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load all the available subbookings for a specific ID and return them as array.
     * We return the instantiated classes to be able to call functions on them.
     *
     * @param int $optionid
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

    /**
     * Returns a given subbooking option instance.
     * The ID might actually come from the area.
     *
     * @param string $area
     * @param int $itemid
     * @return booking_subbooking
     */
    public static function get_subbooking_by_area_and_id(string $area, int $itemid) {
        global $DB;

        // First, we need to isolate the id of the subbooking. We might find it in the area.

        $array = explode('-', $area);

        $area = array_shift($array);
        $sbid = array_shift($array);

        // If we found an id, we use it as itemid and design the  slotid.
        if ($sbid) {
            $record = $DB->get_record('booking_subbooking_options', ['id' => $sbid]);
        } else {
            $record = $DB->get_record('booking_subbooking_options', ['id' => $itemid]);
        }

        // If subbooking type is missing we cannot instantiate a subbooking.
        if (empty($record->type)) {
            return null;
        }

        $subbooking = self::get_subbooking($record->type);
        $subbooking->set_subbookingdata($record);

        return $subbooking;
    }

    /**
     * Function to save answer to a subbooking.
     * We can provide a status which will then decide what actually happens.
     *
     * @param string $area
     * @param int $itemid
     * @param int $status
     * @param int $userid
     * @return bool
     */
    public static function save_response(string $area, int $itemid, int $status, $userid = 0): bool {

        global $USER;

        // Make sure we have the right user.
        if ($userid == 0) {
            $userid = $USER->id;
        }

        $subbooking = self::get_subbooking_by_area_and_id($area, $itemid);

        if (empty($subbooking)) {
            // No subbooking could be found.
            return true;
        }

        // Do we need to update and if so, which records?

        switch ($status) {
            case MOD_BOOKING_STATUSPARAM_BOOKED: // We actually book.
                // Check if there was a reserved or waiting list entry before.
                self::update_or_insert_answer(
                    $subbooking,
                    $itemid,
                    $userid,
                    MOD_BOOKING_STATUSPARAM_BOOKED,
                    [MOD_BOOKING_STATUSPARAM_RESERVED, MOD_BOOKING_STATUSPARAM_WAITINGLIST]
                );
                break;
            case MOD_BOOKING_STATUSPARAM_WAITINGLIST: // We move to the waiting list.
                // Check if there was a reserved entry before.
                self::update_or_insert_answer(
                    $subbooking,
                    $itemid,
                    $userid,
                    MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                    [MOD_BOOKING_STATUSPARAM_RESERVED]
                );
                break;
            case MOD_BOOKING_STATUSPARAM_RESERVED: // We only want to use shortterm reservation.
                // Check if there was a reserved or waiting list entry before.
                self::update_or_insert_answer(
                    $subbooking,
                    $itemid,
                    $userid,
                    MOD_BOOKING_STATUSPARAM_RESERVED,
                    [MOD_BOOKING_STATUSPARAM_WAITINGLIST, MOD_BOOKING_STATUSPARAM_RESERVED]
                );
                break;
            case MOD_BOOKING_STATUSPARAM_NOTBOOKED: // We only want to delete the shortterm reservation.
                // Check if there was a reserved entry before.
                self::update_or_insert_answer(
                    $subbooking,
                    $itemid,
                    $userid,
                    MOD_BOOKING_STATUSPARAM_NOTBOOKED,
                    [MOD_BOOKING_STATUSPARAM_RESERVED]
                );
                break;
            case MOD_BOOKING_STATUSPARAM_DELETED: // We delete the existing subscription.
                // Check if there was a booked entry before.
                self::update_or_insert_answer(
                    $subbooking,
                    $itemid,
                    $userid,
                    MOD_BOOKING_STATUSPARAM_DELETED,
                    [MOD_BOOKING_STATUSPARAM_BOOKED]
                );
                break;
        };
        return true;
    }

    /**
     * This function looks for old answers of a special type and if there are any, it deletes them.
     * Also, it creates a new answer record if necessary.
     *
     * @param object $subbooking
     * @param int $itemid
     * @param int $userid
     * @param int $newstatus
     * @param array $oldstatus
     * @return void
     */
    private static function update_or_insert_answer(
        object $subbooking,
        int $itemid,
        int $userid,
        int $newstatus,
        array $oldstatus
    ) {

        global $DB, $USER;

        $now = time();

        if ($records = self::return_subbooking_answers($subbooking->id, $itemid, $subbooking->optionid, $userid, $oldstatus)) {
            while (count($records) > 0) {
                $record = array_pop($records);
                // We already popped one record, so count has to be 0.
                if (count($records) == 0 && $newstatus !== MOD_BOOKING_STATUSPARAM_NOTBOOKED) {
                    $record->timemodified = $now;
                    $record->status = $newstatus;
                    $DB->update_record('booking_subbooking_answers', $record);
                } else {
                    // This is just for cleaning, should never happen.
                    $DB->delete_records('booking_subbooking_answers', ['id' => $record->id]);
                }
            }
        } else if (
            $newstatus !== MOD_BOOKING_STATUSPARAM_DELETED
            || $newstatus !== MOD_BOOKING_STATUSPARAM_NOTBOOKED
        ) {
            $data = $subbooking->return_subbooking_information($itemid, $userid);
            $record = (object)[
                'itemid' => $itemid,
                'sboptionid' => $subbooking->id,
                'optionid' => $subbooking->optionid,
                'userid' => $userid,
                'usermodified' => $USER->id,
                'status' => $newstatus,
                'json' => $subbooking->return_answer_json($itemid),
                'timestart' => $data['coursestarttime'] ?? null,
                'timeend' => $data['courseendtime'] ?? null,
                'timecreated' => $now,
                'timemodified' => $now,
            ];

            $DB->insert_record('booking_subbooking_answers', $record);
        }
    }

    /**
     * Returns all answer records for a certain PARAMSTATUS if defined.
     *
     * @param int $sboid
     * @param int $itemid
     * @param int $optionid
     * @param int $userid
     * @param array $status
     * @return array
     */
    private static function return_subbooking_answers(int $sboid, int $itemid, int $optionid, int $userid, array $status = []) {

        global $DB;

        // We always fetch all the entries.
        $sql = "SELECT *
                FROM {booking_subbooking_answers}
                WHERE itemid=:itemid
                AND sboptionid=:sboptionid
                AND userid=:userid
                AND optionid=:optionid";

        $params = [
            'itemid' => $itemid,
            'sboptionid' => $sboid,
            'userid' => $userid,
            'optionid' => $optionid,
        ];

        if (!empty($status)) {
            [$inorequal, $ieparams] = $DB->get_in_or_equal($status, SQL_PARAMS_NAMED);
            $sql .= " AND status $inorequal";

            foreach ($ieparams as $key => $value) {
                $params[$key] = $value;
            }
        }

        $records = $DB->get_records_sql($sql, $params);

        return $records;
    }

    /**
     * Returns an array of area and itemid, written for unloading subbookings from shopping cart.
     *
     * @param int $optionid
     * @return array
     */
    public static function return_array_of_subbookings(int $optionid): array {
        global $DB;

        $records = $DB->get_records('booking_subbooking_options', ['optionid' => $optionid]);
        $returnarray = [];

        foreach ($records as $record) {
            $returnarray[] = (object)[
                'area' => 'subbooking',
                'itemid' => $record->id,
            ];
        }

        return $returnarray;
    }
}
