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
 * Agent authorization service implementation.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services\security;

use context;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\interfaces\agent_authorization_service;
use moodle_exception;
use required_capability_exception;

/**
 * Handles authorization checks for the AI agent feature.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorization_service implements agent_authorization_service {
    /**
     * Frankenstyle component of THIS engine plugin.
     *
     * The wizard generator rewrites this literal together with every other component token, which
     * is what makes the coexistence logic below symmetric: compare against PRIMARY_ENGINE instead
     * of hardcoding one side of the relationship.
     *
     * @var string
     */
    private const ENGINE_COMPONENT = 'bookingextension_agent';

    /**
     * Engine component that takes precedence when both engine plugins are installed.
     *
     * This literal is the SAME in both engines on purpose (the generator maps component tokens,
     * and local_wizard maps onto itself): the standalone plugin always outranks the bundled
     * subplugin, so the generated copy never defers while the bundled engine steps aside.
     *
     * @var string
     */
    private const PRIMARY_ENGINE = 'local_wizard';

    /**
     * Return true when this engine plugin is installed and upgraded.
     *
     * @return bool
     */
    public static function is_agent_extension_installed(): bool {
        return self::plugin_is_installed(self::ENGINE_COMPONENT);
    }

    /**
     * Return true when a higher-ranked engine plugin owns the agent on this site.
     *
     * The primary engine (see PRIMARY_ENGINE) is the extracted, standalone home of this AI
     * engine. When this code runs inside a lower-ranked engine and the primary one is present,
     * the lower-ranked engine deliberately steps aside (single source of truth, no double UI /
     * double webservice handling). The check is dynamic (presence + upgraded), so the cutover is
     * fully reversible: remove/disable the primary engine and the bundled engine resumes. Inside
     * the primary engine itself this is a compile-time false — it never defers to anyone.
     *
     * @return bool
     */
    public static function primary_engine_takes_over(): bool {
        if (self::ENGINE_COMPONENT === self::PRIMARY_ENGINE) {
            return false;
        }
        return self::plugin_is_installed(self::PRIMARY_ENGINE);
    }

    /**
     * Return true when THIS engine is the active agent for the site.
     *
     * The single coexistence chokepoint: this engine is active only when it is installed AND no
     * higher-ranked engine has taken over. Routing every entry point (panel readiness, all
     * webservice gates, the navbar head-hook) through here means the engine yields everywhere
     * with one switch when the primary engine is installed — and resumes everywhere when it is
     * removed (reversible cutover).
     *
     * @return bool
     */
    public static function is_agent_engine_active(): bool {
        return self::is_agent_extension_installed() && !self::primary_engine_takes_over();
    }

    /**
     * Frankenstyle component of the engine that backs the alias layer on this site.
     *
     * The single home of the precedence rule: the primary engine (local_wizard) when it
     * has taken over, otherwise this engine. engine_resolver and every consumer resolve
     * the active engine through here, so the rule is never duplicated per component.
     *
     * @return string
     */
    public static function active_engine_component(): string {
        return self::primary_engine_takes_over() ? self::PRIMARY_ENGINE : self::ENGINE_COMPONENT;
    }

    /**
     * Return true when the given plugin is installed and upgraded.
     *
     * @param string $component Frankenstyle component name.
     * @return bool
     */
    private static function plugin_is_installed(string $component): bool {
        if (!class_exists('\\core_plugin_manager')) {
            return false;
        }
        try {
            $plugininfo = \core_plugin_manager::instance()->get_plugin_info($component);
            return ($plugininfo !== null) && (bool)$plugininfo->is_installed_and_upgraded();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Resolve and validate the agent context by id.
     *
     * Context-level-agnostic: accepts every context level the agent can be hosted at.
     * CONTEXT_USER matters for the global navbar entry point — the dashboard (/my/)
     * runs in the user's own user context. CONTEXT_COURSECAT covers category pages.
     * A booking module context still validates exactly as before, so existing
     * behaviour is unchanged. Only CONTEXT_BLOCK stays excluded (no sensible host).
     *
     * @param int $contextid
     * @return context
     */
    private function resolve_valid_context(int $contextid): context {
        $context = context::instance_by_id($contextid, MUST_EXIST);
        $allowedlevels = [CONTEXT_MODULE, CONTEXT_COURSE, CONTEXT_COURSECAT, CONTEXT_USER, CONTEXT_SYSTEM];
        if (!in_array((int)$context->contextlevel, $allowedlevels, true)) {
            throw new moodle_exception('invalidcontext');
        }
        return $context;
    }

    /**
     * Assert that the given user may use the AI instructions feature for this context.
     *
     * @param int $userid
     * @param int $contextid
     * @return void
     */
    public function require_use_capability(int $userid, int $contextid): void {
        $context = $this->resolve_valid_context($contextid);
        if (!self::is_agent_engine_active()) {
            throw new required_capability_exception($context, 'bookingextension/agent:useaiinstructions', 'nopermissions', '');
        }
        if (!has_capability('bookingextension/agent:useaiinstructions', $context, $userid)) {
            throw new required_capability_exception($context, 'bookingextension/agent:useaiinstructions', 'nopermissions', '');
        }
    }

    /**
     * Return true if the user has permission to use AI instructions.
     *
     * @param int $userid
     * @param int $contextid
     * @return bool
     */
    public function can_use(int $userid, int $contextid): bool {
        return $this->check_use_readiness($userid, $contextid) === null;
    }

    /**
     * Graceful readiness check for the AI webservice entry points: never throws.
     *
     * Distinguishes three failure states so callers can present a clean, accurate message instead of a raw
     * exception: the agent being unavailable (not installed / mid-upgrade) is NOT reported as a permission
     * problem. Returns null when ready.
     *
     * @param int $userid
     * @param int $contextid
     * @return array{code:string,message:string}|null
     */
    public function check_use_readiness(int $userid, int $contextid): ?array {
        if (!self::is_agent_engine_active()) {
            return [
                'code' => 'agent_unavailable',
                'message' => get_string('error_ai_unavailable', 'bookingextension_agent'),
            ];
        }
        try {
            $context = $this->resolve_valid_context($contextid);
        } catch (\Throwable $e) {
            return [
                'code' => 'context_invalid',
                'message' => get_string('error_ai_context_invalid', 'bookingextension_agent'),
            ];
        }
        if (!has_capability('bookingextension/agent:useaiinstructions', $context, $userid)) {
            return [
                'code' => 'permission_denied',
                'message' => get_string('error_ai_permission_denied', 'bookingextension_agent'),
            ];
        }
        return null;
    }

    /**
     * Assert that the context is a valid agent context (module, course or system).
     *
     * @param int $contextid
     * @return void
     */
    public function require_valid_context(int $contextid): void {
        $this->resolve_valid_context($contextid);
    }

    /**
     * Assert that the user holds a native Moodle capability at the given (operating) context.
     *
     * Gate 2 for the runtime context switch — re-checks the user's own right at the resolved
     * operating context. Pure authorization, never an escalation.
     *
     * @param int $userid
     * @param \context $operatingcontext
     * @param string $capability
     * @return void
     */
    public function require_capability_at(int $userid, \context $operatingcontext, string $capability): void {
        require_capability($capability, $operatingcontext, $userid);
    }
}
