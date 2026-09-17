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
 * Allowed to book for user condition.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability\conditions;

use context_system;
use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use mod_booking\booking_option_settings;
use mod_booking\local\bookingworkflow\bookforothers;
use mod_booking\singleton_service;
use MoodleQuickForm;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Checks whether the logged-in user is allowed to book the option for the user it is checked for.
 *
 * Booking for oneself is always allowed. Booking for another user is allowed with the
 * "mod/booking:bookforothers" capability, the shopping cart cashier capability or
 * whenever a bookingextension (eg. confirmation_supervisor) allows it.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class allowedtobookforuser implements bo_condition {
    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER;

    /** @var bool $overwrittenbybillboard Indicates if the condition can be overwritten by the billboard. */
    public $overwrittenbybillboard = true;

    /**
     * Get the condition id.
     *
     * @return int
     */
    public function get_id(): int {
        return $this->id;
    }

    /**
     * Needed to see if class can take JSON.
     *
     * @return bool
     */
    public function is_json_compatible(): bool {
        return false; // Hardcoded condition.
    }

    /**
     * Needed to see if it shows up in mform.
     *
     * @return bool
     */
    public function is_shown_in_mform(): bool {
        return false;
    }

    /**
     * Returns the name of the condition.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('bocondallowedtobookforuser', 'mod_booking');
    }

    /**
     * Returns whether the condition is skippable or not.
     * This condition protects booking for other users, so it must not be skipped.
     *
     * @return bool
     */
    public function is_skippable(): bool {
        return false;
    }

    /**
     * Determines whether the logged-in user may book the item for the given user.
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $not Set true if we are inverting the condition
     * @return bool True if available
     */
    public function is_available(booking_option_settings $settings, int $userid, bool $not = false): bool {
        $isavailable = $this->is_allowed((int)$settings->id, $userid);

        if ($not) {
            $isavailable = !$isavailable;
        }

        return $isavailable;
    }

    /**
     * Checks if the logged-in user (agent) may book the option for the given user.
     *
     * @param int $optionid
     * @param int $userid
     * @return bool
     */
    private function is_allowed(int $optionid, int $userid): bool {
        global $USER;

        $agentid = (int)($USER->id ?? 0);

        // Booking for oneself is always allowed.
        if (empty($userid) || $userid === $agentid) {
            return true;
        }

        // Without a real agent (eg. cron or not logged-in contexts) there is nobody booking for others.
        if (empty($agentid) || isguestuser($agentid)) {
            return true;
        }

        // Cashiers may book for others, this mirrors the guard in shortcodes::init_table_for_courses.
        if (
            class_exists('local_shopping_cart\shopping_cart')
            && has_capability('local/shopping_cart:cashier', context_system::instance())
        ) {
            return true;
        }

        // Checks the "bookforothers" capability and all bookingextensions.
        [$allowed, ] = bookforothers::check_booking_capability($optionid, $agentid, $userid);

        return (bool)$allowed;
    }

    /**
     * Each function can return additional sql.
     * This will be used if the conditions should not only block booking...
     * ... but actually hide the conditions altogether.
     *
     * @param int $userid
     * @param array $params This is the array with parameters for the sql query.
     * @return array
     */
    public function return_sql(int $userid = 0, &$params = []): array {
        return ['', '', '', [], ''];
    }

    /**
     * The hard block is complementary to the is_available check.
     * Not being allowed to book for the user must always prevent the booking.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return bool
     */
    public function hard_block(booking_option_settings $settings, $userid): bool {
        return true;
    }

    /**
     * Obtains a string describing this restriction.
     *
     * @param booking_option_settings $settings Item we're checking
     * @param int $userid User ID to check availability for
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @return array availability and information string about this restriction
     */
    public function get_description(booking_option_settings $settings, $userid = null, $full = false, $not = false): array {

        $isavailable = $this->is_available($settings, (int)$userid, $not);

        $description = !$isavailable ? $this->get_description_string($isavailable, $full, $settings, (int)$userid) : '';

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_NONE, MOD_BOOKING_BO_BUTTON_JUSTMYALERT];
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
     * This condition does not provide a page.
     *
     * @param int $optionid
     * @param int $userid optional user id
     * @return array
     */
    public function render_page(int $optionid, int $userid = 0): array {
        return [];
    }

    /**
     * Renders the label telling the user that they may not book for this user.
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

        $label = $this->get_description_string(false, $full, $settings, $userid);

        // No price: the current user may not book this option for the user anyway.
        return bo_info::render_button($settings, $userid, $label, 'alert alert-warning', false, $fullwidth, 'alert', 'option');
    }

    /**
     * Helper function to return localized description strings.
     *
     * @param bool $isavailable
     * @param bool $full
     * @param booking_option_settings $settings
     * @param int $userid the user the option would be booked for
     * @return string
     */
    public function get_description_string($isavailable, $full, $settings, int $userid = 0): string {

        if ($isavailable) {
            return '';
        }

        if (
            $this->overwrittenbybillboard
            && !empty($desc = bo_info::apply_billboard($this, $settings))
        ) {
            return $desc;
        }

        $user = !empty($userid) ? singleton_service::get_instance_of_user($userid) : null;
        $name = !empty($user) ? fullname($user) : '';

        return $full ? get_string('bocondallowedtobookforuserfullnotavailable', 'mod_booking', $name) :
            get_string('bocondallowedtobookforusernotavailable', 'mod_booking', $name);
    }
}
