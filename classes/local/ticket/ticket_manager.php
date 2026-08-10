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

    /** @var string Booking option JSON key: 1 if the door scanner must confirm the holder's identity. */
    public const JSON_CONFIRMIDENTITY = 'ticketconfirmidentity';

    /** @var string Booking option JSON key holding additional free text printed on the ticket. */
    public const JSON_EXTRAINFO = 'ticketextrainfo';

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

        // Never create a second valid ticket for the same user + option.
        if ($existing = self::find_valid_ticket($optionid, $userid)) {
            return $existing;
        }

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
