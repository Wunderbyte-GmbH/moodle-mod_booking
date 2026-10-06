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

namespace mod_booking\local\wizard\options\skills;

use mod_booking\local\wizard\booking\booking_skill_support;
use moodle_url;

/**
 * Turns the booking diagnosis into checklist rows for the shared diagnostic preview.
 *
 * One row per thing that can stand between a person and a booking (or its confirmation mail), each
 * carrying a status the renderer maps to a glyph: 'ok' (checked and fine), 'fail' (this blocks) and
 * 'warn' (worth knowing, not decisive). Rows are plain data; the engine's diagnostic checklist
 * renderer turns them into the same markup the other diagnose skills produce.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnose_checklist_builder {
    /**
     * Build the checklist rows.
     *
     * @param array $context {
     * @var int    cmid
     * @var int    optionid
     * @var string userstatus
     * @var int    invisiblevalue
     * @var array  instancechecks    Instance-level context from the skill.
     * @var array  stats             Effective booking statistics of the option.
     * @var array  conditions        Descriptions of currently blocking availability conditions.
     * @var array  notificationrules Payload of option_rules_diagnostics::describe().
     * }
     * @param string $lang Output language of the conversation.
     * @return array<int,array{status:string,check:string,finding:string,url:?string}>
     */
    public static function build(array $context, string $lang = ''): array {
        $rows = [];
        $instance = (array)($context['instancechecks'] ?? []);
        $cmid = (int)($context['cmid'] ?? 0);
        $optionid = (int)($context['optionid'] ?? 0);
        $optionurl = ($cmid > 0 && $optionid > 0)
            ? booking_skill_support::build_option_link_for_output($cmid, $optionid)
            : null;

        $rows[] = self::enrolment_row($instance, $lang);
        $rows[] = self::activity_row($instance, $lang);
        $rows[] = self::option_visibility_row((int)($context['invisiblevalue'] ?? 0), $lang, $optionurl);
        $rows[] = self::booking_enabled_row($instance, $lang);
        $rows[] = self::maxperuser_row($instance, $lang);
        $rows[] = self::banned_row($instance, $lang);
        $rows[] = self::status_row((string)($context['userstatus'] ?? ''), $lang, $optionurl);
        $rows[] = self::capacity_row((array)($context['stats'] ?? []), $lang);
        $rows[] = self::conditions_row((array)($context['conditions'] ?? []), $lang);
        $rows[] = self::rules_row((array)($context['notificationrules'] ?? []), $lang, $optionurl);
        $rows[] = self::rule_time_window_row((array)($context['notificationrules'] ?? []), $lang);

        return array_values(array_filter($rows));
    }

    /**
     * Whether the person is enrolled in the course holding the booking activity.
     *
     * @param array $instance
     * @param string $lang
     * @return array
     */
    private static function enrolment_row(array $instance, string $lang): array {
        $enrolled = !isset($instance['isenrolled']) || !empty($instance['isenrolled']);
        $courseid = (int)($instance['courseid'] ?? 0);

        return self::row(
            $enrolled ? 'ok' : 'fail',
            'enrolment',
            $enrolled ? 'enrolment_ok' : 'enrolment_fail',
            null,
            $lang,
            $courseid > 0 ? new moodle_url('/course/view.php', ['id' => $courseid]) : null
        );
    }

    /**
     * Whether the booking activity itself is available to the person.
     *
     * @param array $instance
     * @param string $lang
     * @return array
     */
    private static function activity_row(array $instance, string $lang): array {
        $visible = !isset($instance['activityuservisible']) || !empty($instance['activityuservisible']);
        $info = trim((string)($instance['activityavailableinfo'] ?? ''));

        if ($visible) {
            return self::row('ok', 'activityvisible', 'activityvisible_ok', null, $lang);
        }

        return $info !== ''
            ? self::row('fail', 'activityvisible', 'activityvisible_fail_info', $info, $lang)
            : self::row('fail', 'activityvisible', 'activityvisible_fail', null, $lang);
    }

    /**
     * Whether the option itself is visible.
     *
     * @param int $invisible 0 = visible, 1 = hidden, 2 = not listed but reachable.
     * @param string $lang
     * @param ?string $url
     * @return array
     */
    private static function option_visibility_row(int $invisible, string $lang, ?string $url): array {
        if ($invisible === 1) {
            return self::row('fail', 'optionvisible', 'optionvisible_hidden', null, $lang, $url);
        }
        if ($invisible === 2) {
            return self::row('warn', 'optionvisible', 'optionvisible_notinlist', null, $lang, $url);
        }

        return self::row('ok', 'optionvisible', 'optionvisible_ok', null, $lang);
    }

    /**
     * Whether booking is switched on for the whole activity.
     *
     * @param array $instance
     * @param string $lang
     * @return array
     */
    private static function booking_enabled_row(array $instance, string $lang): array {
        $disabled = !empty($instance['instancedisablebooking']);

        return self::row(
            $disabled ? 'fail' : 'ok',
            'bookingenabled',
            $disabled ? 'bookingenabled_fail' : 'bookingenabled_ok',
            null,
            $lang
        );
    }

    /**
     * Whether the per-user booking limit still leaves room.
     *
     * @param array $instance
     * @param string $lang
     * @return array
     */
    private static function maxperuser_row(array $instance, string $lang): array {
        $max = (int)($instance['maxperuser'] ?? 0);
        if ($max <= 0) {
            return self::row('ok', 'maxperuser', 'maxperuser_none', null, $lang);
        }

        $current = (int)($instance['userbookingcount'] ?? 0);
        $a = (object)['max' => $max, 'current' => $current];

        return $current >= $max
            ? self::row('fail', 'maxperuser', 'maxperuser_fail', $a, $lang)
            : self::row('ok', 'maxperuser', 'maxperuser_ok', $a, $lang);
    }

    /**
     * Whether the username is on the activity's blocked list.
     *
     * @param array $instance
     * @param string $lang
     * @return array
     */
    private static function banned_row(array $instance, string $lang): array {
        $banned = !empty($instance['userisbannedfrominstance']);

        return self::row(
            $banned ? 'fail' : 'ok',
            'banned',
            $banned ? 'banned_fail' : 'banned_ok',
            null,
            $lang
        );
    }

    /**
     * The current booking status of the person for this option.
     *
     * @param string $userstatus
     * @param string $lang
     * @param ?string $url
     * @return array
     */
    private static function status_row(string $userstatus, string $lang, ?string $url): array {
        $map = [
            'booked' => ['ok', 'status_booked'],
            'waitinglist' => ['warn', 'status_waitinglist'],
            'reserved' => ['warn', 'status_reserved'],
            'notifylist' => ['warn', 'status_notifylist'],
        ];
        [$status, $key] = $map[$userstatus] ?? ['fail', 'status_notbooked'];

        return self::row($status, 'status', $key, null, $lang, $url);
    }

    /**
     * Whether the option still has places (or a usable waiting list).
     *
     * @param array $stats
     * @param string $lang
     * @return array
     */
    private static function capacity_row(array $stats, string $lang): array {
        if (empty($stats['fullybooked'])) {
            return self::row('ok', 'capacity', 'capacity_ok', null, $lang);
        }
        if (!empty($stats['waitinglistfull'])) {
            return self::row('fail', 'capacity', 'capacity_waitinglist_full', null, $lang);
        }
        if ((int)($stats['maxoverbooking'] ?? 0) > 0) {
            return self::row('warn', 'capacity', 'capacity_waitinglist_available', null, $lang);
        }

        return self::row('fail', 'capacity', 'capacity_full', null, $lang);
    }

    /**
     * Whether any availability condition currently blocks the booking.
     *
     * @param array $conditions
     * @param string $lang
     * @return array
     */
    private static function conditions_row(array $conditions, string $lang): array {
        if (empty($conditions)) {
            return self::row('ok', 'conditions', 'conditions_ok', null, $lang);
        }

        $row = self::row('fail', 'conditions', 'conditions_fail', count($conditions), $lang);
        // The condition texts are the concrete finding; the count alone would help nobody.
        $row['finding'] .= ' ' . implode(' ', $conditions);

        return $row;
    }

    /**
     * Whether the booking rules of the surrounding contexts actually apply to this option.
     *
     * This is the row that answers "why did no confirmation mail go out although I am booked".
     *
     * @param array $rules Payload of option_rules_diagnostics::describe().
     * @param string $lang
     * @param ?string $url
     * @return array
     */
    private static function rules_row(array $rules, string $lang, ?string $url): array {
        $applied = count((array)($rules['applied'] ?? []));
        $skipped = count((array)($rules['skipped'] ?? []));
        $confirmationoff = count((array)($rules['skippedconfirmationrules'] ?? []));

        if (empty($rules['restrictionactive'])) {
            return self::row('ok', 'rules', 'rules_ok', $applied, $lang);
        }

        $a = (object)[
            'applied' => $applied,
            'total' => $applied + $skipped,
            'names' => option_rules_diagnostics::rule_names(
                (array)($rules['skippedconfirmationrules'] ?? []),
                $lang
            ),
        ];

        return $confirmationoff > 0
            ? self::row('fail', 'rules', 'rules_confirmation_off', $a, $lang, $url)
            : self::row('warn', 'rules', 'rules_restricted', $a, $lang, $url);
    }

    /**
     * Whether the applied mail rules are still inside their time window after the option end.
     *
     * A "react on event" rule stops applying a set number of days after the end of the option, and
     * the mail task then skips it silently. A confirmation rule out of time is the cause of a missing
     * confirmation; any other mail rule out of time is only worth a warning.
     *
     * @param array $rules Payload of option_rules_diagnostics::describe().
     * @param string $lang
     * @return array
     */
    private static function rule_time_window_row(array $rules, string $lang): array {
        $expiredconfirmation = (array)($rules['expiredconfirmationrules'] ?? []);
        $expiredother = (array)($rules['expiredothermailrules'] ?? []);

        if (!empty($expiredconfirmation)) {
            $a = (object)[
                'names' => option_rules_diagnostics::rule_names($expiredconfirmation, $lang),
                'date' => option_rules_diagnostics::format_time(
                    option_rules_diagnostics::earliest_window_end($expiredconfirmation),
                    $lang
                ),
            ];
            $contextid = (int)($expiredconfirmation[0]['contextid'] ?? 0);

            return self::row(
                'fail',
                'ruletimewindow',
                empty($rules['confirmationcheckedatbooking'])
                    ? 'ruletimewindow_confirmation_expired_now'
                    : 'ruletimewindow_confirmation_expired',
                $a,
                $lang,
                $contextid > 0 ? new moodle_url('/mod/booking/edit_rules.php', ['contextid' => $contextid]) : null
            );
        }

        if (!empty($expiredother)) {
            return self::row('warn', 'ruletimewindow', 'ruletimewindow_other_expired', count($expiredother), $lang);
        }

        return self::row('ok', 'ruletimewindow', 'ruletimewindow_ok', null, $lang);
    }

    /**
     * Assemble one checklist row.
     *
     * @param string $status ok | fail | warn
     * @param string $checkkey Suffix of the check label string.
     * @param string $findingkey Suffix of the finding string.
     * @param mixed $a Placeholder payload for the finding string.
     * @param string $lang
     * @param string|moodle_url|null $url
     * @return array{status:string,check:string,finding:string,url:?string}
     */
    private static function row(
        string $status,
        string $checkkey,
        string $findingkey,
        $a,
        string $lang,
        $url = null
    ): array {
        if ($url instanceof moodle_url) {
            $url = $url->out(false);
        }

        return [
            'status' => $status,
            'check' => self::str('agent_booking_diagnose_check_' . $checkkey, null, $lang),
            'finding' => self::str('agent_booking_diagnose_finding_' . $findingkey, $a, $lang),
            'url' => is_string($url) && trim($url) !== '' ? trim($url) : null,
        ];
    }

    /**
     * Localized string bound to the conversation language.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    private static function str(string $identifier, $a, string $lang): string {
        $lang = trim($lang);
        if ($lang === '') {
            return get_string($identifier, 'mod_booking', $a);
        }

        return get_string_manager()->get_string($identifier, 'mod_booking', $a, $lang);
    }
}
