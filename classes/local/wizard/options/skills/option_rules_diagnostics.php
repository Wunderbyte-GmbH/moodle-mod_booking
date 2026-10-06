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

use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules\rule_react_on_event;
use mod_booking\option\fields\applybookingrules;
use mod_booking\singleton_service;

/**
 * Data-only diagnostics for the per-option booking-rule restriction.
 *
 * A booking option can limit which booking rules are applied to it (option form, "Rules" header):
 * skipbookingrulesmode = 0 applies every rule EXCEPT the listed ones (opt out), mode = 1 applies
 * ONLY the listed ones (opt in). An opt-in with an empty or incomplete list silently switches off
 * the mail-sending rules of the surrounding contexts, which is a frequent cause of "I booked but
 * got no confirmation mail".
 *
 * A rule that does apply can still be out of time: "react on event" rules stop applying a set number
 * of days after the end of the option (aftercompletion, 1 day in every rule template), and the mail
 * task then skips them without a trace outside the cron log.
 *
 * Everything here is derived from stored configuration (option JSON, rule JSON action name,
 * isactive/useastemplate flags, option end time) — never from user or LLM wording.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_rules_diagnostics {
    /** Rule action that sends an e-mail to the selected recipients. */
    private const MAIL_ACTION = 'send_mail';

    /** The only rule type whose mails stop after the option has ended. */
    private const TIME_WINDOW_RULE = 'rule_react_on_event';

    /**
     * Events that mark "this person now holds a booking".
     *
     * A rule reacting to one of these is what produces a booking confirmation mail, so switching it
     * off answers "I booked but got no confirmation" directly. Every other skipped mail rule (a
     * reminder n days before, a cancellation notice, an evaluation mail) is context, not the cause.
     * These are event class names from the rule configuration — structure, not wording.
     *
     * @var string[]
     */
    private const BOOKING_CONFIRMATION_EVENTS = [
        '\\mod_booking\\event\\bookingoption_booked',
        '\\mod_booking\\event\\bookingoption_bookedviaautoenrol',
        '\\mod_booking\\event\\bookinganswer_confirmed',
        '\\mod_booking\\event\\bookinganswer_movedupfromwaitinglist',
        '\\mod_booking\\event\\bookinganswer_slotbooked',
        '\\mod_booking\\event\\bookinganswer_waitingforconfirmation',
    ];

    /** How many rule names a single user-facing sentence may list before it is summarized. */
    private const MAX_LISTED_RULE_NAMES = 5;

    /**
     * Describe which booking rules apply to one option and which the option skips.
     *
     * The time window of a confirmation rule is checked at $confirmationtime, the moment the
     * person's booking answer was written, because that is when the confirmation went out. Without
     * a booking it is checked now: would a booking made now still be confirmed? Every other mail
     * rule fires later (reminders, cancellations, completion), so its window is checked now.
     *
     * @param int $optionid
     * @param int $confirmationtime Time of the person's booking answer, 0 when there is none.
     * @return array{
     *     mode:int,
     *     restrictionactive:bool,
     *     applied:array<int,array>,
     *     skipped:array<int,array>,
     *     skippedmailrules:array<int,array>,
     *     skippedconfirmationrules:array<int,array>,
     *     appliedmailrulecount:int,
     *     appliedconfirmationrulecount:int,
     *     confirmationcheckedatbooking:bool,
     *     expiredconfirmationrules:array<int,array>,
     *     expiredothermailrules:array<int,array>
     * }
     */
    public static function describe(int $optionid, int $confirmationtime = 0): array {
        $empty = [
            'mode' => 0,
            'restrictionactive' => false,
            'applied' => [],
            'skipped' => [],
            'skippedmailrules' => [],
            'skippedconfirmationrules' => [],
            'appliedmailrulecount' => 0,
            'appliedconfirmationrulecount' => 0,
            'confirmationcheckedatbooking' => $confirmationtime > 0,
            'expiredconfirmationrules' => [],
            'expiredothermailrules' => [],
        ];
        if ($optionid <= 0) {
            return $empty;
        }

        $mode = (int)\mod_booking\booking_option::get_value_of_json_by_key($optionid, 'skipbookingrulesmode');
        $listed = \mod_booking\booking_option::get_value_of_json_by_key($optionid, 'skipbookingrules');
        $listed = is_array($listed) ? $listed : [];

        // Opt in restricts as soon as it is switched on (an empty list then means "no rule at all"),
        // opt out only restricts when at least one rule is actually excluded.
        $restrictionactive = ($mode === 1) || !empty($listed);

        $applied = [];
        $skipped = [];
        $skippedmailrules = [];
        $skippedconfirmationrules = [];
        $appliedmailrulecount = 0;
        $appliedconfirmationrulecount = 0;
        $expiredconfirmationrules = [];
        $expiredothermailrules = [];
        $now = time();
        $confirmationat = $confirmationtime > 0 ? $confirmationtime : $now;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        foreach (booking_rules::get_list_of_saved_rules_by_optionid($optionid) as $rule) {
            $entry = self::describe_rule($rule, $settings);
            if ($entry === null) {
                continue;
            }
            // Only an active mail rule could have produced a mail, so only those can explain a
            // missing one. An inactive or non-mail rule is listed, but never blamed.
            $couldhavemailed = $entry['sendsmail'] && $entry['isactive'];

            if (applybookingrules::apply_rule($optionid, (int)$rule->id)) {
                $applied[] = $entry;
                if ($couldhavemailed) {
                    $appliedmailrulecount++;
                    if ($entry['confirmsbooking']) {
                        $appliedconfirmationrulecount++;
                    }
                    // Only an applied rule can be out of time; a skipped one is blamed on the restriction.
                    $windowend = $entry['windowend'];
                    $checkedat = $entry['confirmsbooking'] ? $confirmationat : $now;
                    if ($windowend !== null && $windowend <= $checkedat) {
                        if ($entry['confirmsbooking']) {
                            $expiredconfirmationrules[] = $entry;
                        } else {
                            $expiredothermailrules[] = $entry;
                        }
                    }
                }
                continue;
            }

            $skipped[] = $entry;
            if ($couldhavemailed) {
                $skippedmailrules[] = $entry;
                if ($entry['confirmsbooking']) {
                    $skippedconfirmationrules[] = $entry;
                }
            }
        }

        return [
            'mode' => $mode,
            'restrictionactive' => $restrictionactive,
            'applied' => $applied,
            'skipped' => $skipped,
            'skippedmailrules' => $skippedmailrules,
            'skippedconfirmationrules' => $skippedconfirmationrules,
            'appliedmailrulecount' => $appliedmailrulecount,
            'appliedconfirmationrulecount' => $appliedconfirmationrulecount,
            'confirmationcheckedatbooking' => $confirmationtime > 0,
            'expiredconfirmationrules' => $expiredconfirmationrules,
            'expiredothermailrules' => $expiredothermailrules,
        ];
    }

    /**
     * Reduce one stored rule record to the facts the diagnosis needs.
     *
     * @param \stdClass $rule Record of {booking_rules}.
     * @param object $settings Booking option settings of the diagnosed option.
     * @return array{id:int,contextid:int,name:string,rulename:string,eventname:string,isactive:bool,
     *         sendsmail:bool,confirmsbooking:bool,aftercompletion:int,windowend:?int}|null
     *         Null for rule templates, which never fire.
     */
    private static function describe_rule(\stdClass $rule, object $settings): ?array {
        if (!empty($rule->useastemplate)) {
            return null;
        }

        $ruleobject = json_decode((string)($rule->rulejson ?? ''));
        $name = trim((string)($ruleobject->name ?? ''));
        if ($name === '') {
            $name = trim((string)($rule->rulename ?? ''));
        }

        $eventname = trim((string)($rule->eventname ?? ''));
        $rulename = trim((string)($rule->rulename ?? ''));
        $windowend = ($rulename === self::TIME_WINDOW_RULE && is_object($ruleobject))
            ? rule_react_on_event::get_time_window_end($ruleobject, $settings)
            : null;

        return [
            'id' => (int)$rule->id,
            'contextid' => (int)($rule->contextid ?? 0),
            'name' => $name,
            'rulename' => $rulename,
            'eventname' => $eventname,
            'isactive' => !isset($rule->isactive) || !empty($rule->isactive),
            'sendsmail' => trim((string)($ruleobject->actionname ?? '')) === self::MAIL_ACTION,
            'confirmsbooking' => in_array(ltrim($eventname, '\\'), array_map(
                static fn(string $event): string => ltrim($event, '\\'),
                self::BOOKING_CONFIRMATION_EVENTS
            ), true),
            'aftercompletion' => $windowend === null ? 0 : (int)($ruleobject->ruledata->aftercompletion ?? 0),
            'windowend' => $windowend,
        ];
    }

    /**
     * Earliest end of the time window among the given rules, the moment the first of them stopped applying.
     *
     * @param array $rules Entries of {@see self::describe()}.
     * @return int Timestamp, 0 when none of the rules has a time window.
     */
    public static function earliest_window_end(array $rules): int {
        $ends = array_filter(array_map(static fn(array $rule): int => (int)($rule['windowend'] ?? 0), $rules));

        return empty($ends) ? 0 : min($ends);
    }

    /**
     * Date and time for a user-facing sentence, in the language of the conversation.
     *
     * @param int $timestamp
     * @param string $lang
     * @return string
     */
    public static function format_time(int $timestamp, string $lang = ''): string {
        $lang = trim($lang);
        if ($lang === '') {
            return userdate($timestamp, get_string('strftimedatetime', 'langconfig'));
        }

        $previous = force_current_language($lang);
        try {
            return userdate($timestamp, get_string('strftimedatetime', 'langconfig'));
        } finally {
            force_current_language($previous);
        }
    }

    /**
     * Comma-separated rule names for a user-facing sentence, capped so the line stays readable.
     *
     * @param array $rules Entries of {@see self::describe()}.
     * @param string $lang Language of the "and n more" suffix.
     * @return string
     */
    public static function rule_names(array $rules, string $lang = ''): string {
        $names = [];
        foreach ($rules as $rule) {
            $name = trim((string)($rule['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        $names = array_values(array_unique($names));

        $overflow = count($names) - self::MAX_LISTED_RULE_NAMES;
        if ($overflow > 0) {
            $names = array_slice($names, 0, self::MAX_LISTED_RULE_NAMES);
            $names[] = $lang === ''
                ? get_string('agent_booking_diagnose_rules_more', 'mod_booking', $overflow)
                : get_string_manager()->get_string(
                    'agent_booking_diagnose_rules_more',
                    'mod_booking',
                    $overflow,
                    $lang
                );
        }

        return implode(', ', $names);
    }
}
