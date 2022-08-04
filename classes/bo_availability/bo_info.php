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

use mod_booking\singleton_service;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

define('MAXNUMBEROFBOOKINGS', '[
    "name" : "bo_condition_user_customfielld",
    "overridestimes": false,
    "customfieldshortname" : "Geschlecht",
    "operator" : "=",
    "value" : "f"
    ]');

/**
 * Base class for conditional availability information of a booking option
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class info {

    /** @var bool Visibility flag (eye icon) */
    protected $visible;

    /** @var string Availability data as JSON string */
    protected $availability;

    /**
     * Constructs with item details.
     *
     */
    public function __construct($setting) {

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
     * @return bool True if this item is available to the user, false otherwise
     */
    public function is_available(int $optionid, int $userid = 0):bool {

        global $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // We have hardcoded concitions that are always checked. We add them to the conditions array from the start.
        $conditions = [];

        $conditions[] = json_decode(MAXNUMBEROFBOOKINGS);

        // If there are availabilities defines, we
        if (!empty($settings->availability)) {

            $availabilityobject = json_decode($settings->availability);

            // If the json is not valid, we throw an error.
            if (!$availabilityobject
                || empty($availabilityobject->conditions)) {
                throw new moodle_exception('availabilityjsonerror', 'mod_booking');
            }

            $conditions = array_merge($conditions, $availabilityobject->conditions);
        }

        // Resolve optional parameters.
        if (!$userid) {
            $userid = $USER->id;
        }

        // Run through all the individual conditions to make sure they are fullfilled.
        foreach ($conditions as $condition) {

            // We check each condition for it's availability.
            echo $condition;
        }

        return true;

    }

    /**
     * Checks whether this activity is going to be available for all users.
     *
     * Normally, if there are any conditions, then it may be hidden depending
     * on the user. However in the case of date conditions there are some
     * conditions which will definitely not result in it being hidden for
     * anyone.
     *
     * @return bool True if activity is available for all
     */
    public function is_available_for_all() {
        global $CFG;
        if (is_null($this->availability) || empty($CFG->enableavailability)) {
            return true;
        } else {
            try {
                return $this->get_availability_tree()->is_available_for_all();
            } catch (\coding_exception $e) {
                $this->warn_about_invalid_availability($e);
                return false;
            }
        }
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
     * Stores an updated availability tree JSON structure into the relevant
     * database table.
     *
     * @param string $availabilty New JSON value
     */
    protected abstract function set_in_database($availabilty);

    /**
     * Formats the $cm->availableinfo string for display. This includes
     * filling in the names of any course-modules that might be mentioned.
     * Should be called immediately prior to display, or at least somewhere
     * that we can guarantee does not happen from within building the modinfo
     * object.
     *
     * @param \renderable|string $inforenderable Info string or renderable
     * @param int|\stdClass $courseorid
     * @return string Correctly formatted info string
     */
    public static function format_info($inforenderable, $courseorid) {
        global $PAGE, $OUTPUT;

        // Use renderer if required.
        if (is_string($inforenderable)) {
            $info = $inforenderable;
        } else {
            $renderable = new \core_availability\output\availability_info($inforenderable);
            $info = $OUTPUT->render($renderable);
        }

        // Don't waste time if there are no special tags.
        if (strpos($info, '<AVAILABILITY_') === false) {
            return $info;
        }

        // Handle CMNAME tags.
        $modinfo = get_fast_modinfo($courseorid);
        $context = \context_course::instance($modinfo->courseid);
        $info = preg_replace_callback('~<AVAILABILITY_CMNAME_([0-9]+)/>~',
                function($matches) use($modinfo, $context) {
                    $cm = $modinfo->get_cm($matches[1]);
                    if ($cm->has_view() and $cm->get_user_visible()) {
                        // Help student by providing a link to the module which is preventing availability.
                        return \html_writer::link($cm->get_url(), format_string($cm->get_name(), true, ['context' => $context]));
                    } else {
                        return format_string($cm->get_name(), true, ['context' => $context]);
                    }
                }, $info);
        $info = preg_replace_callback('~<AVAILABILITY_FORMAT_STRING>(.*?)</AVAILABILITY_FORMAT_STRING>~s',
                function($matches) use ($context) {
                    $decoded = htmlspecialchars_decode($matches[1], ENT_NOQUOTES);
                    return format_string($decoded, true, ['context' => $context]);
                }, $info);
        $info = preg_replace_callback('~<AVAILABILITY_CALLBACK type="([a-z0-9_]+)">(.*?)</AVAILABILITY_CALLBACK>~s',
                function($matches) use ($modinfo, $context) {
                    // Find the class, it must have already been loaded by now.
                    $fullclassname = 'availability_' . $matches[1] . '\condition';
                    if (!class_exists($fullclassname, false)) {
                        return '<!-- Error finding class ' . $fullclassname .' -->';
                    }
                    // Load the parameters.
                    $params = [];
                    $encodedparams = preg_split('~<P/>~', $matches[2], 0);
                    foreach ($encodedparams as $encodedparam) {
                        $params[] = htmlspecialchars_decode($encodedparam, ENT_NOQUOTES);
                    }
                    return $fullclassname::get_description_callback_value($modinfo, $context, $params);
                }, $info);

        return $info;
    }

}
