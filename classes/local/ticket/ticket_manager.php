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

namespace mod_booking\local\ticket;

use context_module;
use context_system;
use core_user;
use mod_booking\booking_option;
use mod_booking\local\certificateclass;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;
use stored_file;

/**
 * Entry-ticket manager ("SofaTicket").
 *
 * A ticket is a {booking_tickets} record owned by mod_booking. tool_certificate is only used as a
 * layout engine to build the PDF (see \mod_booking\local\ticket\ticket_pdf) — no
 * {tool_certificate_issues} row is written, no certificate event is fired and no tool_certificate
 * notification is sent. This keeps tickets completely separate from the certificate feature.
 *
 * Which template is used, and whether tickets are personalized or require an identity check at the
 * door, is configured per booking option in the "Ticketing" section of the option form.
 *
 * Traceability of check-ins runs via the booking presence status
 * (MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN) plus the Moodle logstore (ticket_scanned event).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_manager {
    /** @var string The table holding the tickets. */
    public const TABLE = 'booking_tickets';

    /** @var string File area of the generated ticket PDFs, in the module context of the booking instance. */
    public const FILEAREA = 'tickets';

    /** @var string Status of a ticket that is still valid. */
    public const STATUS_VALID = 'valid';

    /** @var string Status of a ticket that has been cancelled. */
    public const STATUS_CANCELLED = 'cancelled';

    /** @var string Booking option JSON key holding the id of the ticket template. */
    public const JSON_TEMPLATE = 'ticket';

    /** @var string Booking option JSON key: 1 if the ticket is bound to its holder. */
    public const JSON_PERSONALIZED = 'ticketpersonalized';

    /**
     * @var string Booking option JSON key: 1 if entry staff must explicitly confirm the holder's identity.
     * The scanner then shows the identity data even for non-personalised tickets, and the webservice
     * refuses to check in unless the caller passes confirmed=true.
     */
    public const JSON_CONFIRMIDENTITY = 'ticketconfirmidentity';

    /** @var string Booking option JSON key holding additional free text printed on the ticket. */
    public const JSON_EXTRAINFO = 'ticketextrainfo';

    /** @var string Booking option JSON key: user ids allowed to scan tickets of this option without the capability. */
    public const JSON_SCANNERS = 'ticketscanners';

    /** @var string Booking option JSON key: seconds before a date's start from which the scanner is available. */
    public const JSON_SCANBEFORE = 'ticketscanbefore';

    /** @var string Booking option JSON key: seconds after a date's end until which the scanner is available. */
    public const JSON_SCANAFTER = 'ticketscanafter';

    /** @var int Seconds before a session's start from which it counts as "running" for the nearest-date pick. */
    public const NEAREST_DATE_LEAD = 2 * HOURSECS;

    /** @var string Prefix of custom profile field keys in the "bookingticketidentityfields" setting. */
    public const IDENTITY_PROFILE_PREFIX = 'profile_';

    /**
     * Whether the entry-ticket feature is globally enabled.
     *
     * Per booking option configuration is checked with is_enabled_for_option().
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        if (!class_exists('tool_certificate\\template')) {
            return false;
        }
        return !empty(get_config('booking', 'bookingticketon'));
    }

    /**
     * The ticket template configured on a booking option, if the template still exists.
     *
     * @param int $optionid
     *
     * @return int Template id, or 0 if none is configured.
     */
    public static function get_template_id_for_option(int $optionid): int {
        global $DB;

        if (empty($optionid)) {
            return 0;
        }
        $templateid = (int) (booking_option::get_value_of_json_by_key($optionid, self::JSON_TEMPLATE) ?? 0);
        if (empty($templateid)) {
            return 0;
        }
        if (!$DB->record_exists('tool_certificate_templates', ['id' => $templateid])) {
            return 0;
        }
        return $templateid;
    }

    /**
     * Whether tickets are configured for a booking option.
     *
     * @param int $optionid
     *
     * @return bool
     */
    public static function is_enabled_for_option(int $optionid): bool {
        return self::is_enabled() && !empty(self::get_template_id_for_option($optionid));
    }

    /**
     * Whether tickets of an option are bound to their holder (and may therefore not be resold).
     *
     * Defaults to true: a ticket that was not explicitly marked transferable is personalized.
     *
     * @param int $optionid
     *
     * @return bool
     */
    public static function is_personalized(int $optionid): bool {
        $value = booking_option::get_value_of_json_by_key($optionid, self::JSON_PERSONALIZED);
        if ($value === null || $value === '') {
            return true;
        }
        return !empty($value);
    }

    /**
     * Whether the door scanner has to confirm the holder's identity before checking a participant in.
     *
     * @param int $optionid
     *
     * @return bool
     */
    public static function requires_identity_confirmation(int $optionid): bool {
        return !empty(booking_option::get_value_of_json_by_key($optionid, self::JSON_CONFIRMIDENTITY));
    }

    /**
     * The presence status a successful scan sets a participant to (default: CHECKEDIN).
     *
     * @return int
     */
    public static function get_checkin_status(): int {
        $status = get_config('booking', 'bookingticketcheckinstatus');
        if ($status === false || $status === '') {
            return MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN;
        }
        return (int) $status;
    }

    /**
     * The one place deciding whether a user may scan entry tickets.
     *
     * A user may scan when they hold mod/booking:scanticket in the booking instance, or - for a
     * specific option - when they were picked as entry staff in the option's "Ticketing" section.
     * Without an option (instance-wide scanner) only the capability counts. Guests never may.
     *
     * @param int $cmid Course module id of the booking instance.
     * @param int $optionid Booking option, 0 for the instance-wide scanner.
     * @param int $userid Defaults to the current user.
     *
     * @return bool
     */
    public static function can_scan(int $cmid, int $optionid = 0, int $userid = 0): bool {
        global $USER;

        if (empty($userid)) {
            $userid = (int) ($USER->id ?? 0);
        }
        if (empty($cmid) || empty($userid) || isguestuser($userid)) {
            return false;
        }
        $context = context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        if (has_capability('mod/booking:scanticket', $context, $userid)) {
            return true;
        }
        if (empty($optionid)) {
            return false;
        }
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->id) || (int) $settings->cmid !== $cmid) {
            return false;
        }
        return in_array($userid, self::get_scanner_userids($optionid), true);
    }

    /**
     * Whether a user may download a ticket PDF.
     *
     * The holder always may. Others need "View ticket report" in the booking instance, or must be
     * allowed to scan the ticket's option (see can_scan()). Guests never may.
     *
     * @param stdClass $ticket Record of booking_tickets.
     * @param int $cmid Course module id of the booking instance the file was requested in.
     * @param int $userid Defaults to the current user.
     *
     * @return bool
     */
    public static function can_download(stdClass $ticket, int $cmid, int $userid = 0): bool {
        global $USER;

        if (empty($userid)) {
            $userid = (int) ($USER->id ?? 0);
        }
        if (empty($userid) || isguestuser($userid) || empty($cmid)) {
            return false;
        }
        if ((int) $ticket->userid === $userid) {
            return true;
        }
        $context = context_module::instance($cmid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        if (has_capability('mod/booking:viewticketreport', $context, $userid)) {
            return true;
        }
        return self::can_scan($cmid, (int) $ticket->optionid, $userid);
    }

    /**
     * Throw the capability exception unless the user may scan (see can_scan()).
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     *
     * @return void
     * @throws \required_capability_exception
     */
    public static function require_can_scan(int $cmid, int $optionid = 0, int $userid = 0): void {
        if (!self::can_scan($cmid, $optionid, $userid)) {
            throw new \required_capability_exception(
                context_module::instance($cmid),
                'mod/booking:scanticket',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * The users picked as entry staff of an option (in addition to capability holders).
     *
     * @param int $optionid
     *
     * @return int[]
     */
    public static function get_scanner_userids(int $optionid): array {
        if (empty($optionid)) {
            return [];
        }
        $value = booking_option::get_value_of_json_by_key($optionid, self::JSON_SCANNERS);
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    /**
     * Whether the current user may pick (and see) a given user as entry staff.
     *
     * Users allowed to view every profile on the site (managers) may pick anyone; everybody else
     * only users whose profile they may view in the option's course (core user_can_view_profile()).
     *
     * @param stdClass $user At least id, and deleted (set to 0 for lightweight search rows).
     * @param stdClass $course Full course record of the booking instance.
     *
     * @return bool
     */
    public static function user_may_pick_scanner(stdClass $user, stdClass $course): bool {
        global $CFG;
        require_once("{$CFG->dirroot}/user/lib.php");

        if (has_capability('moodle/user:viewdetails', context_system::instance())) {
            return true;
        }
        if (!isset($user->deleted)) {
            $user->deleted = 0;
        }
        return user_can_view_profile($user, $course);
    }

    /**
     * The availability window of the scanner for an option.
     *
     * Thresholds (JSON_SCANBEFORE / JSON_SCANAFTER, seconds) open the scanner around every date of the
     * option: from start - before until end + after. A missing threshold is unbounded on that side,
     * so without any threshold - or for options without dates - the scanner is always available.
     *
     * @param int $optionid
     * @param int|null $now
     *
     * @return array ['open' => bool, 'nextopen' => int (0 if none), 'closesat' => int (0 if open-ended)]
     */
    public static function get_scan_window(int $optionid, ?int $now = null): array {
        $now = $now ?? time();
        $always = ['open' => true, 'nextopen' => 0, 'closesat' => 0];

        $before = booking_option::get_value_of_json_by_key($optionid, self::JSON_SCANBEFORE);
        $after = booking_option::get_value_of_json_by_key($optionid, self::JSON_SCANAFTER);
        $before = ($before === null || $before === '') ? null : (int) $before;
        $after = ($after === null || $after === '') ? null : (int) $after;
        if ($before === null && $after === null) {
            return $always;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $sessions = self::real_sessions($settings->sessions ?? []);
        if (empty($sessions)) {
            return $always;
        }

        $nextopen = 0;
        foreach ($sessions as $session) {
            $start = (int) $session->coursestarttime;
            $end = max((int) ($session->courseendtime ?? 0), $start);
            $opens = $before === null ? PHP_INT_MIN : $start - $before;
            $closes = $after === null ? PHP_INT_MAX : $end + $after;
            if ($opens <= $now && $now <= $closes) {
                return ['open' => true, 'nextopen' => 0, 'closesat' => $after === null ? 0 : $closes];
            }
            if ($opens > $now && ($nextopen === 0 || $opens < $nextopen)) {
                $nextopen = $opens;
            }
        }
        return ['open' => false, 'nextopen' => $nextopen, 'closesat' => 0];
    }

    /**
     * The sessions of an option that exist as booking_optiondates rows, keyed by optiondate id.
     *
     * The legacy synthetic session (id 0, built from the option's own start/end) is dropped: no
     * per-date presence can be stored for it.
     *
     * @param array $sessions As found in booking_option_settings::$sessions.
     *
     * @return array
     */
    public static function real_sessions(array $sessions): array {
        $real = [];
        foreach ($sessions as $session) {
            if (!empty($session->id)) {
                $real[(int) $session->id] = $session;
            }
        }
        return $real;
    }

    /**
     * Human readable label of a session (localized date range).
     *
     * @param stdClass $session
     *
     * @return string
     */
    public static function date_label(stdClass $session): string {
        $start = (int) $session->coursestarttime;
        $end = (int) ($session->courseendtime ?? 0);
        $label = userdate($start, get_string('strftimedatetimeshort', 'langconfig'));
        if ($end > $start) {
            $sameday = userdate($start, '%Y%m%d') === userdate($end, '%Y%m%d');
            $label .= ' - ' . userdate(
                $end,
                get_string($sameday ? 'strftimetime' : 'strftimedatetimeshort', 'langconfig')
            );
        }
        return $label;
    }

    /**
     * The dates of an option for the scanner's date selection, with a holder's check-in per date.
     *
     * @param int $optionid
     * @param int $userid Ticket holder whose presence is reported per date (0 = nobody).
     *
     * @return array List of ['optiondateid', 'starttime', 'endtime', 'label', 'present'].
     */
    public static function get_scan_dates(int $optionid, int $userid = 0): array {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $sessions = self::real_sessions($settings->sessions ?? []);
        if (empty($sessions)) {
            return [];
        }
        $present = [];
        if ($userid) {
            $rows = $DB->get_records('booking_optiondates_answers', [
                'optionid' => $optionid,
                'userid' => $userid,
                'status' => self::get_checkin_status(),
            ], '', 'id, optiondateid');
            foreach ($rows as $row) {
                $present[(int) $row->optiondateid] = true;
            }
        }
        $dates = [];
        foreach ($sessions as $id => $session) {
            $dates[] = [
                'optiondateid' => $id,
                'starttime' => (int) $session->coursestarttime,
                'endtime' => (int) ($session->courseendtime ?? 0),
                'label' => self::date_label($session),
                'present' => !empty($present[$id]),
            ];
        }
        return $dates;
    }

    /**
     * Pick the option date (session) an entry scan most likely refers to.
     *
     * Order of preference: a session that is running right now (from two hours before its start
     * until its end), then the next upcoming session, then the most recent past session.
     * Sessions without a real id (the legacy synthetic session built from the option's own
     * start/end time) are ignored because no per-date presence can be stored for them.
     *
     * @param array $sessions Session records as found in booking_option_settings::$sessions.
     * @param int|null $now Reference time, defaults to time().
     *
     * @return int The optiondate id, or 0 if the option has no dates.
     */
    public static function pick_nearest_optiondate(array $sessions, ?int $now = null): int {
        $now = $now ?? time();
        $upcoming = null;
        $past = null;

        foreach ($sessions as $session) {
            if (empty($session->id)) {
                continue;
            }
            $start = (int) ($session->coursestarttime ?? 0);
            $end = (int) ($session->courseendtime ?? 0);
            if ($end < $start) {
                $end = $start;
            }
            if ($start - self::NEAREST_DATE_LEAD <= $now && $now <= $end) {
                return (int) $session->id;
            }
            if ($start > $now) {
                if ($upcoming === null || $start < (int) $upcoming->coursestarttime) {
                    $upcoming = $session;
                }
            } else if ($past === null || $start > (int) $past->coursestarttime) {
                $past = $session;
            }
        }

        if ($upcoming !== null) {
            return (int) $upcoming->id;
        }
        if ($past !== null) {
            return (int) $past->id;
        }
        return 0;
    }

    /**
     * Time of the check-in of a user on one option date, or null if not (yet) checked in.
     *
     * @param int $optionid
     * @param int $optiondateid
     * @param int $userid
     *
     * @return int|null
     */
    public static function is_present_on_date(int $optionid, int $optiondateid, int $userid): ?int {
        global $DB;

        if (empty($optionid) || empty($optiondateid) || empty($userid)) {
            return null;
        }
        $record = $DB->get_record('booking_optiondates_answers', [
            'optionid' => $optionid,
            'optiondateid' => $optiondateid,
            'userid' => $userid,
        ], 'id, status, timemodified', IGNORE_MULTIPLE);
        if (!$record || (int) $record->status !== self::get_checkin_status()) {
            return null;
        }
        return (int) $record->timemodified;
    }

    /**
     * Number of participants checked in on one option date.
     *
     * @param int $optionid
     * @param int $optiondateid
     *
     * @return int
     */
    public static function count_present_on_date(int $optionid, int $optiondateid): int {
        global $DB;

        if (empty($optionid) || empty($optiondateid)) {
            return 0;
        }
        return $DB->count_records('booking_optiondates_answers', [
            'optionid' => $optionid,
            'optiondateid' => $optiondateid,
            'status' => self::get_checkin_status(),
        ]);
    }

    /**
     * The identity fields the scanner may show, as setting choices (key => label).
     *
     * Core fields use fixed keys; custom profile fields are prefixed with "profile_" so a custom
     * shortname can never shadow a core key.
     *
     * @return array
     */
    public static function get_identity_field_choices(): array {
        global $CFG;
        require_once("{$CFG->dirroot}/user/profile/lib.php");

        $choices = [
            'picture' => get_string('ticketidentitypicture', 'mod_booking'),
            'fullname' => get_string('ticketidentityfullname', 'mod_booking'),
            'email' => get_string('email'),
            'idnumber' => get_string('idnumber'),
            'phone1' => get_string('phone1'),
            'city' => get_string('city'),
            'country' => get_string('country'),
        ];
        foreach (profile_get_custom_fields() as $field) {
            $choices[self::IDENTITY_PROFILE_PREFIX . $field->shortname] = format_string($field->name) .
                " ({$field->shortname})";
        }
        return $choices;
    }

    /**
     * The identity field keys configured in the "bookingticketidentityfields" setting.
     *
     * @return string[]
     */
    public static function get_configured_identity_fields(): array {
        $configured = (string) get_config('booking', 'bookingticketidentityfields');
        if ($configured === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    /**
     * Identity data of a ticket holder for the entry check, as configured in the site settings.
     *
     * Returns a list of ['shortname' => ..., 'name' => ..., 'value' => ...] in the configured order.
     * The "picture" choice is not part of the list: the picture URL is delivered separately.
     * Values are plain text (custom profile fields are rendered through their display_data()).
     *
     * @param int $userid
     *
     * @return array
     */
    public static function get_identity_fields(int $userid): array {
        global $CFG;

        $keys = self::get_configured_identity_fields();
        if (empty($keys) || empty($userid)) {
            return [];
        }
        $user = core_user::get_user($userid);
        if (!$user) {
            return [];
        }

        $custom = [];
        $wantscustom = array_filter($keys, fn($key) => strpos($key, self::IDENTITY_PROFILE_PREFIX) === 0);
        if (!empty($wantscustom)) {
            require_once("{$CFG->dirroot}/user/profile/lib.php");
            foreach (profile_get_user_fields_with_data($userid) as $formfield) {
                $custom[$formfield->get_shortname()] = $formfield;
            }
        }

        $labels = null;
        $fields = [];
        foreach ($keys as $key) {
            if ($key === 'picture') {
                continue;
            }
            $value = '';
            $name = '';
            if (strpos($key, self::IDENTITY_PROFILE_PREFIX) === 0) {
                $shortname = substr($key, strlen(self::IDENTITY_PROFILE_PREFIX));
                if (empty($custom[$shortname])) {
                    continue;
                }
                $name = format_string($custom[$shortname]->field->name);
                $value = trim(html_entity_decode(
                    strip_tags((string) $custom[$shortname]->display_data()),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ));
            } else {
                $labels = $labels ?? self::get_identity_field_choices();
                if (!isset($labels[$key])) {
                    continue;
                }
                $name = $labels[$key];
                switch ($key) {
                    case 'fullname':
                        $value = fullname($user);
                        break;
                    case 'country':
                        $countries = get_string_manager()->get_list_of_countries();
                        $value = $countries[$user->country] ?? (string) $user->country;
                        break;
                    default:
                        $value = (string) ($user->{$key} ?? '');
                }
            }
            $fields[] = ['shortname' => $key, 'name' => $name, 'value' => $value];
        }
        return $fields;
    }

    /**
     * Create a ticket for a user on a booking option.
     *
     * Idempotent: if a valid ticket already exists for the user + option it is returned unchanged.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $answerid Optional id of the booking answer the ticket was created for.
     *
     * @return stdClass|null The ticket record, or null if nothing was created.
     */
    public static function create_ticket(int $optionid, int $userid, int $answerid = 0): ?stdClass {
        global $DB;

        if (empty($optionid) || empty($userid) || !self::is_enabled_for_option($optionid)) {
            return null;
        }

        // Never create a second valid ticket for the same user + option. A plain DB
        // unique index cannot back this up (cancelled tickets may accumulate per
        // user + option), so serialize concurrent creations - e.g. duplicated booked
        // events - with a lock and re-check inside it.
        if ($existing = self::find_valid_ticket($optionid, $userid)) {
            return $existing;
        }
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_booking_ticket_create');
        $lock = $lockfactory->get_lock("option{$optionid}user{$userid}", 10);
        try {
            if ($existing = self::find_valid_ticket($optionid, $userid)) {
                return $existing;
            }
            return self::create_ticket_record($optionid, $userid, $answerid);
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Build and persist a new ticket record incl. PDF and created event.
     *
     * Callers must have ensured (under lock) that no valid ticket exists yet.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $answerid
     *
     * @return stdClass|null
     */
    private static function create_ticket_record(int $optionid, int $userid, int $answerid): ?stdClass {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->id)) {
            return null;
        }
        $user = core_user::get_user($userid);
        if (empty($user)) {
            return null;
        }

        $now = time();
        $data = certificateclass::build_certificate_data($settings, $userid, $now, null);
        // Tool_certificate used to add this key implicitly when issuing, so templates may rely on it.
        $data['userfullname'] = fullname($user);
        $data['courseid'] = (int) ($settings->courseid ?? 0);
        $data[self::JSON_EXTRAINFO] = (string) (
            booking_option::get_value_of_json_by_key($optionid, self::JSON_EXTRAINFO) ?? ''
        );

        $ticket = (object) [
            'optionid' => $optionid,
            'optiondateid' => 0,
            'userid' => $userid,
            'answerid' => $answerid ?: self::find_answerid($optionid, $userid),
            'templateid' => self::get_template_id_for_option($optionid),
            'code' => self::generate_code(),
            'status' => self::STATUS_VALID,
            'personalized' => self::is_personalized($optionid) ? 1 : 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'timerevoked' => 0,
            'json' => json_encode($data),
        ];
        $ticket->id = $DB->insert_record(self::TABLE, $ticket);

        // A failed PDF is not fatal: the record stays and regenerate_pdf() fills it in on first access.
        self::store_pdf($ticket);

        $event = \mod_booking\event\ticket_created::create([
            'context' => context_module::instance($settings->cmid),
            'objectid' => $ticket->id,
            'relateduserid' => $userid,
            'other' => [
                'optionid' => $optionid,
                'optionname' => $settings->get_title_with_prefix(),
                'ticketid' => $ticket->id,
                'code' => $ticket->code,
            ],
        ]);
        $event->trigger();

        return $ticket;
    }

    /**
     * Generate a unique ticket verification code.
     *
     * Stays within [A-Z0-9] so PARAM_ALPHANUM can be used everywhere a code is accepted.
     *
     * @return string
     */
    public static function generate_code(): string {
        global $DB;

        do {
            $code = strtoupper(random_string(16));
        } while ($DB->record_exists(self::TABLE, ['code' => $code]));

        return $code;
    }

    /**
     * Render the ticket PDF and store it in the module context of the booking instance.
     *
     * Replaces any previously stored PDF of this ticket.
     *
     * @param stdClass $ticket A {booking_tickets} record.
     *
     * @return stored_file|null
     */
    public static function store_pdf(stdClass $ticket): ?stored_file {
        $settings = singleton_service::get_instance_of_booking_option_settings((int) $ticket->optionid);
        if (empty($settings->cmid)) {
            return null;
        }
        $user = core_user::get_user((int) $ticket->userid);
        if (empty($user)) {
            return null;
        }

        $data = json_decode($ticket->json ?? '{}', true);
        if (!is_array($data)) {
            $data = [];
        }

        $content = ticket_pdf::render((int) $ticket->templateid, $ticket, $user, $data);
        if ($content === '') {
            return null;
        }

        $context = context_module::instance($settings->cmid);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_booking', self::FILEAREA, $ticket->id);

        return $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_booking',
            'filearea' => self::FILEAREA,
            'itemid' => $ticket->id,
            'filepath' => '/',
            'filename' => clean_filename($ticket->code . '.pdf'),
        ], $content);
    }

    /**
     * Regenerate the PDF of an existing ticket.
     *
     * @param int $ticketid
     *
     * @return stored_file|null
     */
    public static function regenerate_pdf(int $ticketid): ?stored_file {
        global $DB;

        $ticket = $DB->get_record(self::TABLE, ['id' => $ticketid]);
        if (empty($ticket)) {
            return null;
        }
        return self::store_pdf($ticket);
    }

    /**
     * Cancel every valid ticket a user holds for an option (self-cancel, admin cancel, deletion).
     *
     * The record and its PDF are kept so the ticket stays verifiable; only the status changes.
     * Idempotent, and a no-op when there is no valid ticket.
     *
     * @param int $optionid
     * @param int $userid
     * @param int|null $cancelledtime Defaults to now.
     *
     * @return int Number of tickets cancelled.
     */
    public static function cancel_ticket(int $optionid, int $userid, ?int $cancelledtime = null): int {
        global $DB;

        if (empty($optionid) || empty($userid)) {
            return 0;
        }
        // Guard so cancellation never fatals before the table exists (upgrade order).
        if (!$DB->get_manager()->table_exists(self::TABLE)) {
            return 0;
        }

        $cancelledtime = $cancelledtime ?? time();
        $tickets = $DB->get_records(self::TABLE, [
            'optionid' => $optionid,
            'userid' => $userid,
            'status' => self::STATUS_VALID,
        ]);

        foreach ($tickets as $ticket) {
            $DB->update_record(self::TABLE, (object) [
                'id' => $ticket->id,
                'status' => self::STATUS_CANCELLED,
                'timerevoked' => $cancelledtime,
                'timemodified' => time(),
            ]);
        }

        return count($tickets);
    }

    /**
     * The valid ticket of a user for an option, or null.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return stdClass|null
     */
    public static function find_valid_ticket(int $optionid, int $userid): ?stdClass {
        global $DB;

        if (empty($optionid) || empty($userid)) {
            return null;
        }
        $ticket = $DB->get_record(self::TABLE, [
            'optionid' => $optionid,
            'userid' => $userid,
            'status' => self::STATUS_VALID,
        ], '*', IGNORE_MULTIPLE);

        return $ticket ?: null;
    }

    /**
     * The valid ticket of a booked user, created on the spot if the booking predates the ticket design.
     *
     * Only users with an active booking (not on the waiting list) get a ticket. Creating a ticket
     * renders its PDF, so callers should use this for a single user (e.g. the current one), never
     * for every row of a participant list.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return stdClass|null
     */
    public static function find_or_create_for_booked_user(int $optionid, int $userid): ?stdClass {
        if ($ticket = self::find_valid_ticket($optionid, $userid)) {
            return $ticket;
        }
        if (!self::is_enabled_for_option($optionid) || empty(self::find_answerid($optionid, $userid))) {
            return null;
        }
        return self::create_ticket($optionid, $userid);
    }

    /**
     * The download button (ticket icon + "Ticket") for a ticket, or an empty string without a PDF URL.
     *
     * @param stdClass|null $ticket
     *
     * @return string
     */
    public static function render_download_button(?stdClass $ticket): string {
        if (empty($ticket) || self::is_cancelled($ticket)) {
            return '';
        }
        $url = self::get_file_url($ticket);
        if (empty($url)) {
            return '';
        }
        return \html_writer::link(
            $url,
            \html_writer::tag('i', '', ['class' => 'fa fa-fw fa-ticket', 'aria-hidden' => 'true'])
                . ' ' . get_string('ticketbutton', 'mod_booking'),
            [
                'target' => '_blank',
                'class' => 'btn btn-outline-secondary btn-sm mod-booking-ticket-link',
                'role' => 'button',
                'title' => get_string('ticketdownload', 'mod_booking'),
                'aria-label' => get_string('ticketdownload', 'mod_booking'),
            ]
        );
    }

    /**
     * Look up a ticket by its verification code.
     *
     * @param string $code
     *
     * @return stdClass|null
     */
    public static function find_by_code(string $code): ?stdClass {
        global $DB;

        if ($code === '') {
            return null;
        }
        $ticket = $DB->get_record(self::TABLE, ['code' => $code]);
        return $ticket ?: null;
    }

    /**
     * All tickets of a user, newest first.
     *
     * @param int $userid
     *
     * @return stdClass[]
     */
    public static function find_all_for_user(int $userid): array {
        global $DB;

        if (empty($userid)) {
            return [];
        }
        return $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated DESC, id DESC');
    }

    /**
     * Whether a ticket has been cancelled.
     *
     * @param stdClass $ticket
     *
     * @return bool
     */
    public static function is_cancelled(stdClass $ticket): bool {
        return ($ticket->status ?? self::STATUS_VALID) === self::STATUS_CANCELLED;
    }

    /**
     * The stored PDF of a ticket, or null if it has not been generated (yet).
     *
     * @param stdClass $ticket
     *
     * @return stored_file|null
     */
    public static function get_file(stdClass $ticket): ?stored_file {
        $settings = singleton_service::get_instance_of_booking_option_settings((int) $ticket->optionid);
        if (empty($settings->cmid)) {
            return null;
        }
        $context = context_module::instance($settings->cmid);
        $fs = get_file_storage();
        $file = $fs->get_file(
            $context->id,
            'mod_booking',
            self::FILEAREA,
            $ticket->id,
            '/',
            clean_filename($ticket->code . '.pdf')
        );

        return $file ?: null;
    }

    /**
     * The download URL of a ticket PDF, or null if there is no stored file.
     *
     * @param stdClass $ticket
     *
     * @return moodle_url|null
     */
    public static function get_file_url(stdClass $ticket): ?moodle_url {
        $file = self::get_file($ticket);
        if (empty($file)) {
            return null;
        }
        return moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'mod_booking',
            self::FILEAREA,
            $ticket->id,
            '/',
            $file->get_filename()
        );
    }

    /**
     * The public verification URL of a ticket. This is what the QR code on the PDF encodes.
     *
     * @param stdClass $ticket
     *
     * @return moodle_url
     */
    public static function get_verify_url(stdClass $ticket): moodle_url {
        return new moodle_url('/mod/booking/verifyticket.php', ['code' => $ticket->code]);
    }

    /**
     * Find ticket designs (tool_certificate templates) matching a name or an id.
     *
     * Used by the booking AI agent, which knows template names but never numeric ids. A purely
     * numeric query is treated as an id; everything else is matched against the template name,
     * preferring an exact (case-insensitive) match over partial ones.
     *
     * @param string $query Template name, part of a name, or a numeric template id.
     * @param int $limit Maximum number of candidates to return.
     *
     * @return array List of ['id' => int, 'name' => string], best match first.
     */
    public static function search_templates(string $query, int $limit = 5): array {
        global $DB;

        $query = trim($query);
        if ($query === '' || !$DB->get_manager()->table_exists('tool_certificate_templates')) {
            return [];
        }

        // A numeric query is an id, but only if such a template really exists.
        if (ctype_digit($query)) {
            $record = $DB->get_record('tool_certificate_templates', ['id' => (int) $query], 'id, name');
            if (!empty($record)) {
                return [['id' => (int) $record->id, 'name' => (string) $record->name]];
            }
        }

        // Exact name match wins, so "Ticket" never becomes ambiguous when a template is called exactly that.
        $exact = $DB->get_records_select(
            'tool_certificate_templates',
            $DB->sql_equal('name', ':name', false),
            ['name' => $query],
            'name ASC',
            'id, name',
            0,
            $limit
        );
        if (!empty($exact)) {
            return array_values(array_map(
                fn($record) => ['id' => (int) $record->id, 'name' => (string) $record->name],
                $exact
            ));
        }

        $like = $DB->sql_like('name', ':name', false, false);
        $records = $DB->get_records_select(
            'tool_certificate_templates',
            $like,
            ['name' => '%' . $DB->sql_like_escape($query) . '%'],
            'name ASC',
            'id, name',
            0,
            $limit
        );

        return array_values(array_map(
            fn($record) => ['id' => (int) $record->id, 'name' => (string) $record->name],
            $records
        ));
    }

    /**
     * Delete the tickets of one booking option: PDF files and DB rows.
     *
     * Must run while the option (and its course module) still exists, so the
     * module context for the file area can be resolved.
     *
     * @param int $optionid
     * @param int $userid Limit to one user, 0 = all users.
     *
     * @return void
     */
    public static function delete_tickets_for_option(int $optionid, int $userid = 0): void {
        global $DB;

        if (!$DB->get_manager()->table_exists(self::TABLE)) {
            return;
        }
        $params = ['optionid' => $optionid];
        if (!empty($userid)) {
            $params['userid'] = $userid;
        }
        self::delete_ticket_records($DB->get_records(self::TABLE, $params));
    }

    /**
     * Delete the tickets of a whole booking instance: PDF files and DB rows.
     *
     * Must run before the booking_options rows of the instance are deleted, so
     * the option -> cm resolution for the file area still works.
     *
     * @param int $bookingid
     * @param array $userids Limit to these users, empty = all users.
     *
     * @return void
     */
    public static function delete_tickets_for_booking(int $bookingid, array $userids = []): void {
        global $DB;

        if (!$DB->get_manager()->table_exists(self::TABLE)) {
            return;
        }
        $sql = "SELECT t.*
                  FROM {" . self::TABLE . "} t
                  JOIN {booking_options} bo ON bo.id = t.optionid
                 WHERE bo.bookingid = :bookingid";
        $params = ['bookingid' => $bookingid];
        if (!empty($userids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'tuser');
            $sql .= " AND t.userid {$insql}";
            $params += $inparams;
        }
        self::delete_ticket_records($DB->get_records_sql($sql, $params));
    }

    /**
     * Delete the given ticket records together with their PDF files.
     *
     * @param array $tickets Records from booking_tickets.
     *
     * @return void
     */
    private static function delete_ticket_records(array $tickets): void {
        global $DB;

        $fs = get_file_storage();
        foreach ($tickets as $ticket) {
            $settings = singleton_service::get_instance_of_booking_option_settings((int) $ticket->optionid);
            if (!empty($settings->cmid)) {
                $context = context_module::instance($settings->cmid, IGNORE_MISSING);
                if ($context) {
                    $fs->delete_area_files($context->id, 'mod_booking', self::FILEAREA, $ticket->id);
                }
            }
            $DB->delete_records(self::TABLE, ['id' => $ticket->id]);
        }
    }

    /**
     * Whether a submitted ticketdesign value is the schema-documented OFF sentinel.
     *
     * The contract knows exactly two off values: the empty string and the literal
     * 'none' (case-insensitive) — as documented in the option schema, which instructs
     * the model to send exactly these. Deliberately NO natural-language word lists
     * here: behavior must never derive from phrase matching, and a real design named
     * e.g. "No" or "Kein Foto" must stay resolvable as a design query.
     *
     * @param string $query
     *
     * @return bool
     */
    public static function is_design_off_sentinel(string $query): bool {
        $query = trim($query);

        return $query === '' || \core_text::strtolower($query) === 'none';
    }

    /**
     * The name of a ticket design, or an empty string if it no longer exists.
     *
     * @param int $templateid
     *
     * @return string
     */
    public static function get_template_name(int $templateid): string {
        global $DB;

        if (empty($templateid) || !$DB->get_manager()->table_exists('tool_certificate_templates')) {
            return '';
        }
        $name = $DB->get_field('tool_certificate_templates', 'name', ['id' => $templateid]);

        return $name === false ? '' : (string) $name;
    }

    /**
     * Resolve the active booking answer of a user for an option.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return int Answer id, or 0 if there is none.
     */
    private static function find_answerid(int $optionid, int $userid): int {
        global $DB;

        $answer = $DB->get_record_select(
            'booking_answers',
            'optionid = :optionid AND userid = :userid AND waitinglist < 2',
            ['optionid' => $optionid, 'userid' => $userid],
            'id',
            IGNORE_MULTIPLE
        );

        return empty($answer) ? 0 : (int) $answer->id;
    }
}
