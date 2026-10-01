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
 * The certifcate class handles logic related to issueing certificates.
 *
 * @package mod_booking
 * @author Magdalena Holczik
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use core_competency\competency;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\dates_handler;
use mod_booking\placeholders\placeholders\customfields;
use mod_booking\singleton_service;
use mod_booking\customfield\booking_handler;
use tool_certificate\template;
use tool_certificate\certificate as toolCertificate;
use stdClass;

/**
 * Certificate class for logic related to issueing certificates.
 */
class certificateclass {
    /**
     * Returns the certificate issues of a user for a booking option,
     * ordered by timecreated (oldest first).
     *
     * @param int $userid
     * @param int $optionid
     * @return array
     */
    public static function get_certificates_for_user_option(int $userid, int $optionid): array {
        global $DB;

        if (
            empty($userid)
            || empty($optionid)
            || !class_exists('tool_certificate\certificate')
        ) {
            return [];
        }

        $params = [
            'userid' => $userid,
            'optionid' => $optionid,
            // Entry tickets used to be stored as issues with component 'mod_booking'. They are not certificates
            // and must never show up in the certificate list of a user. See \mod_booking\local\ticket\ticket_manager.
            'component' => 'tool_certificate',
        ];

        switch ($DB->get_dbfamily()) {
            case 'postgres':
                $sql = "
                    SELECT id, code, expires, timecreated
                      FROM {tool_certificate_issues}
                     WHERE userid = :userid
                       AND component = :component
                       AND (data::jsonb ->> 'bookingoptionid') ~ '^[0-9]+$'
                       AND (data::jsonb ->> 'bookingoptionid')::int = :optionid
                     ORDER BY timecreated, id
                ";
                break;
            case 'mysql':
                $sql = "
                    SELECT id, code, expires, timecreated
                      FROM {tool_certificate_issues}
                     WHERE userid = :userid
                       AND component = :component
                       AND CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.bookingoptionid')) AS UNSIGNED) = :optionid
                     ORDER BY timecreated, id
                ";
                break;
            default:
                return [];
        }

        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Issue certificate.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $completeddate
     * @param int $templateid
     * @param int|null $expirydatetype
     * @param int|null $expirydateabsolute
     * @param int|null $expirydaterelative
     * @param stdClass|null $condition
     *
     * @return int
     *
     */
    public static function issue_certificate(
        int $optionid,
        int $userid,
        int $completeddate = 0,
        int $templateid = 0,
        ?int $expirydatetype = null,
        ?int $expirydateabsolute = null,
        ?int $expirydaterelative = null,
        ?stdClass $condition = null,
    ): int {
        global $DB;
        $id = 0;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (
            !class_exists('tool_certificate\certificate')
            || !get_config('booking', 'certificateon')
        ) {
            return $id;
        }
        // Without a condition this is the certificate stored on the booking option. In conditions mode only
        // certificate conditions issue certificates, a template saved on the option before the switch is ignored.
        if (empty($condition) && !self::option_certificate_applies()) {
            return $id;
        }
        // In per-option mode only the option certificate is issued, conditions saved before the switch are ignored.
        if (!empty($condition) && self::option_certificate_applies()) {
            return $id;
        }
        if (empty($templateid)) {
            $templateid = (int)(booking_option::get_value_of_json_by_key($optionid, 'certificate') ?? 0);
        }

        if (empty($templateid)) {
            return $id;
        }

        $template = template::instance($templateid);

        // Certificate expiry date key.
        if ($expirydatetype === null) {
            $expirydatetype = (int)(booking_option::get_value_of_json_by_key($optionid, 'expirydatetype') ?? 0);
        }
        if ($expirydateabsolute === null) {
            $expirydateabsolute = (int)(booking_option::get_value_of_json_by_key($optionid, 'expirydateabsolute') ?? 0);
        }
        if ($expirydaterelative === null) {
            $expirydaterelative = (int)(booking_option::get_value_of_json_by_key($optionid, 'expirydaterelative') ?? 0);
        }

        $certificateexpirydate = toolCertificate::calculate_expirydate($expirydatetype, $expirydateabsolute, $expirydaterelative);
        if (!empty($expirydatetype) && $certificateexpirydate < time()) {
            return $id;
        }
        // Create Certificate.
        $data = self::build_certificate_data($settings, $userid, $completeddate, $condition);
        singleton_service::set_temp_values_for_certificates($settings->id, $userid, $condition->id ?? 0);
        // Issue the certificate.
        $id = $template->issue_certificate(
            $userid,
            $certificateexpirydate,
            $data,
            'tool_certificate',
            empty($settings->courseid) ? null : $settings->courseid
        );
        // Get the issue and create the PDF.
        $issue = $DB->get_record('tool_certificate_issues', ['id' => $id]);
        $pdf = $template->create_issue_file($issue, false);
        singleton_service::unset_temp_values_for_certificates();

        // Get required options data for the event.
        $requiredoptionsdata = self::get_required_options_data($settings, $userid);

        // Trigger certificate issued event.
        $event = \mod_booking\event\certificate_issued::create([
            'context' => \context_module::instance($settings->cmid),
            'objectid' => $id,
            'relateduserid' => $userid,
            'other' => [
                'bookingoption_id' => $settings->id,
                'bookingoption_name' => $settings->get_title_with_prefix(),
                'required_options' => $requiredoptionsdata,
            ],
        ]);
        $event->trigger();

        return $id;
    }

    /**
     * Whether the certificate stored on a booking option (JSON key "certificate") is issued.
     *
     * The site setting "certificateoptions" selects how certificates are issued: 0 = per booking option,
     * 1 = by certificate conditions. Only the selected source issues certificates: an option certificate
     * saved before switching to conditions, or conditions saved before switching back, stay stored but
     * are not issued. When this returns false, certificate conditions apply instead.
     *
     * @return bool
     */
    public static function option_certificate_applies(): bool {
        return empty(get_config('booking', 'certificateoptions'));
    }

    /**
     * Build the data array (placeholders) passed to a tool_certificate issue for a booking option.
     *
     * Extracted so both the completion-coupled certificate flow and the entry-ticket flow
     * (see \mod_booking\local\ticket\ticket_manager) share exactly the same placeholder set.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     * @param int $completeddate
     * @param stdClass|null $condition
     *
     * @return array
     */
    public static function build_certificate_data(
        booking_option_settings $settings,
        int $userid,
        int $completeddate = 0,
        ?stdClass $condition = null
    ): array {
        $customfielddata = [];
        $customfields = booking_handler::get_customfields();
        foreach ($customfields as $customfield) {
            if (!in_array($customfield->type, ['text', 'textarea', 'textformat'])) {
                continue;
            }
            $placeholder = '{' . $customfield->shortname . '}';
            $params = [];
            $value = customfields::return_value(
                $settings->cmid,
                $settings->id,
                $userid,
                $placeholder,
                $params,
                $customfield->shortname
            );
            if (empty($value)) {
                $value = " ";
            }
            $customfielddata['cf' . $customfield->shortname] = $value;
        }
        $bookingoptionfields = [
            'bookingoptionid' => $settings->id,
            'bookingoptionname' => $settings->get_title_with_prefix(),
            'bookinginstancename' => singleton_service::get_instance_of_booking_settings_by_cmid($settings->cmid)->name,
            'bookingoptiondescription' => clean_text(
                $settings->description,
                $format = FORMAT_HTML,
                $options = ['strip_tags' => true]
            ),
            'location' => $settings->location,
            'institution' => $settings->institution,
            'teachers' => self::return_teachers_for_certificate($settings->teachers),
            'sessions' => self::return_sessions_for_certificate($settings->sessions),
            'daterange' => self::return_daterange_for_certificate($settings),
            'duration' => self::return_duration_for_certificate($settings),
            'dateswithduration' => self::return_dateswithduration_for_certificate($settings),
            'timeawarded' => self::return_timeawarded_for_certificate($settings, $userid, $completeddate),
            'competencies' => self::return_competencies_for_certificate($settings->competencies ?? ''),
            'bookingnotes' => self::return_notes_for_certificate($settings->id, $userid),
        ];
        if (!empty($condition)) {
            $conditionfields = [
                'conditionid' => $condition->id,
                'conditionname' => $condition->name,
            ];
        }

        return array_merge(
            $bookingoptionfields,
            $customfielddata,
            $conditionfields ?? []
        );
    }

    /**
     * Get required options data for the event.
     * Fetches all required booking options that need to be completed for certificate issuance.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     *
     * @return array Array of required options with their details
     *
     */
    private static function get_required_options_data(booking_option_settings $settings, int $userid): array {
        $requiredoptions = booking_option::get_value_of_json_by_key(
            $settings->id,
            'certificaterequiresotheroptions'
        ) ?? [];

        if (empty($requiredoptions)) {
            return [];
        }
        $ba1 = singleton_service::get_instance_of_booking_answers($settings);
        $iscompleted = $ba1->is_activity_completed($userid);
        $requiredoptionsdata = [];
        $requiredoptionsdata[$settings->id] = [
                'optionid' => $settings->id,
                'optionname' => $settings->get_title_with_prefix(),
                'completed' => $iscompleted,
        ];
        foreach ($requiredoptions as $requiredoptionid) {
            if (empty($requiredoptionid)) {
                continue;
            }

            $settingsotheroption = singleton_service::get_instance_of_booking_option_settings($requiredoptionid);
            $ba = singleton_service::get_instance_of_booking_answers($settingsotheroption);
            $iscompleted = $ba->is_activity_completed($userid);

            $requiredoptionsdata[$requiredoptionid] = [
                'optionid' => $requiredoptionid,
                'optionname' => $settingsotheroption->get_title_with_prefix(),
                'completed' => $iscompleted,
            ];
        }

        return $requiredoptionsdata;
    }

    /**
     * [Description for return_competency_for_certificate]
     *
     * @param string $competencies
     *
     * @return string
     *
     */
    private static function return_competencies_for_certificate(string $competencies) {

        if (empty($competencies)) {
            return '';
        }

        $competenciesarray = explode(',', $competencies);
        $collected = [];
        foreach ($competenciesarray as $competencid) {
            $competency = competency::get_record(['id' => (int) $competencid]);
            $collected[] = $competency->get('shortname');
        }
        $returnstring = implode(', ', $collected);
        return $returnstring;
    }
    /**
     * Helper function to return Teachers for certificate.
     *
     * @param array $teachers
     *
     * @return string
     *
     */
    private static function return_teachers_for_certificate(array $teachers) {
        $certificateteachers = [];
        foreach ($teachers as $teacher) {
            $certificateteachers[] = "$teacher->firstname $teacher->lastname";
        }
        return implode("<br />", $certificateteachers);
    }

    /**
     * Helper function to return Duration for certificate.
     *
     * @param object $settings
     *
     * @return string
     *
     */
    private static function return_duration_for_certificate(object $settings) {
        $duration = self::return_duration_seconds($settings);
        if (empty($duration)) {
            return '';
        }
        $hours = (string)floor($duration / 3600);
        $minutes = (string)floor(($duration % 3600) / 60);
        $a = new stdClass();
        $a->hours = $hours;
        $a->minutes = $minutes;
        return get_string('durationforcertificate', 'mod_booking', $a);
    }

    /**
     * Helper function returning the duration of a booking option in seconds,
     * summed over its sessions (booking_optiondates), falling back to the
     * option's coursestarttime/courseendtime span. The duration is not stored
     * anywhere, so it always has to be calculated.
     *
     * @param object $settings booking_option_settings
     *
     * @return int duration in seconds, 0 when there are no usable dates
     *
     */
    private static function return_duration_seconds(object $settings): int {
        if (!empty($settings->sessions)) {
            $duration = 0;
            foreach ($settings->sessions as $session) {
                $duration += ($session->courseendtime - $session->coursestarttime);
            }
            return $duration;
        }
        if (
            !empty($settings->courseendtime)
            && !empty($settings->coursestarttime)
            && $settings->courseendtime > $settings->coursestarttime
        ) {
            return $settings->courseendtime - $settings->coursestarttime;
        }
        return 0;
    }

    /**
     * Helper function to return a combined period field for the certificate:
     * the overall start date, the end date and the duration (summed from the
     * sessions) as decimal hours in brackets, e.g.
     * "20 August 2026 - 25 August 2026 (12,5 h)".
     *
     * The duration is not stored anywhere, it is calculated from the option's
     * sessions (booking_optiondates) - see return_duration_seconds().
     *
     * @param object $settings booking_option_settings
     *
     * @return string the combined period, or '' when there are no usable dates
     *
     */
    private static function return_dateswithduration_for_certificate(object $settings) {
        // Overall period: the option's start and end, falling back to the span of its sessions.
        $start = (int)($settings->coursestarttime ?? 0);
        $end = (int)($settings->courseendtime ?? 0);
        if ((empty($start) || empty($end)) && !empty($settings->sessions)) {
            $starts = array_column($settings->sessions, 'coursestarttime');
            $ends = array_column($settings->sessions, 'courseendtime');
            $start = $start ?: (!empty($starts) ? min($starts) : 0);
            $end = $end ?: (!empty($ends) ? max($ends) : 0);
        }

        if (empty($start) || empty($end)) {
            return '';
        }

        $timeformat = get_string('strftimedate', 'langconfig');
        $period = userdate($start, $timeformat) . ' - ' . userdate($end, $timeformat);

        // Append the calculated duration as decimal hours in brackets, e.g. "(12,5 h)".
        // format_float renders the localized decimal separator and strips trailing zeros.
        $seconds = self::return_duration_seconds($settings);
        if (!empty($seconds)) {
            $hours = format_float($seconds / 3600, 2, true, true);
            $period .= ' (' . $hours . ' h)';
        }

        return $period;
    }

    /**
     * Helper function to return Sessions for certificate.
     *
     * @param array $sessions
     *
     * @return string
     *
     */
    private static function return_sessions_for_certificate(array $sessions) {
        $dates = "";
        foreach ($sessions as $session) {
            $dates .= dates_handler::prettify_optiondates_start_end(
                $session->coursestarttime,
                $session->courseendtime,
                current_language(),
                false
            ) . "<br />";
        }
        return $dates;
    }

    /**
     * Helper function to return a single date range for the certificate.
     *
     * When the booking option has more than one session, this returns the date of the
     * very first session's start up to the date of the last session's end, e.g.
     * "12 May 2026 - 18 July 2026". With a single session (or a single day) it returns
     * that one date. This is a compact alternative to the full {sessions} list, exposed
     * to certificate templates as the {daterange} placeholder.
     *
     * @param booking_option_settings $settings booking option settings (with ->sessions and course start/end times)
     *
     * @return string
     */
    private static function return_daterange_for_certificate(booking_option_settings $settings): string {
        $starts = [];
        $ends = [];
        foreach ($settings->sessions ?? [] as $session) {
            if (!empty($session->coursestarttime)) {
                $starts[] = (int)$session->coursestarttime;
            }
            if (!empty($session->courseendtime)) {
                $ends[] = (int)$session->courseendtime;
            }
        }
        // Options without separate sessions fall back to their own start / end time.
        if (empty($starts) && !empty($settings->coursestarttime)) {
            $starts[] = (int)$settings->coursestarttime;
        }
        if (empty($ends) && !empty($settings->courseendtime)) {
            $ends[] = (int)$settings->courseendtime;
        }
        if (empty($starts)) {
            return '';
        }

        $firststart = min($starts);
        $lastend = !empty($ends) ? max($ends) : max($starts);

        $date = dates_handler::prettify_datetime($firststart, $lastend, current_language(), false);

        // Single day (or only one date available): show it once, without a range.
        if (empty($date->enddate) || $date->startdate === $date->enddate) {
            return $date->startdate;
        }
        return $date->startdate . ' - ' . $date->enddate;
    }

    /**
     * Helper function to return the notes ("Anmerkungen") of the booking answer of a user.
     *
     * The notes can be edited on report.php for every booked user. They are not part of the
     * cached booking answers, so we read them directly from the database.
     *
     * @param int $optionid
     * @param int $userid
     *
     * @return string
     *
     */
    private static function return_notes_for_certificate(int $optionid, int $userid): string {
        global $DB;

        if (empty($optionid) || empty($userid)) {
            return '';
        }

        $sql = "SELECT notes
                  FROM {booking_answers}
                 WHERE optionid = :optionid
                       AND userid = :userid
                       AND waitinglist <> :deleted
              ORDER BY id DESC";

        $params = [
            'optionid' => $optionid,
            'userid' => $userid,
            'deleted' => MOD_BOOKING_STATUSPARAM_DELETED,
        ];

        $notes = $DB->get_field_sql($sql, $params, IGNORE_MULTIPLE);

        return (string) ($notes ?? '');
    }

    /**
     * Helper function to return the time the certificate was awarded
     * @param booking_option_settings $settings
     * @param int $userid
     * @param int $completeddate
     *
     * @return string
     *
     */
    private static function return_timeawarded_for_certificate(
        booking_option_settings $settings,
        int $userid,
        int $completeddate
    ) {
        if (empty($completeddate)) {
            $ba = singleton_service::get_instance_of_booking_answers($settings);
            $users = $ba->get_usersonlist();
            if (!$answer = $users[$userid] ?? false) {
                return '';
            }
            $completeddate = $answer->completeddate ?? $answer->timemodified ?? time();
        }

        // The time awarded is currently the time modified. We might change that at one point.
        return userdate($completeddate, get_string('strftimedaydate'));
    }

    /**
     * Check if all required options are completed for certificate issuance.
     * If a certificate does not require other options, it will return true.
     * If there are required options, it checks if the user has completed them all.
     * There is no check if the current option is required in another option, if so,
     * the other option will use this check on completion.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     *
     * @return bool
     *
     */
    public static function required_options_fulfilled(booking_option_settings $settings, int $userid): bool {
        $requiredoptions = booking_option::get_value_of_json_by_key(
            $settings->id,
            'certificaterequiresotheroptions'
        ) ?? [];

        if (empty($requiredoptions)) {
            return true;
        }

        // Check the flag if one or all are options are required to complete.
        $mode = booking_option::get_value_of_json_by_key(
            $settings->id,
            'certificaterequiredoptionsmode'
        ) ?? 0;
        if (!empty($mode)) {
            return self::one_required_option_fulfilled($requiredoptions, $userid);
        }

        // Default: all required options must be completed.
        foreach ($requiredoptions as $requiredoptionid) {
            if (empty($requiredoptionid)) {
                continue;
            }
            $settingsotheroption = singleton_service::get_instance_of_booking_option_settings($requiredoptionid);
            $ba = singleton_service::get_instance_of_booking_answers($settingsotheroption);
            if (!$ba->is_activity_completed($userid)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if at least one required option is completed for certificate issuance.
     *
     * @param array $requiredoptions Array of required option IDs
     * @param int $userid
     *
     * @return bool
     *
     */
    public static function one_required_option_fulfilled(array $requiredoptions, int $userid): bool {
        foreach ($requiredoptions as $requiredoptionid) {
            if (empty($requiredoptionid)) {
                continue;
            }
            $settingsotheroption = singleton_service::get_instance_of_booking_option_settings($requiredoptionid);
            $ba = singleton_service::get_instance_of_booking_answers($settingsotheroption);
            if ($ba->is_activity_completed($userid)) {
                return true;
            }
        }
        return false;
    }
}
