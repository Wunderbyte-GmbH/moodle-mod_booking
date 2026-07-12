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

use mod_booking\local\certificateclass;
use mod_booking\singleton_service;
use stdClass;
use tool_certificate\template;

/**
 * Entry-ticket manager ("SofaTicket").
 *
 * A ticket is a tool_certificate issue created from a single global master template at booking time.
 * Cancellation is a *soft-cancel*: the issue row is kept (data retained for verifiability) but marked
 * invalid, because tool_certificate's revoke_issue() destructively deletes the row and its PDF.
 *
 * Traceability of check-ins runs exclusively via the booking presence status
 * (MOD_BOOKING_PRESENCE_STATUS_CHECKEDIN) plus the Moodle logstore (ticket_scanned event) — no extra table.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ticket_manager {
    /** @var string Component stored on ticket issues, distinguishing them from completion certificates. */
    public const COMPONENT = 'mod_booking';

    /** @var string Issue data JSON key holding the booking option id a ticket belongs to. */
    public const DATA_OPTIONID = 'bookingticketoptionid';

    /** @var string Issue data JSON key holding the booked user id. */
    public const DATA_USERID = 'bookingticketuserid';

    /** @var string Issue data JSON key holding the cancellation timestamp (0/absent = still valid). */
    public const DATA_CANCELLEDTIME = 'bookingticketcancelledtime';

    /** @var string Marker flag in issue data JSON identifying an entry ticket. */
    public const DATA_ISTICKET = 'bookingticket';

    /**
     * Whether the entry-ticket feature is globally enabled and configured.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        if (!class_exists('tool_certificate\\template')) {
            return false;
        }
        if (empty(get_config('booking', 'bookingticketon'))) {
            return false;
        }
        return !empty(self::get_template_id());
    }

    /**
     * The configured global master ticket template id, if the template still exists.
     *
     * @return int
     */
    public static function get_template_id(): int {
        global $DB;
        $templateid = (int) get_config('booking', 'bookingtickettemplateid');
        if (empty($templateid)) {
            return 0;
        }
        if (!$DB->record_exists('tool_certificate_templates', ['id' => $templateid])) {
            return 0;
        }
        return $templateid;
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
     * Issue a ticket for a user on a booking option. Idempotent: if an active (non-cancelled) ticket
     * already exists for the user + option it is returned unchanged and no second issue is created.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return int The issue id, or 0 if nothing was issued (feature off / no template).
     */
    public static function issue_ticket(int $optionid, int $userid): int {
        global $DB;

        if (!$optionid || !$userid || !self::is_enabled()) {
            return 0;
        }

        // Idempotency: never create a second active ticket for the same user + option.
        if ($existing = self::find_active_issue($optionid, $userid)) {
            return (int) $existing->id;
        }

        $templateid = self::get_template_id();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings->id)) {
            return 0;
        }

        $template = template::instance($templateid);

        $data = certificateclass::build_certificate_data($settings, $userid, time(), null);
        $data[self::DATA_ISTICKET] = 1;
        $data[self::DATA_OPTIONID] = $optionid;
        $data[self::DATA_USERID] = $userid;

        // Reused by tool_certificate placeholders during PDF generation.
        singleton_service::set_temp_values_for_certificates($settings->id, $userid, 0);
        // Delegate to tool_certificate: it issues the record, generates the PDF and sends the notification (PDF attached).
        $issueid = (int) $template->issue_certificate(
            $userid,
            null,
            $data,
            self::COMPONENT,
            empty($settings->courseid) ? null : $settings->courseid
        );
        singleton_service::unset_temp_values_for_certificates();

        return $issueid;
    }

    /**
     * Soft-cancel every active ticket a user holds for an option (self-cancel, admin cancel, deletion).
     *
     * Keeps the issue row and its data (spec requirement) but marks it invalid everywhere:
     * stores a cancellation timestamp, archives it, and pushes the expiry into the past so even the
     * stock tool_certificate verification page reports it as invalid. Idempotent and never throws when
     * there is no active ticket (e.g. bookings made before the feature was rolled out).
     *
     * @param int $optionid
     * @param int $userid
     * @param int|null $cancelledtime Defaults to now.
     *
     * @return int Number of tickets cancelled.
     */
    public static function cancel_ticket(int $optionid, int $userid, ?int $cancelledtime = null): int {
        global $DB;

        $cancelledtime = $cancelledtime ?? time();
        $count = 0;
        foreach (self::find_active_issues($optionid, $userid) as $issue) {
            $data = json_decode($issue->data ?? '{}', true);
            if (!is_array($data)) {
                $data = [];
            }
            $data[self::DATA_CANCELLEDTIME] = $cancelledtime;

            $update = (object) [
                'id' => $issue->id,
                'data' => json_encode($data),
                'archived' => 1,
                'expires' => $cancelledtime,
            ];
            $DB->update_record('tool_certificate_issues', $update);
            $count++;
        }
        return $count;
    }

    /**
     * Find the first active (non-cancelled) ticket issue for a user + option, or null.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return stdClass|null
     */
    public static function find_active_issue(int $optionid, int $userid): ?stdClass {
        $active = self::find_active_issues($optionid, $userid);
        return $active ? reset($active) : null;
    }

    /**
     * Find all active (non-cancelled) ticket issues for a user + option.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return stdClass[] Keyed by issue id.
     */
    public static function find_active_issues(int $optionid, int $userid): array {
        $result = [];
        foreach (self::find_all_issues($optionid, $userid) as $issue) {
            if (!self::is_cancelled($issue)) {
                $result[$issue->id] = $issue;
            }
        }
        return $result;
    }

    /**
     * Find all ticket issues (active or cancelled) for a user + option.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return stdClass[] Keyed by issue id.
     */
    public static function find_all_issues(int $optionid, int $userid): array {
        global $DB;
        // Guard so cancellation/lookup never fatals when tool_certificate is absent (or later uninstalled).
        if (!class_exists('tool_certificate\\template')) {
            return [];
        }
        $records = $DB->get_records('tool_certificate_issues', [
            'userid' => $userid,
            'component' => self::COMPONENT,
        ]);
        $result = [];
        foreach ($records as $record) {
            $data = json_decode($record->data ?? '{}');
            if ((int) ($data->{self::DATA_OPTIONID} ?? 0) === $optionid) {
                $result[$record->id] = $record;
            }
        }
        return $result;
    }

    /**
     * Look up a ticket issue by its verification code.
     *
     * @param string $code
     *
     * @return stdClass|null The tool_certificate_issues record, or null if not found.
     */
    public static function find_issue_by_code(string $code): ?stdClass {
        global $DB;
        if ($code === '') {
            return null;
        }
        $issue = $DB->get_record('tool_certificate_issues', ['code' => $code]);
        return $issue ?: null;
    }

    /**
     * Whether a ticket issue has been cancelled (soft-cancel marker present).
     *
     * @param stdClass $issue A tool_certificate_issues record.
     *
     * @return bool
     */
    public static function is_cancelled(stdClass $issue): bool {
        $data = json_decode($issue->data ?? '{}');
        if (!empty($data->{self::DATA_CANCELLEDTIME})) {
            return true;
        }
        // Archived tickets are considered cancelled/invalid even without the explicit marker.
        return !empty($issue->archived);
    }

    /**
     * The cancellation timestamp of a ticket issue, or 0 if it is not cancelled.
     *
     * @param stdClass $issue A tool_certificate_issues record.
     *
     * @return int
     */
    public static function get_cancelledtime(stdClass $issue): int {
        $data = json_decode($issue->data ?? '{}');
        return (int) ($data->{self::DATA_CANCELLEDTIME} ?? 0);
    }

    /**
     * Whether an issue is a SofaTicket entry ticket (issued by this feature).
     *
     * @param stdClass $issue A tool_certificate_issues record.
     *
     * @return bool
     */
    public static function is_ticket(stdClass $issue): bool {
        if (($issue->component ?? '') !== self::COMPONENT) {
            return false;
        }
        $data = json_decode($issue->data ?? '{}');
        return !empty($data->{self::DATA_ISTICKET});
    }

    /**
     * Resolve the booking option id a ticket issue belongs to.
     *
     * @param stdClass $issue A tool_certificate_issues record.
     *
     * @return int
     */
    public static function get_optionid(stdClass $issue): int {
        $data = json_decode($issue->data ?? '{}');
        return (int) ($data->{self::DATA_OPTIONID} ?? 0);
    }
}
