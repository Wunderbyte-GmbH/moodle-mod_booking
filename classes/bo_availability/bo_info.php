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
 * Base class for conditional availability information (for module or section).
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use context_module;
use html_writer;
use mod_booking\booking_option_settings;
use mod_booking\output\button_notifyme;
use mod_booking\output\col_price;
use mod_booking\singleton_service;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * class for conditional availability information of a booking option
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bo_info {

    /** @var bool Visibility flag (eye icon) */
    protected $visible;

    /** @var string Availability data as JSON string */
    protected $availability;

    /** @var int Optionid for a given option */
    protected $optionid;

    /** @var int userid for a given user */
    protected $userid;

    /**
     * Constructs with item details.
     *
     */
    public function __construct(booking_option_settings $settings) {

        global $USER;

        $this->optionid = $settings->id;
        $this->userid = $USER->id;

    }

    /**
     * Determines whether this particular item is currently available
     * according to the availability criteria.
     *
     * - This does not include the 'visible' setting (i.e. this might return
     *   true even if visible is false); visible is handled independently.
     * - This does not take account of the viewhiddenactivities capability.
     *   That should apply later.
     *
     * Depending on options selected, a description of the restrictions which
     * mean the student can't view it (in HTML format) may be stored in
     * $information. If there is nothing in $information and this function
     * returns false, then the activity should not be displayed at all.
     *
     * This function displays debugging() messages if the availability
     * information is invalid.
     *
     * @param int optionid
     * @param int $userid If set, specifies a different user ID to check availability for
     * @return array [isavailable, description]
     */
    public function is_available(int $optionid = null, int $userid = 0):array {

        global $USER, $CFG;

        // We only get full description when we book for another user.
        // It's a clear sign of higher rights.
        $full = $USER->id == $userid ? false : true;

        if (!$optionid) {
            $optionid = $this->optionid;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $conditions = self::get_conditions(CONDPARAM_HARDCODED_ONLY);

        if (!empty($settings->availability)) {

            $availabilityarray = json_decode($settings->availability);

            // If the json is not valid, we throw an error.
            if (!is_array($availabilityarray) && (!$availabilityarray || empty($availabilityarray))) {
                throw new moodle_exception('availabilityjsonerror', 'mod_booking');
            }

            $conditions = array_merge($conditions, $availabilityarray);
        }

        // Resolve optional parameters.
        if (!$userid) {
            $userid = $USER->id;
        }

        $resultsarray = [];

        // Run through all the individual conditions to make sure they are fullfilled.
        foreach ($conditions as $condition) {

            $classname = get_class($condition);

            // First, we have the hardcoded conditions already as instances.
            if ($classname !== 'stdClass') {
                list($isavailable, $description) = $condition->get_description($settings, $userid, $full);
                $resultsarray[$condition->id] = ['id' => $condition->id,
                    'isavailable' => $isavailable,
                    'description' => $description];
            } else {
                // Else we need to instantiate the condition first.

                $classname = 'mod_booking\bo_availability\conditions\\' . $condition->name;

                if (class_exists($classname)) {

                    // We now set the id from the json for this instance.
                    // We might actually use a hardcoded condition with a negative id...
                    // ... also as customized condition with positive id.
                    $instance = new $classname($condition->id);
                    $instance->customsettings = $condition;

                } else {
                    // Should never happen, but just go on in case of.
                    continue;
                }
                // Then pass the availability-parameters.
                list($isavailable, $description) = $instance->get_description($settings, $userid, $full);
                $resultsarray[$condition->id] = ['id' => $condition->id,
                    'isavailable' => $isavailable,
                    'description' => $description];
            }

            // Now we might need to override the result of a previous condition which has been resolved as false before.
            if (!empty($condition->overrides)
            && isset($resultsarray[$condition->overrides])) {

                // We know we have a result to override. It depends now on the operator what to do.
                // If the operator is or, we change the previous result from false to true, if this result is true.
                // OR we change this result to true, if the previous was true.

                switch ($condition->overrideoperator) {
                    case 'OR':
                        // If one of the two results is true, both are true.
                        if ($resultsarray[$condition->overrides]['isavailable']
                            || $resultsarray[$condition->id]['isavailable']) {
                                $resultsarray[$condition->overrides]['isavailable'] = true;
                                $resultsarray[$condition->id]['isavailable'] = true;
                        };
                        break;
                    case 'AND':
                        // We need to return the right description which actually failed.
                        // If both fail, we want to return both descriptions.

                        $description = '';
                        if (!$resultsarray[$condition->overrides]['isavailable']) {
                            $description = $resultsarray[$condition->overrides]['description'];
                        }
                        if (!$resultsarray[$condition->id]['isavailable']) {
                            if (empty($description)) {
                                $description = $resultsarray[$condition->id]['description'];
                            } else {
                                $description .= '<br>' . $resultsarray[$condition->id]['description'];
                            }
                        }
                        // Only now: If NOT both are true, we set both to false.
                        if (!($resultsarray[$condition->overrides]['isavailable']
                            && $resultsarray[$condition->id]['isavailable'])) {
                                $resultsarray[$condition->overrides]['isavailable'] = false;
                                $resultsarray[$condition->id]['isavailable'] = false;
                                // Both get the same descripiton.
                                // if one of them bubbles up as the blocking one, we see the right description.
                                $resultsarray[$condition->overrides]['description'] = $description;
                                $resultsarray[$condition->id]['description'] = $description;
                        };
                }
            }
        }

        $results = array_filter($resultsarray, function ($item) {
            if ($item['isavailable'] == false) {
                return true;
            }
            return false;
        });

        if (count($results) === 0) {
            $id = 0;
            $isavailable = true;
            $description = '';
        } else {
            $id = 0;
            $isavailable = false;
            foreach ($results as $result) {
                // If no Id has been defined or if id is higher, we take the descpription to return.
                if ($id === 0 || $result['id'] > $id) {
                    $description = $result['description'];
                    $id = $result['id'];
                }
            }
        }

        return [$id, $isavailable, $description];

    }

    /**
     * Obtains a string describing all availability restrictions (even if
     * they do not apply any more). Used to display information for staff
     * editing the website.
     *
     * The modinfo parameter must be specified when it is called from inside
     * get_fast_modinfo, to avoid infinite recursion.
     *
     * This function displays debugging() messages if the availability
     * information is invalid.
     *
     * @param \course_modinfo $modinfo Usually leave as null for default
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_full_information(\course_modinfo $modinfo = null) {
        // Do nothing if there are no availability restrictions.
        if (is_null($this->availability)) {
            return '';
        }
    }

    /**
     * Obtains an array with the id, the availability and the description of the actually blocking condition.
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false):array {

        return $this->is_available($settings->id, $userid, false);
    }

    /**
     * Add form fields to passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public static function add_conditions_to_mform(MoodleQuickForm &$mform, int $optionid) {
        global $DB;
        // Workaround: Only show, if it is not turned off in the option form config.
        // We currently need this, because hideIf does not work with headers.
        // In expert mode, we always show everything.
        $showconditionsheader = true;
        $formmode = get_user_preferences('optionform_mode');
        if ($formmode !== 'expert') {
            $cfgconditionsheader = $DB->get_field('booking_optionformconfig', 'active',
                ['elementname' => 'availabilityconditions']);
            if ($cfgconditionsheader === "0") {
                $showconditionsheader = false;
            }
        }
        if ($showconditionsheader) {
            $mform->addElement('header', 'availabilityconditions',
                get_string('availabilityconditions', 'mod_booking'));
        }

        $conditions = self::get_conditions(CONDPARAM_MFORM_ONLY);

        foreach ($conditions as $condition) {
            // For each condition, add the appropriate form fields.
            $condition->add_condition_to_mform($mform, $optionid);
        }
    }

    /**
     * Save all mform conditions.
     *
     * @param stdClass &$fromform reference to the form data
     * @return void
     */
    public static function save_json_conditions_from_form(stdClass &$fromform) {

        $optionid = $fromform->optionid;

        if (!empty($optionid) && $optionid > 0) {
            $conditions = self::get_conditions(CONDPARAM_JSON_ONLY);
            $arrayforjson = [];

            foreach ($conditions as $condition) {
                if (!empty($condition)) {
                    // For each condition, add the appropriate form fields.
                    $conditionobject = $condition->get_condition_object_for_json($fromform);
                    if (!empty($conditionobject->class)) {
                        $arrayforjson[] = $conditionobject;
                    }
                }
            }
            // This will be saved in the table booking_options in the 'availability' field.
            $fromform->availability = json_encode($arrayforjson);
        }
        // Without an optionid we do nothing.
    }

    /**
     * Returns conditions depending on the conditions param.
     *
     * @param int $condparam conditions parameter
     *  0 ... all conditions (default)
     *  1 ... hardcoded conditions only
     *  2 ... customizable conditions only
     * @return array
     */
    public static function get_conditions(int $condparam = CONDPARAM_ALL): array {

        global $CFG;

        // First, we get all the available conditions from our directory.
        $path = $CFG->dirroot . '/mod/booking/classes/bo_availability/conditions/*.php';
        $filelist = glob($path);

        $conditions = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'mod_booking\bo_availability\conditions\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();

                switch ($condparam) {
                    case CONDPARAM_HARDCODED_ONLY:
                        if ($instance->is_json_compatible() === false) {
                            $conditions[] = $instance;
                        }
                        break;
                    case CONDPARAM_JSON_ONLY:
                        if ($instance->is_json_compatible() === true) {
                            $conditions[] = $instance;
                        }
                        break;
                    case CONDPARAM_MFORM_ONLY:
                        if ($instance->is_shown_in_mform()) {
                            $conditions[] = $instance;
                        }
                        break;
                    case CONDPARAM_ALL:
                    default:
                        $conditions[] = $instance;
                        break;
                }
            }
        }

        return $conditions;
    }

    /**
     * Helper function to render condition descriptions and prices
     * for booking options.
     *
     * @param string $description the description string
     * @param string $style any bootstrap style like 'success', 'danger' or 'warning'
     * @param int $optionid option id
     * @param bool $showprice true if price should be shown
     * @param stdClass $optionvalues object containing option data to render col_price
     * @param bool $shownotificationlist true for symbol to subscribe to notification list
     * @param stdClass $usertobuyfor user to buy for
     */
    public static function render_conditionmessage(string $description, string $style = 'warning',
        int $optionid = 0, bool $showprice = false, stdClass $optionvalues = null,
        bool $shownotificationlist = false, stdClass $usertobuyfor = null) {

        global $PAGE;

        $renderedstring = '';
        $output = $PAGE->get_renderer('mod_booking');
        if (!empty($optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $context = context_module::instance($settings->cmid);
        }

        // Show description.
        if (!empty($description)) {
            $renderedstring = html_writer::div($description, "alert alert-$style text-center pt-0 pb-0");
        }

        // Show price and add to cart button.
        if ($showprice && !empty($optionvalues) && $optionid && !empty($usertobuyfor)) {
            $data = new col_price($optionvalues, $settings, $usertobuyfor, $context);
            $renderedstring .= $output->render_col_price($data);
        }

        // If notification list ist turned on, we show the "notify-me" button.
        if ($shownotificationlist && $optionid && $usertobuyfor->id) {
            $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
            $bookinginformation = $bookinganswer->return_all_booking_information($usertobuyfor->id);
            $data = new button_notifyme($usertobuyfor->id, $optionid,
                $bookinginformation['notbooked']['onnotifylist']);

            $renderedstring .= $output->render_notifyme_button($data);
        }

        return $renderedstring;
    }
}
