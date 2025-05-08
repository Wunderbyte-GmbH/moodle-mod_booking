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
 * Condition to allow users to cancel themselves.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability\conditions;

use local_shopping_cart\shopping_cart;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\price;
use mod_booking\singleton_service;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Base class for a single bo availability condition.
 *
 * All bo condition types must extend this class.
 *
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cancelmyself implements bo_condition {
    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_CANCELMYSELF;

    /** @var bool $overwrittenbybillboard Indicates if the condition can be overwritten by the billboard. */
    public $overwrittenbybillboard = false;

    /**
     * Get the condition id.
     *
     * @return int
     *
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Needed to see if class can take JSON.
     * @return bool
     */
    public function is_json_compatible(): bool {
        return false; // Hardcoded condition.
    }

    /**
     * Needed to see if it shows up in mform.
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return false;
    }

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {

        $optionid = $settings->id;
        $now = time();

        // This is the return value. Not available to begin with.
        $isavailable = false;

        // If cancelling was disabled in the booking option or for the whole instance...
        // ...then we do not show the cancel button.
        if (
            booking_option::get_value_of_json_by_key($optionid, 'disablecancel')
            || booking::get_value_of_json_by_key($settings->bookingid, 'disablecancel')
        ) {
            return true;
        }

        // Check if the option has its own canceluntil date and if it has already passed.
        $now = time();
        $canceluntil = booking_option::get_value_of_json_by_key($optionid, 'canceluntil');
        if (!empty($canceluntil) && $now > $canceluntil) {
            return true;
        }

        // Get the booking answers for this instance.
        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);

        $bookinginformation = $bookinganswer->return_all_booking_information($userid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid);

        if (!empty($settings->jsonobject->useprice) && (!class_exists('local_shopping_cart\shopping_cart'))) {
            // If we have a price, this condition is not used.
            $isavailable = true; // True means, it won't be shown.
        } else {
            // If the user is not allowed to cancel we never show cancel button.
            if (!empty($bookingsettings->iselective) && isset($bookinginformation['iamreserved'])) {
                $isavailable = false;
            } else if (isset($bookinginformation['iamreserved'])) {
                $isavailable = true;
            } else if ($bookingsettings->cancancelbook != 1 || isset($bookinginformation['notbooked'])) {
                $isavailable = true; // True means cancel button is not shown.
            } else if (isset($bookinginformation['onwaitinglist']) || isset($bookinginformation['iambooked'])) {
                // If the user is allowed to cancel, we first check if the user is already booked or on the waiting list.
                // We have to check if there's a limit until a certain date.
                $canceluntil = booking_option::return_cancel_until_date($optionid);
                // If the cancel until date has passed, we do not show cancel button.
                if (
                    class_exists('local_shopping_cart\shopping_cart')
                    && (!empty($settings->jsonobject->useprice))
                ) {
                    $item = (object)[
                        'itemid' => $settings->id,
                        'componentname' => 'mod_booking',
                        'canceluntil' => $canceluntil,
                    ];
                    // Shopping cart allows to cancel.
                    if (!shopping_cart::allowed_to_cancel_for_item($item, 'option')) {
                        $isavailable = true;
                    }

                    // If user is confirmed, we don't block.
                    if (isset($bookinginformation['onwaitinglist'])) {
                        // We don't show cancel when we don't ask for confirmation and it's not fully booked.
                        if (
                            empty($settings->waitforconfirmation)
                            && $bookinginformation['onwaitinglist']['fullybooked'] === false
                        ) {
                            $isavailable = true;
                        } else {
                            $ba = $bookinganswer->usersonwaitinglist[$userid];
                            if (!empty($ba->json)) {
                                $jsonobject = json_decode($ba->json);
                                if (!empty($jsonobject->confirmwaitinglist)) {
                                    $isavailable = true;
                                }
                            }
                        }
                    }
                }

                if (!empty($canceluntil) && $now > $canceluntil) {
                    // Don't display cancel button.
                    $isavailable = true;
                }

                if (self::apply_coolingoff_period($settings, $userid)) {
                    $isavailable = true;
                }
            }
        }

        // If it's inversed, we inverse.
        if ($not) {
            $isavailable = !$isavailable;
        }

        return $isavailable;
    }

    /**
     * Each function can return additional sql.
     * This will be used if the conditions should not only block booking...
     * ... but actually hide the conditons alltogether.
     * @param int $userid
     * @return array
     */
    public function return_sql(int $userid = 0): array {

        return ['', '', '', [], ''];
    }

    /**
     * The hard block is complementary to the is_available check.
     * While is_available is used to build eg also the prebooking modals and...
     * ... introduces eg the booking policy or the subbooking page, the hard block is meant to prevent ...
     * ... unwanted booking. It's the check just before booking if we really...
     * ... want the user to book. It will always return false on subbookings...
     * ... as they are not necessary, but return true when the booking policy is not yet answered.
     * Hard block is only checked if is_available already returns false.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    public function hard_block(booking_option_settings $settings, $userid): bool {
        return true;
    }

    /**
     * Obtains a string describing this restriction (whether or not
     * it actually applies). Used to obtain information that is displayed to
     * students if the activity is not available to them, and for staff to see
     * what conditions are.
     *
     * The $full parameter can be used to distinguish between 'staff' cases
     * (when displaying all information about the activity) and 'student' cases
     * (when displaying only conditions they don't meet).
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and Information string (for admin) about all restrictions on
     *   this item
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {

        $description = '';

        $isavailable = $this->is_available($settings, $userid, $not);
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $description = $this->get_description_string();
        } else {
            $description = 'sc cancel';
        }

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_CANCEL];
    }

    /**
     * Only customizable functions need to return their necessary form elements.
     *
     * @param MoodleQuickForm $mform
     * @param int $optionid
     * @return void
     */
    public function add_condition_to_mform(MoodleQuickForm &$mform, int $optionid = 0) {
        // Do nothing.
    }

    /**
     * The page refers to an additional page which a booking option can inject before the booking process.
     * Not all bo_conditions need to take advantage of this. But eg a condition which requires...
     * ... the acceptance of a booking policy would render the policy with this function.
     *
     * @param int $optionid
     * @param int $userid optional user id
     * @return array
     */
    public function render_page(int $optionid, int $userid = 0) {
        return [];
    }

    /**
     * Some conditions (like price & bookit) provide a button.
     * Renders the button, attaches js to the Page footer and returns the html.
     * Return should look somehow like this.
     * ['mod_booking/bookit_button', $data];
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param bool $full
     * @param bool $not
     * @param bool $fullwidth
     * @return array
     */
    public function render_button(
        booking_option_settings $settings,
        int $userid = 0,
        bool $full = false,
        bool $not = false,
        bool $fullwidth = true
    ): array {

        global $USER, $PAGE;
        if ($userid === null) {
            $userid = $USER->id;
        }

        // At this point, we need some logic, because we have a different button for ...
        // ... purchases and just normal bookings.
        if (
            class_exists('local_shopping_cart\shopping_cart')
            && !empty($settings->jsonobject->useprice)
        ) {
            $user = singleton_service::get_instance_of_user($userid);
            $price = price::get_price('option', $settings->id, $user);

            if (
                !empty((float)($price['price'] ?? 0))
                || !empty(get_config('booking', 'displayemptyprice'))
            ) {
                // Get the booking answers for this instance.
                $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
                $bookinginformation = $bookinganswer->return_all_booking_information($userid);

                if (
                    !isset($bookinginformation['onwaitinglist'])
                    && !isset($bookinginformation['iambooked']['paidwithcredits'])
                ) {
                    $label = get_string('cancelsign', 'mod_booking')
                    . "&nbsp;" . get_string('cancelpurchase', 'local_shopping_cart');

                    return bo_info::render_button(
                        $settings,
                        $userid,
                        $label,
                        'btn btn-light btn-sm shopping-cart-cancel-button',
                        false,
                        $fullwidth,
                        'button',
                        'option',
                        false
                    );
                }
            }
        }

        $label = $this->get_description_string();

        return bo_info::render_button(
            $settings,
            $userid,
            $label,
            'btn btn-light btn-sm',
            false,
            $fullwidth,
            'button',
            'option',
            false
        );
    }

    /**
     * Helper function to return localized description strings.
     *
     * @return string
     */
    private function get_description_string(): string {

        // Do not trigger billboard here.
        return get_string('cancelsign', 'mod_booking') . "&nbsp;" .
            get_string('cancelmyself', 'mod_booking');
    }

    /**
     * Returns false if we are still within the coolingoff period
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    public static function apply_coolingoff_period($settings, $userid): bool {

        $coolingoffperiod = get_config('booking', 'coolingoffperiod');
        if ($coolingoffperiod > 0) {
            $ba = singleton_service::get_instance_of_booking_answers($settings);
            $timemodified = $ba->users[$userid]->timemodified ?? 0;
            if (strtotime("+ $coolingoffperiod seconds", $timemodified) > time()) {
                return true;
            }
        }
        return false;
    }
}
