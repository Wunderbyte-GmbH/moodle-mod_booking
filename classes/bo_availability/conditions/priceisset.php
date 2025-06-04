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
 * Base class for a single booking option availability condition.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

 namespace mod_booking\bo_availability\conditions;

use context_module;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\local\modechecker;
use mod_booking\price;
use mod_booking\singleton_service;
use moodle_url;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * If a price is set for the option, normal booking is not available.
 *
 * Booking only via payment.
 *
 * All bo condition types must extend this class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class priceisset implements bo_condition {
    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_PRICEISSET;

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

        global $DB;

        // This is the return value. Not available to begin with.
        $isavailable = false;

        if (!get_config('booking', 'displayemptyprice') && !empty($settings->jsonobject->useprice)) {
            $user = singleton_service::get_instance_of_user($userid);
            if ($user) {
                $price = price::get_price('option', $settings->id, $user);
                if (isset($price['price']) && empty((float) $price['price'])) {
                    $isavailable = true;
                }
            }
        }

        if (!get_config('booking', 'priceisalwayson')) {
            // If the user is not yet booked we return true.
            if (empty($settings->jsonobject->useprice)) {
                $isavailable = true;
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
     * ... want the user to book. It will return always return false on subbookings...
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

        $description = $this->get_description_string($isavailable, $full, $settings);

        // If shopping cart is not installed, we still want to allow admins to book for others.
        $context = context_module::instance($settings->cmid);
        if (
            !class_exists('local_shopping_cart\shopping_cart') &&
            has_capability('mod/booking:bookforothers', $context)
        ) {
            return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_MYALERT];
        }

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_MYBUTTON];
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
        $response = [
            'data' => [],
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* 'json' => '', */
            'template' => '',
            'buttontype' => 0, // This means that the continue button is enabled.
        ];

        return $response;
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

        $userid = !empty($userid) ? $userid : $USER->id;

        $settings = singleton_service::get_instance_of_booking_option_settings($settings->id);

        $user = singleton_service::get_instance_of_user($userid);

        $data = $settings->return_booking_option_information($user);

        $bookinganswer = singleton_service::get_instance_of_booking_answers($settings);
        $bookinginformation = $bookinganswer->return_all_booking_information($userid);

        if (
            isset($bookinginformation['notbooked']) && ($bookinginformation['notbooked']['onnotifylist']) ||
            (isset($bookinginformation['iambooked']) && $bookinginformation['iambooked']['onnotifylist'])
        ) {
            $data['onlist'] = true;
        }

        if ($fullwidth) {
            $data['fullwidth'] = $fullwidth;
        }

        // The book only on details page avoid js and allows booking only on the details page.
        if (
            get_config('booking', 'bookonlyondetailspage')
            && !modechecker::use_special_details_page_treatment()
        ) {
            $returnurl = $PAGE->url->out();

            // The current page is not /mod/booking/optionview.php.
            $url = new moodle_url("/mod/booking/optionview.php", [
                "optionid" => (int)$settings->id,
                "cmid" => (int)$settings->cmid,
                "userid" => (int)$userid,
                'returnto' => 'url',
                'returnurl' => $returnurl,
            ]);
            $data['link'] = $url->out(false);
            $data['nojs'] = true;
            $data['role'] = '';
        }

        return ['mod_booking/bookit_price', $data];
    }

    /**
     * Helper function to return localized description strings.
     *
     * @param bool $isavailable
     * @param bool $full
     * @param booking_option_settings $settings
     * @return string
     */
    private function get_description_string($isavailable, $full, $settings): string {

        if (
            !$isavailable
            && $this->overwrittenbybillboard
            && !empty($desc = bo_info::apply_billboard($this, $settings))
        ) {
            return $desc;
        }

        if ($isavailable) {
            $description = $full ? get_string('bocondpriceissetfullavailable', 'mod_booking') :
                get_string('bocondpriceissetavailable', 'mod_booking');
        } else {
            $description = $full ? get_string('bocondpriceissetfullnotavailable', 'mod_booking') :
                get_string('bocondpriceissetnotavailable', 'mod_booking');
        }
        return $description;
    }
}
