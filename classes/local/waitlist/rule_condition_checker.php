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
 * Reads the existing rule_react_on_event/send_mail_interval rule configuration
 * (WAITLIST_REFACTOR_ARCHITECTURE_2026-08-12.md §3.3/§6, K11) to decide which waitlist-progression
 * rule(s) are currently allowed to fire for an option. Multiple active rules per booking instance
 * are supported (e.g. two different intervals/conditions) - the existing DB schema already
 * assumes this, so this class does too rather than silently narrowing it to one.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\waitlist;

use mod_booking\booking_rules\rules\rule_react_on_event;
use mod_booking\singleton_service;
use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_rules\booking_rules;


/**
 * K11: resolves which send_mail_interval rules on an option's booking instance currently satisfy
 * their "Execute when..." condition.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rule_condition_checker {
    /**
     * Returns the ids of all active send_mail_interval rules, on the booking instance that owns
     * $optionid, whose "Execute when..." condition is currently met. Empty array if none.
     *
     * @param int $optionid
     * @return int[] rule ids, ascending
     */
    public function applicable_rules(int $optionid): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        if (empty($settings)) {
            return [];
        }

        $context = \context_module::instance($settings->cmid);
        $records = booking_rules::get_list_of_saved_rules_by_context(
            $context->id,
            '\\mod_booking\\event\\bookingoption_freetobookagain'
        );

        $answers = new booking_answers($settings);
        $isfullybooked = $answers->is_fully_booked();
        $isfullwaitinglist = $answers->is_fully_booked_on_waitinglist();

        $ruleids = [];
        foreach ($records as $record) {
            if (empty($record->isactive)) {
                continue;
            }
            $rulejson = json_decode($record->rulejson);
            if (empty($rulejson) || ($rulejson->actionname ?? '') !== 'send_mail_interval') {
                continue;
            }
            $condition = (int) ($rulejson->ruledata->condition ?? rule_react_on_event::ALWAYS);
            if ($this->condition_met($condition, $isfullybooked, $isfullwaitinglist)) {
                $ruleids[] = (int) $record->id;
            }
        }

        sort($ruleids);
        return $ruleids;
    }

    /**
     * Evaluates one of the 5 rule_react_on_event condition values (see that class's
     * check_if_rule_still_applies() for the original logic this mirrors).
     *
     * @param int $condition one of rule_react_on_event::ALWAYS/FULLYBOOKED/NOTFULLYBOOKED/
     *                        FULLWAITINGLIST/NOTFULLWAITINGLIST
     * @param bool $isfullybooked
     * @param bool $isfullwaitinglist
     * @return bool
     */
    private function condition_met(int $condition, bool $isfullybooked, bool $isfullwaitinglist): bool {
        switch ($condition) {
            case rule_react_on_event::FULLYBOOKED:
                return $isfullybooked;
            case rule_react_on_event::NOTFULLYBOOKED:
                return !$isfullybooked;
            case rule_react_on_event::FULLWAITINGLIST:
                return $isfullwaitinglist;
            case rule_react_on_event::NOTFULLWAITINGLIST:
                return !$isfullwaitinglist;
            case rule_react_on_event::ALWAYS:
            default:
                return true;
        }
    }
}
