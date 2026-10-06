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
 * External service: activate trial AI context for course and module.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use context_module;
use context_system;
use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Activate trial context by enabling AI at course and module level.
 */
class activate_trial_context extends external_api {
    /**
     * Describe input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
        ]);
    }

    /**
     * Enable AI for the related course and module.
     *
     * @param int $contextid
     * @return array
     */
    public static function execute(int $contextid): array {
        global $DB, $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
        ]);

        $authz = new authorization_service();
        if ($problem = $authz->check_use_readiness((int)$USER->id, (int)$params['contextid'])) {
            return ['success' => false, 'message' => $problem['message']];
        }
        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
        // Only module contexts carry a cmid; other context levels pass 0.
        $cmid = ($context instanceof context_module) ? (int)$context->instanceid : 0;
        self::validate_context($context);
        // Managers may onboard too; admins pass via moodle/site:doanything.
        require_capability('bookingextension/agent:requesttrial', context_system::instance());

        if (!class_exists('\\core_ai\\manager')) {
            return [
                'success' => false,
                'message' => get_string('aitrial_coreai_unavailable', 'bookingextension_agent'),
            ];
        }

        // The activation promise ("should we set up the system now?") includes
        // re-enabling a configured-but-disabled Wunderbyte provider instance.
        // Previously this endpoint only flipped course/module toggles and told
        // the admin "no active provider" for the disabled-instance case.
        try {
            // Endpoint-based detection (no provider name heuristic): enable every instance whose
            // action endpoint actually targets the Wunderbyte LLM gateway, including disabled ones.
            // provider_compat::enable_provider_view enables the instance on 5.x and the plugin on 4.5.
            $candidates = \bookingextension_agent\local\wizard\services\agent_access_service::find_wunderbyte_llm_instances(false);
            foreach ($candidates as $instance) {
                if (empty($instance->enabled)) {
                    \bookingextension_agent\local\wizard\services\provider_compat::enable_provider_view($instance);
                }
            }
        } catch (\Throwable $e) {
            debugging('trial activation: enabling provider instance failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        $registry = skill_registry::make_default();
        $store = new conversation_store();
        $status = (new orchestrator($registry, new interpreter($registry), $store))
            ->get_runtime_provider_status($contextid);

        if (empty($status['provideractive'])) {
            return [
                'success' => false,
                'message' => get_string('aiready_check_provider_active_todo', 'bookingextension_agent'),
            ];
        }

        if ($cmid > 0) {
            // Any module type: the AI toggles are core fields, not booking-specific.
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            $DB->set_field('course', 'enableaitools', 1, ['id' => (int)$cm->course]);
            $DB->set_field('course_modules', 'enableaitools', 1, ['id' => $cmid]);
            rebuild_course_cache((int)$cm->course, true);
        } else {
            // Outside a module (navbar overlay): enable the enclosing course toggle
            // if there is one; dashboard/system contexts have no toggle to flip.
            $coursecontext = $context->get_course_context(false);
            if ($coursecontext) {
                $DB->set_field('course', 'enableaitools', 1, ['id' => (int)$coursecontext->instanceid]);
                rebuild_course_cache((int)$coursecontext->instanceid, true);
            }
        }

        return [
            'success' => true,
            'message' => get_string('aitrial_activate_success', 'bookingextension_agent'),
        ];
    }

    /**
     * Describe return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Activation status.'),
            'message' => new external_value(PARAM_RAW, 'User-facing status message.'),
        ]);
    }
}
