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
 * Cross-context targeting for skills that act on a single booking rule.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\local\wizard\options\skills;

use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\target_selector;

/**
 * Target contract for booking-rule-scoped skills (update_rule_from_template, …).
 *
 * A booking rule is a CONTEXT-BOUND row (booking_rules.contextid) — frequently the SYSTEM
 * context, sometimes a booking activity. It therefore pins its own operating context the way
 * an option pins its activity: the skill can name the rule (ruleid or rulequery) and the
 * engine operates where the rule lives — no "which booking activity?" question, which for a
 * system-scoped rule is unanswerable by construction (thread 584: three identical activity
 * candidate lists for a rename request, with the user's answers never consumable).
 *
 * Resolution strategy (in get_target_selector):
 *   - named/id'd rule in a MODULE context     → that activity's cmid (module selector);
 *   - named/id'd rule at SYSTEM/course level  → null (stay ambient — the path-scoped rule
 *     listing at the ambient context reaches system rules from everywhere);
 *   - ambiguous or unknown rule               → null: the skill's preflight disambiguates by
 *     RULE ID via booking_rules_agent_service::resolve_rule() (never by activity).
 *
 * The executor's fail-closed module check is selector-aware: it only demands a module
 * operating context when this trait actually produced a module selector for the input.
 */
trait rule_targeted_skill {
    /**
     * This skill can run against the context resolved from the named rule.
     *
     * @return bool
     */
    public function supports_target_context(): bool {
        return true;
    }

    /**
     * When the rule lives in an activity, the target is that CONTEXT_MODULE.
     *
     * @return int
     */
    public function get_target_context_level(): int {
        return CONTEXT_MODULE;
    }

    /**
     * Derive the operating-context selector from the rule the input names.
     *
     * @param array $input The command input (ruleid and/or rulequery).
     * @return target_selector|null A module selector when the rule lives in an activity;
     *         null to stay ambient (system/course rules, unknown or ambiguous rules).
     */
    public function get_target_selector(array $input): ?target_selector {
        $contextid = booking_skill_support::context_for_rule(
            (int)($input['ruleid'] ?? 0),
            trim((string)($input['rulequery'] ?? ''))
        );
        if ($contextid <= 0) {
            return null;
        }

        $context = \context::instance_by_id($contextid, IGNORE_MISSING);
        if ($context instanceof \context_module) {
            return target_selector::for_module((int)$context->instanceid, null, 'booking');
        }

        // System- or course-scoped rule: no module target exists. The ambient context is
        // correct — booking_rules::get_list_of_saved_rules_by_context() walks the context
        // PATH, so system rules are reachable from every ambient context.
        return null;
    }
}
