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
 * Shared cross-context targeting for activity-instance-scoped skills.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\dto\target_selector;

/**
 * Skills that act on a single activity instance of one module type (e.g. a booking instance) share
 * one targeting contract: name the module via {@see self::get_target_modname()} and let the engine's
 * generic module_target_resolver pick the concrete instance. No family writes its own cmid
 * resolution — that replaces the old per-skill resolve_cmid_from_context_or_cmid heuristics.
 *
 * The selector is ALWAYS non-null (carrying the modname), even when the user named no specific
 * activity: an "empty" module selector is the signal to auto-resolve the unique instance in scope —
 * the ambient course first, then site-wide — and to ask only when genuinely ambiguous.
 *
 * Implementing skills MUST declare {@see self::get_target_modname()} and bind their native
 * capability (Gate 2) to the PASSED operating context, per the opt-in safety rule in
 * base_skill::supports_target_context().
 */
trait module_targeted_skill {
    /**
     * This skill can run against an activity instance resolved from the input / ambient scope.
     *
     * @return bool
     */
    public function supports_target_context(): bool {
        return true;
    }

    /**
     * A module target names a CONTEXT_MODULE.
     *
     * @return int
     */
    public function get_target_context_level(): int {
        return CONTEXT_MODULE;
    }

    /**
     * The module name whose instances this skill targets, e.g. 'booking'.
     *
     * @return string
     */
    abstract public function get_target_modname(): string;

    /**
     * Build the module-target selector from an explicit cmid or an activity-name query.
     *
     * Always returns a selector carrying the modname so the engine resolves the operating context
     * (auto-pick when neither cmid nor query is given).
     *
     * @param array $input
     * @return target_selector|null
     */
    public function get_target_selector(array $input): ?target_selector {
        $cmid = (int)($input['cmid'] ?? 0);
        $activityquery = trim((string)($input['activityquery'] ?? ''));
        return target_selector::for_module(
            $cmid > 0 ? $cmid : null,
            $activityquery !== '' ? $activityquery : null,
            $this->get_target_modname()
        );
    }
}
