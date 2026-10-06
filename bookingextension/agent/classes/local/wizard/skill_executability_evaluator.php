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
 * Read-only skill executability evaluator.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\services\security\authorization_service;
use context;

/**
 * Central evaluator for skill executability and deny diagnostics.
 */
class skill_executability_evaluator {
    /** @var skill_registry */
    private skill_registry $registry;

    /** @var authorization_service */
    private authorization_service $authz;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param authorization_service $authz
     */
    public function __construct(skill_registry $registry, authorization_service $authz) {
        $this->registry = $registry;
        $this->authz = $authz;
    }

    /**
     * Evaluate one skill for user and context.
     *
     * @param string $skillname
     * @param int $userid
     * @param int $contextid
     * @return array
     */
    public function evaluate_skill(string $skillname, int $userid, int $contextid): array {
        $skillname = trim($skillname);
        $meta = $this->registry->get_skill_contract($skillname);

        if ($meta === null || $this->registry->get_skill($skillname) === null) {
            return $this->deny_result($skillname, skill_contract_validator::DENY_NOT_REGISTERED, [
                'registered' => false,
            ]);
        }

        if (!authorization_service::is_agent_extension_installed()) {
            return $this->deny_result($skillname, skill_contract_validator::DENY_RUNTIME_DISABLED, [
                'registered' => true,
            ]);
        }

        if (!$this->registry->is_skill_active($skillname)) {
            return $this->deny_result($skillname, skill_contract_validator::DENY_INACTIVE, [
                'active' => false,
            ]);
        }

        // Full-access gate: the PRO lock applies ONLY to the write skills of Wunderbyte's own
        // gated components. Read-only skills (any component) and every third-party write skill
        // stay executable without a PRO license or the Wunderbyte LLM subscription.
        if (
            services\agent_access_service::skill_requires_full_access(
                !empty($meta['readonly']),
                (string)($meta['component'] ?? '')
            )
            && !services\agent_access_service::has_full_access()
        ) {
            return $this->deny_result($skillname, skill_contract_validator::DENY_REQUIRES_PRO, [
                'readonly' => false,
                'requires_full_access' => true,
            ]);
        }

        if (!$this->has_required_capabilities($userid, $contextid, $skillname)) {
            return $this->deny_result($skillname, skill_contract_validator::DENY_MISSING_CAPABILITY, [
                'required_capabilities' => $this->registry->get_skill_capabilities($skillname),
            ]);
        }

        if (!$this->is_valid_context($contextid)) {
            return $this->deny_result($skillname, skill_contract_validator::DENY_CONTEXT_INVALID, [
                'contextid' => $contextid,
            ]);
        }

        return [
            'skillname' => $skillname,
            'executable_state' => 'allow',
            'deny_reason' => '',
            'diagnostics' => [
                'registered' => true,
                'active' => true,
                'required_capabilities' => $this->registry->get_skill_capabilities($skillname),
                'readonly' => (bool)($meta['readonly'] ?? false),
            ],
        ];
    }

    /**
     * Evaluate all registered skills for user and context.
     *
     * @param int $userid
     * @param int $contextid
     * @return array
     */
    public function evaluate_all_skills(int $userid, int $contextid): array {
        $results = [];

        foreach ($this->registry->get_skill_names() as $skillname) {
            $results[$skillname] = $this->evaluate_skill($skillname, $userid, $contextid);
        }

        ksort($results);
        return $results;
    }

    /**
     * Return executable skill names only.
     *
     * @param int $userid
     * @param int $contextid
     * @return string[]
     */
    public function get_executable_skill_names(int $userid, int $contextid): array {
        $skillnames = [];

        foreach ($this->evaluate_all_skills($userid, $contextid) as $skillname => $evaluation) {
            if ((string)($evaluation['executable_state'] ?? '') === 'allow') {
                $skillnames[] = $skillname;
            }
        }

        return $skillnames;
    }

    /**
     * Build a standardized deny result payload.
     *
     * @param string $skillname
     * @param string $reason
     * @param array $diagnostics
     * @return array
     */
    private function deny_result(string $skillname, string $reason, array $diagnostics = []): array {
        return [
            'skillname' => $skillname,
            'executable_state' => 'deny',
            'deny_reason' => $reason,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Check skill-specific capabilities for user/context.
     *
     * @param int $userid
     * @param int $contextid
     * @param string $skillname
     * @return bool
     */
    private function has_required_capabilities(int $userid, int $contextid, string $skillname): bool {
        // The name-derived capability (<component>:skill_<normalized_name>) is ALWAYS required and is
        // derived HERE by the engine from the skill name — not taken on trust from declared metadata.
        // This makes the per-skill capability check impossible to bypass: a (3rd-party) skill can
        // never ship without its name capability being enforced, even if its metadata declares none.
        $meta = (array)($this->registry->get_skill_contract($skillname) ?? []);
        $namecapability = skill_contract_validator::build_skill_capability_name(
            (string)($meta['component'] ?? ''),
            $skillname
        );
        if ($namecapability === '') {
            // No derivable name capability (missing component) → fail closed.
            return false;
        }

        $capabilities = $this->registry->get_skill_capabilities($skillname);
        if (!in_array($namecapability, $capabilities, true)) {
            $capabilities[] = $namecapability;
        }

        try {
            $context = context::instance_by_id($contextid, MUST_EXIST);
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($capabilities as $capability) {
            $capability = trim((string)$capability);
            if ($capability === '' || !get_capability_info($capability)) {
                return false;
            }
            if (!has_capability($capability, $context, $userid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether context is valid for booking execution.
     *
     * @param int $contextid
     * @return bool
     */
    private function is_valid_context(int $contextid): bool {
        try {
            $this->authz->require_valid_context($contextid);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
