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

use context;
use coding_exception;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\dto\target_selector;

/**
 * Resolves the operating context for a single skill operation (runtime context switch).
 *
 * A skill may declare that it needs a broader context level than the ambient context
 * the chat lives in (e.g. a question-generation skill running inside a booking module
 * needs the enclosing course to write into the course question bank). This service walks
 * the real Moodle context hierarchy upward to find the required level.
 *
 * IMPORTANT — this is NOT a privilege escalation. It only resolves WHICH context an
 * operation targets. The caller must still re-check the user's capability at the resolved
 * operating context (Gate 1: agent skill capability; Gate 2: the skill's native Moodle
 * capability via base_skill::require_native_capabilities()).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_resolver {
    /**
     * Resolve the operating context for a required context level.
     *
     * If the ambient context is already at or broader than the required level it is
     * returned unchanged; otherwise the nearest ancestor of the required level is used.
     *
     * @param agent_context $ambient The context the chat/thread lives in.
     * @param int $requiredlevel A Moodle CONTEXT_* level constant.
     * @return agent_context The operating context (may equal the ambient context).
     * @throws coding_exception When no ancestor of the required level exists.
     */
    public function resolve(agent_context $ambient, int $requiredlevel): agent_context {
        // Lower numeric context level = broader scope (SYSTEM=10, COURSE=50, MODULE=70).
        // If the ambient context is already broad enough, keep it.
        if ($ambient->level() <= $requiredlevel) {
            return $ambient;
        }

        $target = $this->find_ancestor_of_level($ambient->moodle_context(), $requiredlevel);
        if ($target === null) {
            throw new coding_exception(
                'context_resolver: no ancestor of the required context level was found for the ambient context.'
            );
        }

        return $ambient->with_context($target);
    }

    /**
     * Resolve the operating context, optionally against an explicit target (cross-context).
     *
     * With no target (or an empty one) this behaves exactly like {@see self::resolve()} — the
     * ambient context or its nearest ancestor of the required level (fully backward compatible).
     *
     * With an explicit target selector it resolves a DIFFERENT branch of the context tree (e.g.
     * another course) via the {@see operating_context_target_registry}. This is NOT a privilege
     * escalation: it only decides WHICH context the operation targets; the caller must still
     * enforce Gate 2 (require_capability) at the returned operating context. An ambiguous,
     * not-found or unsupported target raises {@see context_target_unresolved_exception} (carrying
     * the candidates) so the caller can ask for clarification — it never silently falls back to
     * the ambient context.
     *
     * @param agent_context        $ambient      The context the chat/thread lives in.
     * @param int                  $requiredlevel A Moodle CONTEXT_* level constant.
     * @param target_selector|null $target       Explicit target, or null for ambient/ancestor.
     * @param int                  $userid       Acting user id (visibility-aware target resolution).
     * @param operating_context_target_registry|null $registry Injectable for tests.
     * @return agent_context The operating context.
     * @throws context_target_unresolved_exception When an explicit target cannot be uniquely resolved.
     * @throws coding_exception When no ancestor of the required level exists (no-target path).
     */
    public function resolve_operating_context(
        agent_context $ambient,
        int $requiredlevel,
        ?target_selector $target = null,
        int $userid = 0,
        ?operating_context_target_registry $registry = null
    ): agent_context {
        // An empty COURSE selector means "no explicit target → ambient/ancestor". An empty MODULE
        // selector is still meaningful: the modname lets the registry auto-pick the unique instance
        // in scope, so it must NOT short-circuit to the (broader) ambient context.
        if ($target === null || ($target->is_empty() && !$target->is_module_target())) {
            return $this->resolve($ambient, $requiredlevel);
        }

        $registry = $registry ?? new operating_context_target_registry();
        $resolution = $registry->resolve($target, $userid, $ambient);
        if (!$resolution->is_resolved()) {
            throw new context_target_unresolved_exception($resolution);
        }

        return $ambient->with_context($resolution->context());
    }

    /**
     * Walk the context hierarchy upward to the first ancestor of the given level.
     *
     * @param context $context
     * @param int $requiredlevel
     * @return context|null
     */
    private function find_ancestor_of_level(context $context, int $requiredlevel): ?context {
        $candidate = $context;
        while ($candidate instanceof context) {
            if ((int)$candidate->contextlevel === $requiredlevel) {
                return $candidate;
            }
            $parent = $candidate->get_parent_context();
            $candidate = ($parent instanceof context) ? $parent : null;
        }
        return null;
    }
}
