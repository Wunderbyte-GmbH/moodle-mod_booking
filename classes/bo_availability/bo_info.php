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
use context_system;
use local_shopping_cart\shopping_cart;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\booking_context_helper;
use mod_booking\booking_option_settings;
use mod_booking\local\modechecker;
use mod_booking\output\bookingoption_description;
use mod_booking\output\button_notifyme;
use mod_booking\output\col_price;
use mod_booking\output\prepagemodal;
use mod_booking\output\simple_modal;
use mod_booking\price;
use mod_booking\singleton_service;
use moodle_exception;
use moodle_url;
use MoodleQuickForm;
use stdClass;

// The blocking condition can return a value to define which button to use.
define('MOD_BOOKING_BO_BUTTON_INDIFFERENT', 0);
define('MOD_BOOKING_BO_BUTTON_MYBUTTON', 1); // Used for price or book it.
define('MOD_BOOKING_BO_BUTTON_NOBUTTON', 2); // Forces no button (Eg special subbookings).
define('MOD_BOOKING_BO_BUTTON_MYALERT', 3); // Alert is a weaker form of MYBUTTON. With special rights, Button is still shown.
define('MOD_BOOKING_BO_BUTTON_JUSTMYALERT', 4); // A strong Alert which also prevents buttons to be displayed.
define('MOD_BOOKING_BO_BUTTON_CANCEL', 5); // The Cancel button is shown next to MYALERT.

// Define if there are sites and if so, if they are prepend, postpend or booking relevant.
define('MOD_BOOKING_BO_PREPAGE_NONE', 0); // This condition provides no page.
define('MOD_BOOKING_BO_PREPAGE_BOOK', 1); // This condition does only provide a booking page (button or price).
    // Only used when there are other pages as well.
define('MOD_BOOKING_BO_PREPAGE_PREBOOK', 2); // This should be before the bookit button.
define('MOD_BOOKING_BO_PREPAGE_POSTBOOK', 3); // This should be after the bookit button.

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
     * @param booking_option_settings $settings
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
     * @param ?int $optionid
     * @param int $userid If set, specifies a different user ID to check availability for
     * @param bool $hardblock
     * @param bool $noblockingpages
     * @return array [isavailable, description]
     */
    public function is_available(?int $optionid = null, int $userid = 0, bool $hardblock = false,
        bool $noblockingpages = false): array {

        if (!$optionid) {
            $optionid = $this->optionid;
        }

        $results = $this->get_condition_results($optionid, $userid, $hardblock);

        if (count($results) === 0) {
            $id = MOD_BOOKING_BO_COND_CONFIRMATION; // This is the lowest id.
            $isavailable = true;
            $description = '';
        } else {
            $id = MOD_BOOKING_BO_COND_CONFIRMATION;
            $isavailable = false;
            foreach ($results as $result) {
                // If no Id has been defined or if id is higher, we take the descpription to return.
                if ($id === MOD_BOOKING_BO_COND_CONFIRMATION || $result['id'] > $id) {
                    if (class_exists('local_shopping_cart\shopping_cart')
                        && has_capability('local/shopping_cart:cashier', context_system::instance()) &&
                        $result['button'] == MOD_BOOKING_BO_BUTTON_MYALERT) {
                        continue;
                    }
                    // Pages should not block the "allow_add_item_to_cart" function if $noblockingpages is true.
                    if ($noblockingpages &&
                        ($result['insertpage'] == MOD_BOOKING_BO_PREPAGE_PREBOOK ||
                        $result['insertpage'] == MOD_BOOKING_BO_PREPAGE_POSTBOOK)) {
                        continue;
                    }
                    $description = $result['description'];
                    $id = $result['id'];
                }
            }
        }

        return [$id, $isavailable, $description];

    }

    /**
     * Central function to check all available conditions.
     *
     * @param int|null $optionid
     * @param int $userid
     * @param bool $onlyhardblock
     * @return array
     */
    public static function get_condition_results(?int $optionid = null, int $userid = 0, bool $onlyhardblock = false): array {
        global $USER, $CFG;

        require_once($CFG->dirroot . '/mod/booking/lib.php');

        // We only get full description when we book for another user.
        // It's a clear sign of higher rights.
        $full = $USER->id == $userid ? false : true;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $conditions = self::get_conditions(MOD_BOOKING_CONDPARAM_HARDCODED_ONLY);

        if (!empty($settings->availability)) {
            $availabilityarray = json_decode($settings->availability);

            // If the json is not valid, we throw an error.
            if (!is_array($availabilityarray) && (!$availabilityarray || empty($availabilityarray))) {
                throw new moodle_exception(
                    "availabilityjsonerror for optionid $optionid with availabilityjson $settings->availability",
                    'mod_booking'
                );
            }

            // There are conditions, which need to be executed no matter if there is a json or not.
            // Eg. allowedbookinstance.
            // Threfore, if the there are two conditions with the same id in availability & conditions array, we take availability.

            $conditions = array_merge($conditions, $availabilityarray);
        }

        // Resolve optional parameters.
        if (empty($userid)) {
            $userid = $USER->id;
        }

        $resultsarray = [];

        $overrideconditions = [];

        /* Run through all the individual conditions to make sure they are fullfilled.
        Hardcoded conditions are in instantiated classes whereas JSON conditions are in stdclasses.
        They come from the field 'availability' field of the booking options table. */
        while (count($conditions) > 0) {

            $condition = array_shift($conditions);

            $classname = get_class($condition);

            // First, we have the hardcoded conditions already as instances.
            if ($classname !== 'stdClass') {
                list($isavailable, $description, $insertpage, $button)
                    = $condition->get_description($settings, $userid, $full);

                if (!$isavailable && $onlyhardblock) {
                    $isavailable = !$condition->hard_block($settings, $userid);
                }
                $resultsarray[$condition->id] = [
                    'id' => $condition->id,
                    'isavailable' => $isavailable,
                    'description' => $description,
                    'classname' => $classname,
                    'button' => $button, // This indicates if this condition provides a button.
                    'insertpage' => $insertpage, // Bool, only in combination with is available false.
                    'condition' => $condition,
                    'reciprocal' => $condition->is_shown_in_mform(),
                ];
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
                /* The get description function returns availability, description,
                insertpage (int param for prepagemodal provided) and the button. */
                list($isavailable, $description, $insertpage, $button) = $instance->get_description($settings, $userid, $full);

                if (!$isavailable && $onlyhardblock) {
                    // If we only want hard blocks, we might want to override the result of the is_available function.
                    // False will only stay false, if hardblock returns true.
                    $isavailable = !$instance->hard_block($settings, $userid);
                }

                $resultsarray[$condition->id] = ['id' => $condition->id,
                    'isavailable' => $isavailable,
                    'description' => $description,
                    'classname' => $classname,
                    'button' => $button, // This indicates if this condition provides a button.
                    'insertpage' => $insertpage, // Bool, only in combination with is available false.
                    'condition' => $condition,
                    'reciprocal' => $instance->is_shown_in_mform(),
                ];
            }

            // We collect all conditions that have override conditions set.
            if (!empty($condition->overrides)) {
                $overrideconditions[] = $condition;
            }
        }

        // Now we might need to override the result of a previous condition which has been resolved as false before.
        foreach ($overrideconditions as $condition) {

            // As we manipulate this value, we have to keep the original value.
            $resultsarray[$condition->id]['isavailable:original'] = $resultsarray[$condition->id]['isavailable'];

            // Foreach override condition id (ocid).
            foreach ($condition->overrides as $ocid) {
                if (isset($resultsarray[$ocid])) {
                    // We know we have a result to override. It depends now on the operator what to do.
                    // If the operator is or, we change the previous result from false to true, if this result is true.
                    // OR we change this result to true, if the previous was true.
                    switch ($condition->overrideoperator) {
                        case 'OR':
                            // If one of the two results is true, both are true.
                            if (isset($resultsarray[$ocid])) {
                                $overrideswithkeys = array_flip($resultsarray[$ocid]['condition']->overrides ?? []);
                                if (!$resultsarray[$ocid]['reciprocal'] ||
                                    isset($overrideswithkeys[$condition->id])) {
                                    if ($resultsarray[$ocid]['isavailable']) {
                                        $resultsarray[$condition->id]['isavailable'] = true;
                                    }
                                }
                                // If the original condition availability is true...
                                // ...then we also can set the override condition to true.
                                if ($resultsarray[$condition->id]['isavailable:original']) {
                                    $resultsarray[$ocid]['isavailable'] = true;
                                }
                            }
                            break;
                        case 'AND':
                            // We need to return the right description which actually failed.
                            // If both fail, we want to return both descriptions.

                            $description = '';
                            if (!$resultsarray[$ocid]['isavailable']) {
                                $description = $resultsarray[$ocid]['description'];
                            }
                            if (!$resultsarray[$condition->id]['isavailable']) {
                                if (empty($description)) {
                                    $description = $resultsarray[$condition->id]['description'];
                                } else {
                                    $description .= '<br>' . $resultsarray[$condition->id]['description'];
                                }
                            }
                            // Only now: If NOT both are true, we set both to false.
                            if (!($resultsarray[$ocid]['isavailable']
                                && $resultsarray[$condition->id]['isavailable'])) {
                                    $resultsarray[$condition->id]['isavailable'] = false;
                                    // Both get the same descripiton.
                                    // if one of them bubbles up as the blocking one, we see the right description.
                                    $resultsarray[$ocid]['description'] = $description;
                                    $resultsarray[$condition->id]['description'] = $description;
                            };
                            break;
                    }
                } else {
                    array_push($conditions, $condition);
                }
            }
        }

        $results = array_filter($resultsarray, function ($item) {
            if ($item['isavailable'] == false) {
                return true;
            }
            return false;
        });

        ksort($results);
        return $results;
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
     * @param ?\course_modinfo $modinfo Usually leave as null for default
     * @return string Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_full_information(?\course_modinfo $modinfo = null) {
        // Do nothing if there are no availability restrictions.
        if (is_null($this->availability)) {
            return '';
        }
    }

    /**
     * Obtains an array with the id, the availability and the description of the actually blocking condition.
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $full Set true if this is the 'full information' view
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false): array {

        return $this->is_available($settings->id, $userid, false);
    }

    /**
     * Add form fields to passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @param ?\moodleform $moodleform
     * @return void
     */
    public static function add_conditions_to_mform(MoodleQuickForm &$mform, int $optionid, ?\moodleform $moodleform = null) {
        global $DB;

        $mform->addElement('header', 'availabilityconditions', get_string('availabilityconditionsheader', 'mod_booking'));

        $conditions = self::get_conditions(MOD_BOOKING_CONDPARAM_MFORM_ONLY);

        foreach ($conditions as $condition) {
            // For each condition, add the appropriate form fields.
            $condition->add_condition_to_mform($mform, $optionid, $moodleform);
        }
    }

    /**
     * Sets all keys to load form.
     *
     * @param stdClass $defaultvalues
     * @param stdClass $jsonobject
     * @return void
     */
    public static function set_defaults(stdClass &$defaultvalues, $jsonobject) {

        foreach ($jsonobject as $conditionobject) {

            $classname = $conditionobject->class;
            $condition = new $classname($conditionobject->id);
            $condition->set_defaults($defaultvalues, $conditionobject);
        }
    }

    /**
     * Save all mform conditions.
     *
     * @param stdClass $fromform reference to the form data
     * @return void
     */
    public static function save_json_conditions_from_form(stdClass &$fromform) {

        $optionid = $fromform->id ?? 0;
        $arrayforjson = [];

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $existingconditions = [];
        if (!empty($settings->availability)) {
            $existingconditions = json_decode($settings->availability);
        }

        $conditions = self::get_conditions(MOD_BOOKING_CONDPARAM_JSON_ONLY);

        $sqlfilter = 0;
        foreach ($conditions as $condition) {
            if (!empty($condition)) {
                $fullclassname = get_class($condition); // With namespace.
                $classnameparts = explode('\\', $fullclassname);
                $shortclassname = end($classnameparts); // Without namespace.
                $key = "bo_cond_{$shortclassname}_restrict";

                if (isset($fromform->{$key})) {
                    // For each condition, add the appropriate form fields.
                    $conditionobject = $condition->get_condition_object_for_json($fromform);
                    if (!empty($conditionobject->sqlfilter)) {
                        $sqlfilter = MOD_BOOKING_SQL_FILTER_ACTIVE_JSON_BO;
                    }
                    if (!empty($conditionobject->class)) {
                        $arrayforjson[] = $conditionobject;
                    }
                    continue;
                }

                if (!empty($existingconditions)) {
                    foreach ($existingconditions as $existingcondition) {
                        if ($existingcondition->id == $condition->id) {
                            $arrayforjson[] = $existingcondition;
                        }
                    }
                }
            }
        }
        // This will be saved in the table booking_options in the 'availability' field.
        $fromform->availability = json_encode($arrayforjson);
        $fromform->sqlfilter = $sqlfilter;
        // Without an optionid we do nothing.
    }

    /**
     * Add the sql from the conditions.
     *
     * @return array
     */
    public static function return_sql_from_conditions() {
        global $PAGE;
        // First, we get all the relevant conditions.
        $conditions = self::get_conditions(MOD_BOOKING_CONDPARAM_MFORM_ONLY);
        $selectall = '';
        $fromall = '';
        $filterall = '';
        $paramsarray = [];

        $cm = $PAGE->cm;
        if ($cm && ((has_capability('mod/booking:updatebooking', $cm->context)))) {
            // With this capability, ignore filter for sql check.
            // Because of missing $cm this will not work for display outside a course i.e. in shortcodes display.
            // A teacher would not see hidden bookingconditions on startpage but in courselist they would be displayed.
            return ['', '', '', [], ''];
        }
        foreach ($conditions as $class) {

            $condition = new $class();

            list($select, $from, $filter, $params, $where) = $condition->return_sql();

            $selectall .= $select;
            $fromall .= $from;
            $filterall .= $filter;
            if (!empty($where)) {
                $wherearray[] = $where;
            }
            $paramsarray = array_merge($paramsarray, $params);

        }

        $where = implode(" AND ", $wherearray);

        // For performance reason we have a flag if we need to check the value at all.
        $where = " (
                        sqlfilter < 1 OR (
                            $where
                            )
                        )
                        ";

        return ['', '', '', $paramsarray, $where];
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
    public static function get_conditions(int $condparam = MOD_BOOKING_CONDPARAM_ALL): array {

        global $CFG;

        // First, we get all the available conditions from our directory.
        $path = $CFG->dirroot . '/mod/booking/classes/bo_availability/conditions/*.php';
        $filelist = glob($path);

        $conditions = [];

        // We just want filenames, as they are also the classnames.
        foreach ($filelist as $filepath) {
            $path = pathinfo($filepath);
            $filename = 'mod_booking\\bo_availability\\conditions\\' . $path['filename'];

            // We instantiate all the classes, because we need some information.
            if (class_exists($filename)) {
                $instance = new $filename();

                switch ($condparam) {
                    case MOD_BOOKING_CONDPARAM_HARDCODED_ONLY:
                        if ($instance->is_json_compatible() === false) {
                            $conditions[] = $instance;
                        }
                        break;
                    case MOD_BOOKING_CONDPARAM_JSON_ONLY:
                        if ($instance->is_json_compatible() === true) {
                            $conditions[] = $instance;
                        }
                        break;
                    case MOD_BOOKING_CONDPARAM_MFORM_ONLY:
                        if ($instance->is_shown_in_mform()) {
                            $conditions[] = $instance;
                        }
                        break;
                    case MOD_BOOKING_CONDPARAM_CANBEOVERRIDDEN:
                        if (isset($instance->overridable) && $instance->overridable === true) {
                            $conditions[] = $instance;
                        }
                        break;
                    case MOD_BOOKING_CONDPARAM_ALL:
                    default:
                        $conditions[] = $instance;
                        break;
                }
            }
        }

        return $conditions;
    }

    /**
     * Function to render instance of bo_condition.
     *
     * @param string $conditionname
     * @return null|object
     */
    private static function get_condition($conditionname) {
        $filename = 'mod_booking\\bo_availability\\conditions\\' . $conditionname . '.php';

        if (class_exists($filename)) {
            return new $filename();
        }

        return null;
    }

    /**
     * This function renders the prebooking page for the right condition.
     *
     * @param int $optionid
     * @param int $pagenumber
     * @param int $userid
     * @return array
     */
    public static function load_pre_booking_page(int $optionid, int $pagenumber, int $userid) {

        $results = self::get_condition_results($optionid, $userid);

        // Results have to be sorted the right way. At the moment, it depends on the id of the blocking condition.
        usort($results, function ($a, $b) {
            return $a['id'] < $b['id'] ? 1 : -1;
        });

        // Sorted List of blocking conditions which also provide a proper page.
        $conditions = self::return_sorted_conditions($results);
        $condition = self::return_class_of_current_page($conditions, $pagenumber);

        // If the current condition doesn't have the "pre" key...
        // ... then we need to verify if we are already booked.
        // If not, we need to do it now.

        if (
            !isset($conditions[$pagenumber]['pre'])
            && !($conditions[$pagenumber]['id'] === MOD_BOOKING_BO_COND_BOOKITBUTTON)
        ) {
            // Every time we load a page which is not "pre", we need to check if we are booked.
            // First, determine if this is a booking option with a price.

            // Book this option.
            if (
                !self::has_price_set($results)
                || self::booked_on_waitinglist($results)
            ) {
                // Check if we are already booked.
                $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
                $boinfo = new bo_info($settings);

                // Check option availability if user is not logged yet.
                [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $userid, false);

                if (
                    !(
                        $id === MOD_BOOKING_BO_COND_ALREADYBOOKED
                        || $id === MOD_BOOKING_BO_COND_ONWAITINGLIST
                    )
                ) {
                    $response = booking_bookit::bookit('option', $optionid, $userid);
                    if ($response['status'] != 1) {
                        // We need to book twice, as confirmation might be in place.
                        $response = booking_bookit::bookit('option', $optionid, $userid);
                    }
                }
            } else {
                if (class_exists('local_shopping_cart\shopping_cart')) {
                    shopping_cart::add_item_to_cart('mod_booking', 'option', $optionid, $userid);
                } else {
                    throw new moodle_exception('tousepriceinstallshoppingcart', 'mod_booking');
                }
            }
        }

        // We throw an exception if we didn't get a valid pagenumber.
        if (empty($condition)) {
            throw new moodle_exception('wrongpagenumberforprebookingpage', 'mod_booking');
        }

        $data = self::return_data_for_steps($conditions, $pagenumber);

        $template = 'mod_booking/bookingpage/header';

        // We get the condition for the right page.
        $condition = new $condition();
        $object = $condition->render_page($optionid, $userid ?? 0);

        // Now we introduce the header at the first place.
        $object['template'] = $template . ',' . $object['template'];
        $dataarray = array_merge([$data], $object['data']);

        $template = 'mod_booking/bookingpage/footer';

        $footerdata = [
            'data' => [
                'optionid' => $optionid,
                'userid' => $userid,
                'shoppingcartisinstalled' => class_exists('local_shopping_cart\shopping_cart') ? true : false,
            ],
        ];

        // Depending on the circumstances, keys are added to the array.
        self::add_continue_button($footerdata, $conditions, $results, $pagenumber, count($conditions), $optionid, $userid);
        self::add_back_button($footerdata, $conditions, $results, $pagenumber, count($conditions));

        $object['template'] = $object['template'] . ',' .  $template;
        $dataarray = array_merge($dataarray, [$footerdata]);

        $object['json'] = json_encode($dataarray);

        // The condition renders the page we actually need.
        return $object;
    }

    /**
     * Helper function to render condition descriptions and prices
     * for booking options.
     *
     * @param string $description the description string
     * @param string $style any bootstrap style like 'success', 'danger' or 'warning'
     * @param int $optionid option id
     * @param bool $showprice true if price should be shown
     * @param ?stdClass $optionvalues object containing option data to render col_price
     * @param bool $shownotificationlist true for symbol to subscribe to notification list
     * @param ?stdClass $usertobuyfor user to buy for
     * @param bool $modalfordescription
     */
    public static function render_conditionmessage(
            string $description,
            string $style = 'warning',
            int $optionid = 0,
            bool $showprice = false,
            ?stdClass $optionvalues = null,
            bool $shownotificationlist = false,
            ?stdClass $usertobuyfor = null,
            bool $modalfordescription = false) {

        global $PAGE;

        $renderedstring = '';
        $output = $PAGE->get_renderer('mod_booking');
        if (!empty($optionid)) {
            $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
            $context = context_module::instance($settings->cmid);
        }

        // Show description.
        // If necessary in a modal.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* if (!empty($description)) {
            if ($modalfordescription) {
                $data = new prepagemodal($optionid, 'test', $description);
                $renderedstring = $output->render_prepagemodal($data);
            } else {
                $renderedstring = html_writer::div($description, "alert alert-$style text-center");
            }
        } */

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

    /**
     * This is the standard function to render the bookit button. Most bo conditions will use it, because a lot is similiar.
     * They can still alter the returned array.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param string $label
     * @param string $classes
     * @param bool $includeprice
     * @param bool $fullwidth
     * @param string $role
     * @param string $area
     * @param bool $nojs
     * @param string $dataaction
     * @param string $link
     * @param string $showicon
     * @return array
     */
    public static function render_button(
        booking_option_settings $settings,
        int $userid,
        string $label,
        string $classes = 'alert alert-danger',
        bool $includeprice = false,
        bool $fullwidth = true,
        string $role = 'alert',
        string $area = 'option',
        bool $nojs = true,
        string $dataaction = '', // Use 'noforward' to disable automatic forwarding.
        string $link = '',
        string $showicon = ''
    ) {

        global $PAGE;

        $user = singleton_service::get_instance_of_user($userid);

        if (empty($user)) {
            $user = null;
        }

        // Initialize extra classes.
        $extraclasses = '';

        // Needed for normal bookit button.
        if ($fullwidth) {
            // For view.php and default rendering.
            $extraclasses = 'w-100';
        }

        $data = [
            'itemid' => $settings->id,
            'area' => $area,
            'userid' => $userid ?? 0,
            'dataaction' => $dataaction,
            'nojs' => $nojs,
            'main' => [
                'label' => $label,
                'class' => "$classes $extraclasses text-center",
                'role' => $role,
            ],
        ];

        if (!empty($showicon)) {
            $data['main']['showicon'] = $showicon;
        }

        if (!empty($link)) {
            $data['main']['link'] = $link;
            $data['main']['role'] = 'button';
        }

        // Only if the user can not book anyways, we want to show him the price he or she should see.
        $context = context_module::instance($settings->cmid);
        if (
            (!has_capability('mod/booking:bookforothers', $context)
            || get_config('booking', 'bookonlyondetailspage'))
            && $settings->useprice) {
            $priceitems = price::get_price('option', $settings->id, $user);
            if (count($priceitems) > 0) {
                if (
                    get_config('booking', 'priceisalwayson')
                    || !empty(get_config('booking', 'displayemptyprice'))
                    || !empty((float)$priceitems["price"])
                ) {
                    $data['sub'] = [
                        'label' => $priceitems["price"] . " " . $priceitems["currency"],
                        'class' => ' text-center ',
                        'role' => '',
                    ];
                }
            }
        }

        // Needed for bookit_price button.
        if ($fullwidth) {
            // For view.php and default rendering.
            $data['fullwidth'] = true;
        }

        if ($includeprice && $settings->useprice) {
            if ($price = price::get_price('option', $settings->id, $user)) {
                $data['price'] = [
                    'price' => $price['price'],
                    'currency' => $price['currency'],
                ];
            }
        }

        // If user is on notification list, we need to show unsubscribe toggle bell.
        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
        $bookinginformation = $bookinganswer->return_all_booking_information($userid);
        if (isset($bookinginformation['notbooked']) && ($bookinginformation['notbooked']['onnotifylist']) ||
            (isset($bookinginformation['iambooked']) && $bookinginformation['iambooked']['onnotifylist'])) {
            $data['onlist'] = true;
        }

        // The reason for this structure is that we can have a number of comma separated templates.
        // And corresponding data objects in an array. This will be interpreted in JS.
        $returnarray = [
            'mod_booking/bookit_button', // The template.
            $data, // The corresponding data object.
        ];

        return $returnarray;
    }

    /**
     * If billboard is activated, we want to overwrite the warning messages with the billboard text.
     *
     *
     * @param bo_condition $condition
     * @param booking_option_settings $settings
     *
     * @return string
     *
     */
    public static function apply_billboard(bo_condition $condition, booking_option_settings $settings): string {
        if (empty(get_config('booking', 'conditionsoverwritingbillboard'))) {
            return '';
        }

        // Fetch settings of instance to see if alert needs to be overwritten.
        $instance = singleton_service::get_instance_of_booking_by_bookingid($settings->bookingid);
        if (empty($instance->settings->json)) {
            return '';
        }
        $jsondata = json_decode($instance->settings->json);
        if (empty($jsondata->billboardtext) || empty($jsondata->overwriteblockingwarnings)) {
            return '';
        }
        global $PAGE;
        booking_context_helper::fix_booking_page_context($PAGE, $settings->cmid);
        return format_text($jsondata->billboardtext);
    }

    /**
     * To sort the prepages, depending on blocking conditions array.
     * This is also used to determine the total number of pages displayed.
     * Just count the pages returned.
     * If there are just booking & confirmation pages, we supress them.
     *
     * @param array $results
     * @return array
     */
    public static function return_sorted_conditions(array $results) {

        // Make sure the keys are set.
        $prepages = [];
        $prepages['pre'] = [];
        $prepages['post'] = [];
        $prepages['book'] = null;

        $showbutton = true;
        $confirmation = null;
        $showcheckout = false;
        $askforconfirmation = false;

        // First, sort all the pages according to this system:
        // Depending on the MOD_BOOKING_BO_PREPAGE_x constant, we order them pre or post the real booking button.
        foreach ($results as $result) {

            if ($result['id'] === MOD_BOOKING_BO_COND_ASKFORCONFIRMATION) {
                $askforconfirmation = true;
            }

            if ($result['id'] === MOD_BOOKING_BO_COND_PRICEISSET &&
                class_exists('local_shopping_cart\shopping_cart')) {
                if (!$askforconfirmation) {
                    $showcheckout = true;
                }
            }

            // One no button condition tetermines this for all.
            if ($result['button'] === MOD_BOOKING_BO_BUTTON_NOBUTTON) {
                $showbutton = false;
            }

            $newclass = [
                'id' => $result['id'],
                'classname' => $result['classname'],
            ];

            if ($result['id'] === MOD_BOOKING_BO_COND_CONFIRMATION) {
                $confirmation = $newclass;
                /* We use 'showcheckout' to differentiate between "Booking complete"
                and "Proceed to checkout" confirmation. */
                $confirmation['showcheckout'] = $showcheckout;
                continue;
            }

            switch ($result['insertpage']) {
                case MOD_BOOKING_BO_PREPAGE_BOOK:
                    $prepages['book'] = $newclass;
                    break;
                case MOD_BOOKING_BO_PREPAGE_PREBOOK:
                    $newclass['pre'] = true;
                    $prepages['pre'][] = $newclass;
                    break;
                case MOD_BOOKING_BO_PREPAGE_POSTBOOK:
                    $prepages['post'][] = $newclass;
                    break;
            }
        }

        if ($confirmation) {
            $prepages['post'][] = $confirmation;
        }

        // We assemble the array in the right order.

        $conditionsarray = $prepages['pre'];
        // We might not have a book condition.
        // Here, we added the prepages['book'] condition...
        // .., to have another confirmation of the booking in the prepage.

        $conditionsarray = array_merge($conditionsarray, $prepages['post']);

        // When there are no pre or post pages, we don't want show the booking page.

        // We can in the future include a setting which will allow for always showing booking modal.
        // But right now, we will always suppress the Booking modal, when there is only one page.
        // This single page has to be necessarily the confirmation page.
        if ((count($prepages['pre']) + count($prepages['post'])) < 2) {
            return [];
        } else if (empty($prepages['pre'])) {
            array_unshift($conditionsarray, $prepages['book']);
        }
        return $conditionsarray;
    }

    /**
     * This returns the data of the
     *
     * @param array $conditionsarray
     * @param int $pagenumber
     * @return array
     */
    private static function return_data_for_steps(array $conditionsarray, int $pagenumber): array {

        $data['tabs'] = [];

        foreach ($conditionsarray as $key => $value) {

            if (isset($value['showcheckout']) && $value['showcheckout'] == true) {
                $name = 'checkout'; // So we'll get the string 'page:checkout'.
            } else {
                // In all other cases, we want to get the string of 'page:conditionname', e.g. 'page:confirmation'.
                $array = explode('\\', $value['classname']);
                $name = array_pop($array);
            }
            $data['tabs'][] = [
                'name' => get_string('page:' . $name, 'mod_booking'),
                'active' => $key <= $pagenumber ? true : false,
            ];
        };

        return [
            'data' => $data,
        ];
    }

    /**
     * Creates a correctly sorted array for all the pages...
     * ... and returns the classname as string of current page.
     *
     * @param array $conditionsarray
     * @param int $pagenumber
     * @return string
     */
    private static function return_class_of_current_page(array $conditionsarray, int $pagenumber) {

        // Now that we have the right order, we need to return the corresponding classname.
        return $conditionsarray[$pagenumber]['classname'];
    }

    /**
     * Go through conditions classes to see if somewhere a price is set.
     *
     * @param array $results
     * @return bool
     */
    private static function has_price_set(array $results): bool {
        foreach ($results as $result) {
            if ($result['classname'] == 'mod_booking\bo_availability\conditions\priceisset') {
                return true;
            }
        }
        return false;
    }

    /**
     * Go through conditions classes to see if somewhere we are on waitinglist.
     *
     * @param array $results
     * @return bool
     */
    private static function booked_on_waitinglist(array $results): bool {
        foreach ($results as $result) {
            if ($result['classname'] == 'mod_booking\bo_availability\conditions\askforconfirmation') {
                return true;
            }
        }
        return false;
    }

    /**
     * Logic of the continue button in the prepage modal.
     *
     * @param array $footerdata
     * @param array $conditions
     * @param array $results
     * @param int $pagenumber
     * @param int $totalpages
     * @param int $optionid
     * @param int $userid
     * @return void
     */
    private static function add_continue_button(
            array &$footerdata,
            array $conditions,
            array $results,
            int $pagenumber,
            int $totalpages,
            int $optionid,
            int $userid) {

        global $USER;

        // Standardvalues.

        $continuebutton = true;
        $continueaction = 'continuepost';
        $continuelabel = get_string('continue');
        $continuelink = '#';

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $viewparam = booking::get_value_of_json_by_key($settings->bookingid, 'viewparam');
        $turnoffmodals = 0; // By default, we use modals.
        if (
            $viewparam == MOD_BOOKING_VIEW_PARAM_LIST
            || $viewparam = MOD_BOOKING_VIEW_PARAM_LIST_IMG_LEFT
            || $viewparam = MOD_BOOKING_VIEW_PARAM_LIST_IMG_RIGHT
        ) {
            // Only if we use list view, we can use inline modals.
            // So only in this case, we need to check the config setting.
            $turnoffmodals = get_config('booking', 'turnoffmodals');
        }

        if ($conditions[$pagenumber]['id'] === MOD_BOOKING_BO_COND_CONFIRMATION) {
            // We need to decide if we want to show on the last page a "go to checkout" button.
            if (self::has_price_set($results)) {
                $results = self::get_condition_results($optionid, $userid);
                $lastresultid = array_pop($results)['id'];
                switch ($lastresultid) {
                    case MOD_BOOKING_BO_COND_ALREADYRESERVED:

                        // If we are not on the cashier site, do this.
                        if ($userid == $USER->id) {
                            $url = new moodle_url('/local/shopping_cart/checkout.php');
                            $continueaction = 'checkout';
                            $continuelabel = get_string('checkout', 'local_shopping_cart');
                            $continuelink = $url->out();
                            $continuebutton = true;
                        } else {
                            $continueaction = empty($turnoffmodals) ? 'closemodal' : 'closeinline';
                            $continuelabel = get_string('close', 'mod_booking');
                            $continuelink = "#checkout";
                            $continuebutton = true;
                        }

                        break;
                    default:
                        $continuebutton = true;
                        $continueaction = empty($turnoffmodals) ? 'closemodal' : 'closeinline';
                        $continuelabel = get_string('close', 'mod_booking');
                        break;
                }
            } else {
                $continuebutton = true;
                $continueaction = empty($turnoffmodals) ? 'closemodal' : 'closeinline';
                $continuelabel = get_string('close', 'mod_booking');
            }
        }

        $footerdata['data']['continuebutton'] = $continuebutton; // Show button at all.
        $footerdata['data']['continueaction'] = $continueaction; // Which action should be taken?
        $footerdata['data']['continuelabel'] = $continuelabel; // The visible label.
        $footerdata['data']['continuelink'] = $continuelink; // A hard link.
    }

    /**
     * Logic of the back button in the prepage modal.
     *
     * @param array $footerdata
     * @param array $conditions
     * @param array $results
     * @param int $pagenumber
     * @param int $totalpages
     * @return void
     */
    private static function add_back_button(
            array &$footerdata,
            array $conditions,
            array $results,
            int $pagenumber,
            int $totalpages) {

        // Standardvalues.
        $backbutton = true;
        $backaction = 'back';
        $backlabel = get_string('back');

        if ($pagenumber == 0 // If we are on the first page.
            || $conditions[$pagenumber]['id'] === MOD_BOOKING_BO_COND_CONFIRMATION) { // If we are on the confirmation page.
            $backbutton = false;
        }

        $footerdata['data']['backbutton'] = $backbutton; // Show button at all.
        $footerdata['data']['backaction'] = $backaction; // Which action should be taken?
        $footerdata['data']['backlabel'] = $backlabel; // The visible label.
    }

    /**
     * Returns part of SQL-Query according to DB Family for a specified column and key.
     *
     * @param string $dbcolumn
     * @param string $jsonkey
     *
     * @return string
     *
     */
    public static function check_for_sqljson_key(string $dbcolumn, string $jsonkey): string {
        global $DB;

        $databasetype = $DB->get_dbfamily();
        // The $key param is the name of the param in json.
        switch ($databasetype) {
            case 'postgres':
                return " ($dbcolumn->>'$jsonkey')";
            case 'mysql':
                return " JSON_EXTRACT($dbcolumn, '$jsonkey')";
            default:
                return '';
        }
    }

    /**
     * This function adds error keys for form validation.
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors) {
        $optionid = $data['id'] ?? 0;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $existingconditions = [];
        if (!empty($settings->availability)) {
            $existingconditions = json_decode($settings->availability);
            foreach ($existingconditions as $existingcondition) {
                $class = new $existingcondition->class();
                if (method_exists($class, 'validation')) {
                    $class->validation($data, $files, $errors);
                };
            }

        }

        return $errors;
    }

}
