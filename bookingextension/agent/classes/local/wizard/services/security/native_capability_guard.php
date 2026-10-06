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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\security;

use core\context;

/**
 * Central engine enforcement of a skill's native Moodle capabilities (Gate 2).
 *
 * The agent must never grant a right the user does not natively hold. Every skill declares the
 * capabilities of the core action it performs via base_skill::get_required_native_capabilities();
 * this guard checks them against the OPERATING context (the context the skill actually acts on —
 * the cross-context target when the skill opted in, otherwise the ambient context).
 *
 * It is enforced centrally — both in the preflight pipeline (clean denial, no guard token issued)
 * and again in the executor immediately before execute() (the authoritative backstop) — so a skill
 * that forgets its own check, checks the wrong context, or is reached by a crafted/replayed command
 * is still denied. The engine never trusts a skill to guard itself.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class native_capability_guard {
    /**
     * Native capabilities the user is MISSING for this skill at the operating context.
     *
     * Returns an empty array when the skill declares no native capabilities (read-only skills) or
     * the user holds all of them. A non-resolvable operating context is treated as "all missing"
     * (fail-closed) so the action is denied rather than run against an unknown context.
     *
     * @param object $skill The skill instance (must expose get_required_native_capabilities()).
     * @param int $operatingcontextid The resolved operating context id (target of the action).
     * @param int $userid The acting user.
     * @return string[] The capabilities the user lacks (empty = allowed).
     */
    public static function missing_capabilities(object $skill, int $operatingcontextid, int $userid): array {
        if (!method_exists($skill, 'get_required_native_capabilities')) {
            return [];
        }
        $required = array_values(array_filter(array_map(
            static fn($cap): string => trim((string)$cap),
            (array)$skill->get_required_native_capabilities()
        )));
        if (empty($required)) {
            return [];
        }

        try {
            $context = context::instance_by_id($operatingcontextid, MUST_EXIST);
        } catch (\Throwable $e) {
            // Fail closed: cannot resolve the target context -> deny everything declared.
            return $required;
        }

        $missing = [];
        foreach ($required as $capability) {
            if (!has_capability($capability, $context, $userid)) {
                $missing[] = $capability;
            }
        }
        return $missing;
    }
}
