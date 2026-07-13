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
 * Cross-context targeting for skills that act on a single booking option.
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
 * Target contract for booking-option-scoped mutating skills (trainer, update, book users, …).
 *
 * A booking option unambiguously identifies its booking activity, so a skill can name the option
 * (optionid, or an optionquery) and the engine can resolve the *operating context* — the booking
 * activity the command acts on — from that alone, with no ambient module context. This is what
 * lets the same command work from a booking activity page, from the dashboard, and over MCP
 * (which always runs at the system context).
 *
 * The counterpart for activity-scoped skills is the generic engine trait
 * {@see \mod_booking\local\wizard\engine\module_targeted_skill} (targets by cmid / activityquery);
 * this one targets by option and derives the cmid via {@see booking_skill_support}.
 *
 * Resolution strategy (in get_target_selector):
 *   - explicit optionid            → deterministic cmid ({@see booking_skill_support::cmid_for_option()});
 *   - optionquery, unique activity → that cmid ({@see booking_skill_support::activity_for_option_query()});
 *   - optionquery, several/none    → null (stay ambient). The skill's preflight then either resolves
 *     in the ambient activity, or — at a non-module context — surfaces an option-aware clarification
 *     ({@see booking_skill_base}); it never claims a missing permission.
 *
 * OPT-IN SAFETY RULE (base_skill::supports_target_context): a skill using this trait MUST bind its
 * native capability (Gate 2) to the PASSED operating context. Every booking option skill does so by
 * declaring its native capability in the constructor (3rd argument), which the engine's
 * native_capability_guard enforces at the resolved operating context — never at a hardwired ambient
 * cmid. Do not use this trait on a skill that only relies on the ambient governance capability.
 */
trait option_targeted_skill {
    /**
     * This skill can run against a booking activity resolved from the named option.
     *
     * @return bool
     */
    public function supports_target_context(): bool {
        return true;
    }

    /**
     * An option target ultimately names a CONTEXT_MODULE (its booking activity).
     *
     * @return int
     */
    public function get_target_context_level(): int {
        return CONTEXT_MODULE;
    }

    /**
     * Derive the operating-context selector for the booking activity that owns the named option.
     *
     * @param array $input The command input (optionid and/or optionquery, optional optionwhen).
     * @return target_selector|null A module selector carrying the resolved cmid, or null to stay ambient.
     */
    public function get_target_selector(array $input): ?target_selector {
        // Explicit option id(s) — a single option, or a bulk set — pin the activity deterministically.
        $optionids = [];
        if ((int)($input['optionid'] ?? 0) > 0) {
            $optionids[] = (int)$input['optionid'];
        }
        foreach ((array)($input['optionids'] ?? []) as $id) {
            if ((int)$id > 0) {
                $optionids[] = (int)$id;
            }
        }
        if (!empty($optionids)) {
            $cmids = [];
            foreach (array_unique($optionids) as $id) {
                $cmid = booking_skill_support::cmid_for_option($id);
                if ($cmid > 0) {
                    $cmids[$cmid] = true;
                }
            }
            // The named options must all live in one activity to pin a single operating context.
            return count($cmids) === 1
                ? target_selector::for_module((int)array_key_first($cmids), null, 'booking')
                : null;
        }

        // An option named by text resolves to its activity site-wide when it is unique.
        $optionquery = trim(self::scalar_string($input['optionquery'] ?? ''));
        if ($optionquery !== '') {
            $resolved = booking_skill_support::activity_for_option_query(
                $optionquery,
                trim((string)($input['optionwhen'] ?? ''))
            );
            if (($resolved['status'] ?? '') === 'ok') {
                return target_selector::for_module((int)$resolved['cmid'], null, 'booking');
            }
        }

        return null;
    }
}
