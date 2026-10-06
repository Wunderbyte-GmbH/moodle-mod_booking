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

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\wb_action_names;
use core\context;
use core\di;
use core_ai\manager as ai_manager;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;

/**
 * Resolves the agent's runtime provider/availability status for a context.
 *
 * Extracted verbatim from orchestrator::get_runtime_provider_status (orchestrator
 * split, seam "provider status") — pure provider/availability resolution, not part
 * of the planner prompt path. Behaviour is unchanged; the orchestrator keeps a thin
 * delegating method so existing callers (aiready / ai_send_message /
 * activate_trial_context) are unaffected.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider_status_service {
    /** Wunderbyte planner action class (custom, not placement-backed in core). */
    private const WB_ACTION_PLANNER_DECIDE = wb_action_names::PLANNER_DECIDE;

    /** Wunderbyte final-reply action class (custom, not placement-backed in core). */
    private const WB_ACTION_GENERATE_AGENT_REPLY = wb_action_names::GENERATE_AGENT_REPLY;

    /** @var orchestrator_routing_service */
    private orchestrator_routing_service $routingsvc;

    /**
     * Constructor.
     *
     * @param orchestrator_routing_service $routingsvc the same routing service the orchestrator uses
     */
    public function __construct(orchestrator_routing_service $routingsvc) {
        $this->routingsvc = $routingsvc;
    }

    /**
     * Compute the runtime provider/availability status for a context.
     *
     * @param int $contextid
     * @return array
     */
    public function get_status(int $contextid): array {
        $default = [
            'providerconfigured' => false,
            'provideractive' => false,
            'courseenabled' => false,
            'contextenabled' => false,
            'availabilitybypassed' => false,
            'runtimeavailable' => false,
            'toolactionclass' => '',
            'finalactionclass' => '',
            'toolroutepolicy' => 'default',
            'finalroutepolicy' => 'default',
            'failurereason' => '',
        ];

        if (!class_exists('\core_ai\manager')) {
            $default['failurereason'] = 'subsystem_missing';
            return $default;
        }

        try {
            $context = context::instance_by_id($contextid, MUST_EXIST);
            $manager = di::get(ai_manager::class);

            // Version-agnostic provider list: real instances on 5.x, synthesised views on 4.5.
            $providerinstances = provider_compat::get_provider_views();
            $providerconfigured = !empty($providerinstances);

            $hasenabledproviderinstance = false;
            foreach ($providerinstances as $instance) {
                if (!empty($instance->enabled)) {
                    $hasenabledproviderinstance = true;
                    break;
                }
            }

            $provideractive = $hasenabledproviderinstance;
            $candidateactions = [
                generate_text::class,
                summarise_text::class,
                explain_text::class,
                self::WB_ACTION_PLANNER_DECIDE,
                wb_action_names::PLANNER_CONSTRUCT,
                self::WB_ACTION_GENERATE_AGENT_REPLY,
            ];
            foreach ($candidateactions as $candidate) {
                if (!class_exists($candidate)) {
                    continue;
                }
                try {
                    $actionavailable = $manager->is_action_available($candidate);
                } catch (\Throwable $e) {
                    $actionavailable = false;
                }
                if ($actionavailable) {
                    $provideractive = true;
                    break;
                }
            }

            // AVAILABILITY layer (not a permission): the course/module "enableaitools"
            // toggles restrict non-privileged users only. Holders of the
            // ignoreaiavailability capability — site admins implicitly, managers by
            // default — bypass both toggles. Checked for the CURRENT user ($USER):
            // this status is always computed inside a user-facing request (aiready,
            // ai_send_message, activate_trial_context).
            // See docs/Blueprints/agent_permissions_concept_2026-06-10.md §2/§7.
            $availabilitybypassed = has_capability('bookingextension/agent:ignoreaiavailability', $context);

            // The core course-level AI toggle only exists within a course. Resolve the
            // enclosing course context first: core's is_ai_tools_enabled_in_course()
            // treats any non-course context's instanceid as a cmid, which silently
            // breaks for user/system contexts (e.g. the dashboard). No enclosing
            // course → no course toggle applies.
            $coursecontext = $context->get_course_context(false);
            $courseenabled = ($coursecontext && !$availabilitybypassed && method_exists($manager, 'is_ai_tools_enabled_in_course'))
                ? ai_manager::is_ai_tools_enabled_in_course($coursecontext)
                : true;

            $moduleaienabled = true;
            if (
                $context->contextlevel === CONTEXT_MODULE
                && !$availabilitybypassed
                // Missing on Moodle 5.0 cores — no API means the toggle counts as enabled (#2328).
                && method_exists($manager, 'get_ai_fields_from_course_module')
            ) {
                // Cast: $context->instanceid is a string; get_ai_fields_from_course_module() wants int,
                // and this service runs under declare(strict_types=1) (the orchestrator did not, so the
                // string was silently coerced there).
                $moduleaifields = ai_manager::get_ai_fields_from_course_module((int)$context->instanceid);
                $moduleaienabled = is_null($moduleaifields->enableaitools)
                    || (bool)$moduleaifields->enableaitools;
            }

            $toolrouting = $this->routingsvc->resolve_action_class_for_phase(
                $manager,
                $context,
                orchestrator_routing_service::PHASE_DISCOVERY
            );
            $toolactionclass = (string)($toolrouting['actionclass'] ?? '');
            $finalactionclass = self::WB_ACTION_GENERATE_AGENT_REPLY;

            $toolroutepolicy = (string)($toolrouting['routepolicy'] ?? 'default');
            $finalroutepolicy = 'cons_wunderbyte';

            $wunderbyteroutingselected =
                $this->routingsvc->is_wunderbyte_routepolicy($toolroutepolicy)
                && $this->routingsvc->is_wunderbyte_routepolicy($finalroutepolicy);

            $toolenabledincontext = false;
            if ($toolactionclass !== '') {
                if ($wunderbyteroutingselected) {
                    // Explicit override for wunderbyte custom actions: they are not
                    // placement-backed in core, so do not block on module action flags.
                    $toolenabledincontext = true;
                } else if ($this->routingsvc->is_wunderbyte_routepolicy($toolroutepolicy)) {
                    // Defensive fallback when only one side is tagged as wunderbyte.
                    $toolenabledincontext = $moduleaienabled;
                } else {
                    $toolenabledincontext = $this->routingsvc->is_action_available_in_context(
                        $manager,
                        $context,
                        $toolactionclass
                    );
                }
            }

            $finalenabledincontext = false;
            if ($finalactionclass !== '') {
                if ($wunderbyteroutingselected) {
                    // Explicit override for wunderbyte custom actions: they are not
                    // placement-backed in core, so do not block on module action flags.
                    $finalenabledincontext = true;
                } else if ($this->routingsvc->is_wunderbyte_routepolicy($finalroutepolicy)) {
                    // Defensive fallback when only one side is tagged as wunderbyte.
                    $finalenabledincontext = $moduleaienabled;
                } else {
                    $finalenabledincontext = $this->routingsvc->is_action_available_in_context(
                        $manager,
                        $context,
                        $finalactionclass
                    );
                }
            }

            $contextenabled = $toolenabledincontext && $finalenabledincontext;
            $runtimeavailable = $provideractive && $courseenabled && $contextenabled;

            $failurereason = '';
            if (!$runtimeavailable) {
                if (!$providerconfigured) {
                    $failurereason = 'no_provider';
                } else if (!$provideractive) {
                    $failurereason = 'provider_inactive';
                } else if ($toolactionclass === '' || $finalactionclass === '') {
                    $failurereason = 'actions_missing';
                } else if (!$courseenabled) {
                    $failurereason = 'course_disabled';
                } else if (!$contextenabled) {
                    $failurereason = 'context_disabled';
                }
            }

            return [
                'providerconfigured' => $providerconfigured,
                'provideractive' => $provideractive,
                'courseenabled' => $courseenabled,
                'contextenabled' => $contextenabled,
                'availabilitybypassed' => $availabilitybypassed,
                'runtimeavailable' => $runtimeavailable,
                'toolactionclass' => $toolactionclass,
                'finalactionclass' => $finalactionclass,
                'toolroutepolicy' => $toolroutepolicy,
                'finalroutepolicy' => $finalroutepolicy,
                'failurereason' => $failurereason,
            ];
        } catch (\Throwable $e) {
            $default['failurereason'] = 'exception_thrown';
            return $default;
        }
    }
}
