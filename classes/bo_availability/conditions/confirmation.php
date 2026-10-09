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

use mod_booking\bo_availability\bo_condition;
use mod_booking\bo_availability\bo_info;
use core_qrcode;
use mod_booking\booking_option_settings;
use mod_booking\local\ticket\ticket_manager;
use mod_booking\option\dates_handler;
use mod_booking\output\bookingoption_description;
use mod_booking\singleton_service;
use mod_booking\price;
use moodle_url;
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
class confirmation implements bo_condition {
    /** @var int $id Standard Conditions have hardcoded ids. */
    public $id = MOD_BOOKING_BO_COND_CONFIRMATION;

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
     * Returns the name of the condition.
     *
     * @return string
     *
     */
    public function get_name(): string {
        return get_string('bocondconfirmation', 'mod_booking');
    }

    /**
     * Returns whether the condition is skippable or not.
     *
     * @return bool
     */
    public function is_skippable(): bool {
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

        // This is the return value. Not available to begin with.
        $isavailable = false;

        return $isavailable;
    }

    /**
     * Each function can return additional sql.
     * This will be used if the conditions should not only block booking...
     * ... but actually hide the conditons alltogether.
     * @param int $userid
     * @param array $params This is the array with parameters for the sql query.
     * @return array
     */
    public function return_sql(int $userid = 0, &$params = []): array {

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

        $hardblock = false;

        return $hardblock;
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

        // We don't need a description here.
        $description = '';
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /* $description = !$isavailable ? $this->get_description_string($isavailable, $full, $settings) : ''; */

        return [$isavailable, $description, MOD_BOOKING_BO_PREPAGE_POSTBOOK, MOD_BOOKING_BO_BUTTON_INDIFFERENT];
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

        global $USER;
        $userid = empty($userid) ? $USER->id : $userid;

        $data = new bookingoption_description($optionid, null, MOD_BOOKING_DESCRIPTION_WEBSITE, true, false);
        $bodata = $data->get_returnarray();

        $results = bo_info::get_condition_results($optionid, $userid);
        $lastresultid = array_pop($results)['id'];

        // A booked-state top blocker (incl. SLOTMOVE, which only blocks for an actually-booked,
        // self-rebookable user) means the booking succeeded — otherwise the user sees a false
        // error. This alone is not enough for slot bookings though: slotbooking's own condition
        // (id 2) stays permanently blocking by design (see its own docblock), even once the user
        // has genuinely completed a slot booking - it would otherwise wrongly become the highest
        // blocking id and mask the real state. Cross-check the user's actual booking_answers
        // status directly (same helper bookingoption_description already uses) as a second,
        // authoritative signal.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $userisbooked = $bookinganswers->user_status($userid) === MOD_BOOKING_STATUSPARAM_BOOKED;

        if ($userisbooked || in_array($lastresultid, MOD_BOOKING_BO_COND_BOOKED_STATES, true)) {
            $bodata['alreadybooked'] = true;
        } else {
            switch ($lastresultid) {
                case MOD_BOOKING_BO_COND_ALREADYRESERVED:
                    $bodata['alreadyreserved'] = true;
                    break;
                case MOD_BOOKING_BO_COND_ONWAITINGLIST:
                    $bodata['onwaitinglist'] = true;
                    break;
                default:
                    $bodata['notyetbooked'] = true;
                    break;
            }
        }

        $bodata = array_merge($bodata, self::get_next_steps_data($settings, $bodata, $userid));

        $dataarray[] = [
            'data' => $bodata,
        ];

        $returnarray = [
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            /* 'json' => $jsonstring, */
            'data' => $dataarray,
            'template' => 'mod_booking/condition/confirmation',
            'buttontype' => 1, // This means that the continue button is disabled.
        ];

        return $returnarray;
    }

    /**
     * Template data for the confirmation step: status, next steps and the entry ticket.
     *
     * Every step is derived from the state of the option and of the user's booking answer,
     * nothing is shown that the option does not actually provide.
     *
     * @param booking_option_settings $settings
     * @param array $bodata the option description data, with the state flag already set
     * @param int $userid
     * @return array
     */
    private static function get_next_steps_data(booking_option_settings $settings, array $bodata, int $userid): array {
        $steps = [];
        $ticket = [];

        if (!empty($bodata['alreadybooked'])) {
            $state = 'booked';
            $steps[] = self::step(get_string('confirmationstepbooked', 'mod_booking'), '', true);
            $ticket = self::get_ticket_data($settings, $userid);
            if (!empty($bodata['canstillbecancelled']) && !empty($bodata['canceluntil'])) {
                $steps[] = self::step(get_string('confirmationstepcancel', 'mod_booking'), $bodata['canceluntil']);
            }
            $nextdate = self::get_next_date_step($settings);
            if (!empty($nextdate)) {
                $steps[] = $nextdate;
            }
        } else if (!empty($bodata['alreadyreserved'])) {
            $state = 'reserved';
            $expiration = (int) get_config('local_shopping_cart', 'expirationtime');
            $steps[] = self::step(
                get_string('confirmationstepreserved', 'mod_booking'),
                $expiration > 0 ? get_string('confirmationreservedfor', 'mod_booking', format_time($expiration * MINSECS)) : '',
                true
            );
            // Same label as on the book button, so the price shows exactly where the button shows it.
            $price = price::get_display_label(
                price::get_price('option', (int) $settings->id, singleton_service::get_instance_of_user($userid))
            );
            $steps[] = self::step(get_string('confirmationsteppay', 'mod_booking'), $price, false, true);
            $steps[] = self::step(
                get_string('confirmationstepbooked', 'mod_booking'),
                get_string('confirmationstepbookedafterpayment', 'mod_booking')
            );
        } else if (!empty($bodata['onwaitinglist'])) {
            $state = 'waitinglist';
            $steps[] = self::step(
                get_string('confirmationstepwaitinglist', 'mod_booking'),
                get_string('confirmationstepwaitinglistdesc', 'mod_booking'),
                true
            );
            if (!empty($settings->waitforconfirmation)) {
                $steps[] = self::step(
                    get_string('confirmationstepconfirmation', 'mod_booking'),
                    get_string('confirmationstepconfirmationdesc', 'mod_booking'),
                    false,
                    true
                );
            } else {
                $steps[] = self::step(
                    get_string('confirmationstepmoveup', 'mod_booking'),
                    get_string('confirmationstepmoveupdesc', 'mod_booking'),
                    false,
                    true
                );
            }
        } else {
            $state = 'error';
        }

        // Number the open steps; finished steps show a check mark instead.
        foreach ($steps as $index => $step) {
            $steps[$index]['number'] = $index + 1;
        }

        return [
            'confirmationstate' => $state,
            'confirmationstateclass' => 'mod-booking-confirmation-' . $state,
            'steps' => $steps,
            'hassteps' => !empty($steps),
            'ticket' => $ticket,
            'hasticket' => !empty($ticket),
            'showstatusdescription' => $state === 'booked' && !empty(trim(strip_tags($bodata['statusdescription'] ?? ''))),
            'mybookingsurl' => $state === 'error' ? '' : (new moodle_url('/mod/booking/mybookings.php'))->out(false),
        ];
    }

    /**
     * One entry of the next steps list.
     *
     * @param string $title
     * @param string $text
     * @param bool $done
     * @param bool $current
     * @return array
     */
    private static function step(string $title, string $text = '', bool $done = false, bool $current = false): array {
        return [
            'title' => $title,
            'text' => $text,
            'hastext' => $text !== '',
            'done' => $done,
            'current' => $current,
        ];
    }

    /**
     * The step for the next upcoming date of the option, with the number of further dates.
     *
     * @param booking_option_settings $settings
     * @return array empty if no upcoming date exists (e.g. self-learning courses or past options)
     */
    private static function get_next_date_step(booking_option_settings $settings): array {
        if (!empty($settings->selflearningcourse) || empty($settings->sessions)) {
            return [];
        }
        $now = time();
        $upcoming = array_values(array_filter(
            $settings->sessions,
            fn($session) => (int) ($session->coursestarttime ?? 0) > $now
        ));
        if (empty($upcoming)) {
            return [];
        }
        $next = $upcoming[0];
        $text = dates_handler::prettify_optiondates_start_end(
            (int) $next->coursestarttime,
            (int) $next->courseendtime,
            current_language()
        );
        $more = count($upcoming) - 1;
        if ($more > 0) {
            $text .= ' · ' . ($more === 1
                ? get_string('confirmationmoredate', 'mod_booking')
                : get_string('confirmationmoredates', 'mod_booking', $more));
        }
        return self::step(get_string('confirmationstepnextdate', 'mod_booking'), $text);
    }

    /**
     * The entry ticket of a booked user: QR code of the verification URL and the PDF download.
     *
     * The QR code is rendered locally (core_qrcode), no external service sees the ticket code.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @return array empty if tickets are off for this option or no valid ticket exists
     */
    private static function get_ticket_data(booking_option_settings $settings, int $userid): array {
        if (!ticket_manager::is_enabled_for_option((int) $settings->id)) {
            return [];
        }
        $ticket = ticket_manager::find_or_create_for_booked_user((int) $settings->id, $userid);
        if (empty($ticket) || ticket_manager::is_cancelled($ticket)) {
            return [];
        }
        $qrcode = new core_qrcode(ticket_manager::get_verify_url($ticket)->out(false));
        $svg = $qrcode->getBarcodeSVGcode(4, 4, '#000000');
        return [
            'qrcodesrc' => 'data:image/svg+xml;base64,' . base64_encode($svg),
            'qrcodealt' => get_string('confirmationticketqralt', 'mod_booking', format_string($settings->get_title_with_prefix())),
            'code' => $ticket->code,
            'downloadbutton' => ticket_manager::render_download_button($ticket),
        ];
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

        return [];
    }
}
