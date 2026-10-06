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
 * AI readiness helper for booking AI instructions.
 *
 * @package     bookingextension_agent
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard;

use context_system;
use core\di;
use core_ai\manager as ai_manager;
use bookingextension_agent\local\wizard\dto\agent_context;
use bookingextension_agent\local\wizard\services\agent_access_service;
use bookingextension_agent\local\wizard\services\provider_compat;
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Central readiness state for the booking AI panel.
 */
class aiready {
    /** @var agent_context */
    private agent_context $ctx;

    /** @var int */
    private int $userid;

    /**
     * Constructor.
     *
     * Context-agnostic: works for module, course and system contexts. Booking-specific
     * extras (module config URL, AI toggle fallback, statistics) only apply when the
     * context is a booking module; all other contexts get neutral values.
     *
     * @param int $contextid
     * @param int $userid
     */
    public function __construct(int $contextid, int $userid) {
        $this->ctx = agent_context::from_contextid($contextid);
        $this->userid = $userid;
    }

    /**
     * Export readiness and chat config for mustache/JS.
     *
     * @return array
     */
    public function export_for_template(): array {
        global $CFG;

        $context = $this->ctx->moodle_context();
        $authz = new authorization_service();

        if (!authorization_service::is_agent_engine_active()) {
            return [
                'readyforchat' => false,
                'isplatformadmin' => has_capability('moodle/site:config', context_system::instance(), $this->userid),
                'threadid' => 0,
                'checks' => [],
                'introtext' => '',
                'admintext' => '',
                'nonadmintext' => '',
                'initialpanelhidden' => true,
                'chatpanelhidden' => true,
                'cmid' => $this->ctx->cmid() ?? 0,
            ];
        }

        $isplatformadmin = has_capability('moodle/site:config', context_system::instance(), $this->userid);
        $hascapability = $authz->can_use($this->userid, (int)$context->id);

        $providersconfigured = false;
        $haswunderbyteprovider = false;
        $provideractive = false;
        $sourceprovidername = '';
        $existingwunderbyteaccess = false;
        $wbinstanceactive = false;
        $courseenabled = false;
        $contextenabled = false;
        // Debug information (the per-thread LLM logs and the per-message runtime meta) is gated by a
        // capability, not just the site-wide aidebugmode setting, so enabling debug logging does not
        // reveal it to every user. Managers see it by default (bookingextension/agent:viewdebug).
        $canviewdebug = has_capability('bookingextension/agent:viewdebug', $context, $this->userid);
        $debugmode = !empty(get_config('bookingextension_agent', 'aidebugmode')) && $canviewdebug;
        $llmdebugenabled = llm_debug_logger::is_enabled() && $canviewdebug;

        $isbookingmodule = $this->ctx->is_module('booking');
        $cmid = $this->ctx->cmid();
        $courseid = $this->ctx->courseid();
        $providerconfigurl = (new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']))->out(false);
        $courseconfigurl = $courseid !== null
            ? (new \moodle_url('/course/edit.php', ['id' => $courseid]))->out(false)
            : null;
        $moduleconfigurl = $cmid !== null
            ? (new \moodle_url('/course/modedit.php', ['update' => $cmid, 'return' => 1]))->out(false)
            : null;
        $capabilityurl = (new \moodle_url('/admin/roles/check.php', [
            'contextid' => $context->id,
            'capability' => 'bookingextension/agent:useaiinstructions',
        ]))->out(false);

        if (class_exists('\\core_ai\\manager')) {
            try {
                $manager = di::get(ai_manager::class);
                // Version-agnostic provider list: real instances on 5.x, synthesised views on 4.5.
                $providerviews = provider_compat::get_provider_views();
                $providersconfigured = !empty($providerviews);

                // Endpoint-based detection (no provider name/class heuristic): an instance counts
                // as the Wunderbyte trial/subscription only when its action endpoint actually
                // targets the Wunderbyte LLM gateway. A wunderbyte provider pointed elsewhere is
                // deliberately excluded. Disabled instances are included so the "activate" path
                // can still find a configured-but-off trial.
                $haswunderbyteprovider = !empty(agent_access_service::find_wunderbyte_llm_instances(false));

                // An ENABLED aiprovider_wunderbyte instance, regardless of endpoint — the provider
                // may be backed by a third-party LLM (e.g. OpenAI). This is "configured", so the
                // onboarding shows the active state (not the "configure" nudge) for it.
                foreach ($providerviews as $inst) {
                    if (
                        !empty($inst->enabled)
                            && strpos((string)($inst->provider ?? ''), 'aiprovider_wunderbyte') !== false
                    ) {
                        $wbinstanceactive = true;
                        break;
                    }
                }

                // The active non-Wunderbyte-provider instance whose key the "Configure" dialog would take. When it
                // already points at the Wunderbyte gateway (a trial or a purchased key on e.g. the core OpenAI
                // provider), the site HAS Wunderbyte access: the dialog then only configures the Wunderbyte provider
                // with it and offers no second trial (one per site) (#2569). Otherwise it is a third-party provider and
                // both choices stay. Moodle 5.x keeps the instance name on the instance, 4.5 views in config['name'].
                foreach ($providerviews as $inst) {
                    if (
                        !empty($inst->enabled)
                            && strpos((string)($inst->provider ?? ''), 'aiprovider_wunderbyte') === false
                            && !empty($inst->config['apikey'])
                    ) {
                        $sourceprovidername = trim((string)($inst->name ?? $inst->config['name'] ?? ''));
                        $existingwunderbyteaccess = agent_access_service::instance_targets_wunderbyte_llm($inst);
                        break;
                    }
                }

                // Use shared factory fallback so readiness checks stay available
                // even when strict skill-governance blocks full registry boot.
                $registry = skill_registry_factory::get_default();
                $store = new conversation_store();
                $interp = new interpreter($registry);
                $orchestrator = new orchestrator($registry, $interp, $store);
                $runtimeproviderstatus = $orchestrator->get_runtime_provider_status($this->ctx->id());

                $provideractive = (bool)($runtimeproviderstatus['provideractive'] ?? false);

                if (
                    method_exists($manager, 'is_ai_tools_enabled_in_course') &&
                    method_exists($manager, 'is_action_enabled_in_context')
                ) {
                    $courseenabled = (bool)($runtimeproviderstatus['courseenabled'] ?? false);
                    $contextenabled = (bool)($runtimeproviderstatus['contextenabled'] ?? false);

                    // For wunderbyte custom actions (without placement wiring),
                    // fall back to the module-level AI toggle when provider + course are active.
                    // Only module contexts carry such a toggle; other context levels keep
                    // the value computed by the runtime status.
                    if (
                        !$contextenabled
                        && $haswunderbyteprovider
                        && $provideractive
                        && $courseenabled
                        && $cmid !== null
                    ) {
                        $contextenabled = $this->is_module_ai_toggle_enabled($cmid);
                    }
                } else {
                    // Fallback if method does not exist (e.g. older core version) - assume enabled.
                    $courseenabled = true;
                    $contextenabled = true;
                }
            } catch (\Throwable $e) {
                // Keep provider discovery result if it was already computed and
                // only mark runtime gates as unavailable for this request.
                debugging('aiready: runtime gate evaluation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $provideractive = false;
                $courseenabled = false;
                $contextenabled = false;
            }
        }

        $readyforchat = $provideractive && $courseenabled && $contextenabled && $hascapability;
        $threadid = 0;

        if ($readyforchat) {
            $store = new conversation_store();
            $thread = $store->get_or_create_thread($this->userid, (int)$context->id);
            $threadid = (int)$thread->id;
        }

        $checks = [];
        $checks[] = $this->build_check(
            $providersconfigured,
            get_string('aiready_check_provider_configured', 'bookingextension_agent'),
            $providersconfigured
                ? get_string('aiready_check_provider_configured_done', 'bookingextension_agent')
                : get_string('aiready_check_provider_configured_todo', 'bookingextension_agent'),
            $providerconfigurl
        );
        $checks[] = $this->build_check(
            $provideractive,
            get_string('aiready_check_provider_active', 'bookingextension_agent'),
            $provideractive
                ? get_string('aiready_check_provider_active_done', 'bookingextension_agent')
                : get_string('aiready_check_provider_active_todo', 'bookingextension_agent'),
            $providerconfigurl
        );
        // Availability bypass (ignoreaiavailability): the toggles do not restrict this
        // user — say so instead of pretending the toggles are on (they may be off,
        // which matters when an admin diagnoses a teacher-facing problem).
        $availabilitybypassed = (bool)($runtimeproviderstatus['availabilitybypassed'] ?? false);
        if ($courseid !== null) {
            // The course-level AI toggle only exists within a course; on dashboard or
            // system pages the row would be meaningless.
            $checks[] = $this->build_check(
                $courseenabled,
                get_string('aiready_check_course_enabled', 'bookingextension_agent'),
                $availabilitybypassed
                    ? get_string('aiready_check_availability_bypassed', 'bookingextension_agent')
                    : ($courseenabled
                        ? get_string('aiready_check_course_enabled_done', 'bookingextension_agent')
                        : get_string('aiready_check_course_enabled_todo', 'bookingextension_agent')),
                $courseconfigurl
            );
        }
        if ($cmid !== null) {
            // Module-level AI toggle row only makes sense inside an activity.
            $checks[] = $this->build_check(
                $contextenabled,
                get_string('aiready_check_context_enabled', 'bookingextension_agent'),
                ($availabilitybypassed && $contextenabled)
                    ? get_string('aiready_check_availability_bypassed', 'bookingextension_agent')
                    : ($contextenabled
                        ? get_string('aiready_check_context_enabled_done', 'bookingextension_agent')
                        : get_string('aiready_check_context_enabled_todo', 'bookingextension_agent')),
                $moduleconfigurl
            );
        }
        $checks[] = $this->build_check(
            $hascapability,
            get_string('aiready_check_capability', 'bookingextension_agent'),
            $hascapability
                ? get_string('aiready_check_capability_done', 'bookingextension_agent')
                : get_string('aiready_check_capability_todo', 'bookingextension_agent'),
            $capabilityurl
        );

        $introtext = get_string('aiready_intro_text', 'bookingextension_agent');

        $admintext = '';
        $nonadmintext = '';

        if (!$readyforchat) {
            if ($isplatformadmin) {
                if (!$haswunderbyteprovider) {
                    $admintext = get_string('aiready_admin_text', 'bookingextension_agent');
                } else {
                    $reason = $runtimeproviderstatus['failurereason'] ?? '';
                    $reasonmap = [
                        'subsystem_missing' => 'error_ai_subsystem_missing',
                        'no_provider'       => 'error_ai_no_provider',
                        'provider_inactive' => 'error_ai_provider_inactive',
                        'actions_missing'   => 'error_ai_actions_missing',
                        'course_disabled'   => 'error_ai_course_disabled',
                        'context_disabled'  => 'error_ai_context_disabled',
                        // Internal failure of the status check itself — not a provider error.
                        'exception_thrown'  => 'error_ai_internal_status',
                    ];
                    if ($reason !== '' && isset($reasonmap[$reason])) {
                        $admintext = get_string($reasonmap[$reason], 'bookingextension_agent');
                    } else if (!$hascapability) {
                        // Runtime is fine, only the use-capability is missing — the
                        // capability check row already explains that; a provider
                        // error message here would point admins at the wrong knob.
                        $admintext = '';
                    } else {
                        $admintext = get_string('ai_provider_not_configured', 'bookingextension_agent');
                    }
                }
            } else {
                $nonadmintext = get_string('aiready_nonadmin_text', 'bookingextension_agent');
            }
        }

        $activationquestiontext = $haswunderbyteprovider
            ? get_string('aitrial_activation_question_existing_provider', 'bookingextension_agent')
            : get_string('aitrial_activation_question', 'bookingextension_agent');

        $stats = $this->get_booking_statistics();
        if (!$isbookingmodule) {
            $welcometext = get_string('ai_welcome_generic', 'bookingextension_agent');
        } else if ($stats['num_options'] === 0) {
            $welcometext = get_string('ai_welcome_empty', 'bookingextension_agent');
        } else {
            $welcometext = get_string('ai_welcome_with_options', 'bookingextension_agent', (object) [
                'numoptions' => $stats['num_options'],
                'numbooked' => $stats['num_booked'],
            ]);
        }

        // Previews are now self-contained: each skill returns ready HTML (and optionally its own
        // AMD module name) as data inside the result, so there is no global preview-type/js-module
        // registry to seed here.
        $jsmodules = [];

        // Who may start the guided trial setup: admins (via site:doanything) plus anyone
        // explicitly granted the capability (managers). Drives the trial UI visibility so
        // managers — not only platform admins — can onboard.
        $canrequesttrial = has_capability(
            'bookingextension/agent:requesttrial',
            context_system::instance(),
            $this->userid
        );

        // Provider PLUGIN installation (distinct from an instance being CONFIGURED): decides
        // whether to recommend installing the Wunderbyte provider or to offer the standard
        // (reduced) fallback in the onboarding card.
        $wunderbyteprovinstalled = (bool)\core_component::get_plugin_directory('aiprovider', 'wunderbyte');
        $standardprovinstalled = (bool)\core_component::get_plugin_directory('aiprovider', 'openai');

        // Moodle 4.5 has the pre-instance core_ai model: multi-instance providers (and thus the
        // Wunderbyte provider plugin) are unavailable, so the agent permanently runs in reduced
        // mode (core generate_text + full-catalog, no embeddings/RAG). Used to suppress the
        // "install/upgrade the Wunderbyte provider" nudges that cannot succeed there.
        $supportsinstances = provider_compat::supports_provider_instances();
        $reducedmodepermanent = !$supportsinstances;

        // Privacy mode drives the GDPR consent modal shown before a trial is requested.
        // Default mirrors the admin setting default ('strict'); an unset config is treated as enabled.
        $privacymode = (string)get_config('bookingextension_agent', 'aiprivacymode');
        if ($privacymode === '') {
            $privacymode = 'strict';
        }

        return [
            'cmid' => $cmid ?? 0,
            'contextid' => (int)$context->id,
            'threadid' => $threadid,
            'sesskey' => sesskey(),
            'wwwroot' => $CFG->wwwroot,
            'ready_for_chat' => $readyforchat,
            'provider_available' => $provideractive,
            'is_platform_admin' => $isplatformadmin,
            'can_request_trial' => $canrequesttrial,
            'has_use_capability' => $hascapability,
            'show_trial_button' => $canrequesttrial && !$readyforchat && !$haswunderbyteprovider,
            'show_trial_activate_button' => $canrequesttrial && !$readyforchat && $haswunderbyteprovider,
            // GDPR consent modal state (rendered client-side before the trial key request).
            'privacy_mode' => $privacymode,
            'privacy_mode_active' => $privacymode !== 'off',
            // Provider-plugin guidance for the onboarding card.
            'wunderbyte_provider_installed' => $wunderbyteprovinstalled,
            'standard_provider_installed' => $standardprovinstalled,
            'using_standard_fallback' => !$wunderbyteprovinstalled && $standardprovinstalled,
            'no_provider_installed' => !$wunderbyteprovinstalled && !$standardprovinstalled,
            'provider_install_url' => get_string('aitrial_provider_install_url', 'bookingextension_agent'),
            // Stepper wizard: which step the onboarding card is on. Connect = no working provider yet;
            // Activate = a provider is active but AI is not enabled in this context (capability already ok).
            'wizard_connect' => $canrequesttrial && !$provideractive,
            'wizard_activate' => $canrequesttrial && $provideractive && !$readyforchat && $hascapability,
            // A non-Wunderbyte provider is doing the work (agent runs, reduced). Two upgrade nudges:
            // - upgrade: the Wunderbyte provider PLUGIN is not even installed -> link to install it.
            // - configure: the plugin IS installed but no Wunderbyte instance is active yet -> offer
            // one-click auto-configuration (the Wunderbyte trial provisioning).
            // On Moodle 4.5 the Wunderbyte provider plugin cannot be installed, so the "upgrade
            // provider" nudge would link to an install that can never succeed — suppress it there.
            'provider_upgrade_available' => $provideractive && !$wbinstanceactive
                && !$wunderbyteprovinstalled && $supportsinstances,
            'provider_configure_available' => $provideractive && !$wbinstanceactive && $wunderbyteprovinstalled,
            // True on Moodle versions without multi-instance core_ai (4.5): the agent runs in
            // permanent reduced mode; the UI explains this instead of nudging a WB-provider install.
            'reduced_mode_permanent' => $reducedmodepermanent,
            // Manual key entry (purchased key) + admin gear. The key store needs a supported provider
            // plugin (Wunderbyte for the full skill set, or the standard OpenAI-compatible provider as a
            // reduced fallback — mirroring the trial flow) and the trial/onboarding capability; the debug
            // toggle is a site-config (admin) action; the gear shows if at least one of those is
            // available. provider_configured drives the overwrite confirm (a Wunderbyte instance active).
            'can_store_key' => $canrequesttrial && ($wunderbyteprovinstalled || $standardprovinstalled),
            'can_toggle_debug' => $isplatformadmin,
            'provider_manage_available' => ($canrequesttrial && $wunderbyteprovinstalled) || $isplatformadmin,
            // Gear entry "Connect with Claude": shown to users who may connect an external AI client
            // over MCP (mcpaccess; admins pass implicitly). The linked page checks the tool_oauthmcp
            // prerequisites and explains the OAuth 2.1 handshake, so it is useful even before the
            // plugin is installed/configured.
            'can_connect_claude' => has_capability(
                'bookingextension/agent:mcpaccess',
                context_system::instance(),
                $this->userid
            ),
            'provider_configured' => $wbinstanceactive,
            // Active Wunderbyte provider but no full access (not on the Wunderbyte LLM and no PRO
            // licence) → show the green "active" pill plus a "Get Pro" upgrade link to the store.
            'show_get_pro' => $wbinstanceactive && !agent_access_service::has_full_access(),
            'source_provider_name' => $sourceprovidername,
            // Configure dialog: existing Wunderbyte access -> one action "configure the Wunderbyte provider with it",
            // no trial; a third-party source -> take its credentials, or start a trial.
            'configure_from_existing_access' => $existingwunderbyteaccess,
            'configure_offer_trial' => !$existingwunderbyteaccess,
            // Live AI-credit bar: only when a Wunderbyte provider exists and the
            // viewer may see organisation-level spend (managers/admins).
            'show_usage_bar' => $haswunderbyteprovider
                && has_capability('aiprovider/wunderbyte:viewusage', context_system::instance(), $this->userid),
            'activation_question_text' => $activationquestiontext,
            'intro_text' => $introtext,
            'admin_text' => $admintext,
            'nonadmin_text' => $nonadmintext,
            'readiness_checks' => $checks,
            'num_options' => $stats['num_options'],
            'num_booked' => $stats['num_booked'],
            'welcome_text' => $welcometext,
            'debug_mode' => $debugmode,
            'llm_debug_enabled' => $llmdebugenabled,
            'registered_js_modules_json' => json_encode($jsmodules),
            // Auto-confirm session-allowance lifetime, shown on the "confirm for session" button label.
            'session_confirm_minutes' => intdiv(conversation_store::CONFIRMATION_SESSION_ALLOWLIST_TTL, 60),
            // Whether the "confirm for session" button is offered at all: suspending the per-action
            // confirm gate is capability-gated (admin-only by default). Server-enforced again in
            // ai_confirm_run, so hiding here is UX, not the security boundary.
            'can_confirm_session' => has_capability(
                'bookingextension/agent:confirmforsession',
                $context,
                $this->userid
            ),
        ];
    }

    /**
     * Build a single readiness check row.
     *
     * @param bool $done
     * @param string $label
     * @param string $detail
     * @param string|null $configureurl
     * @return array
     */
    private function build_check(bool $done, string $label, string $detail, ?string $configureurl = null): array {
        return [
            'done' => $done,
            'label' => $label,
            'detail' => $detail,
            'configureurl' => $configureurl,
            'configurelabel' => get_string('aiready_configure_here', 'bookingextension_agent'),
            'icon' => $done
                ? '<i class="fa fa-check-square text-success" aria-hidden="true"></i>'
                : '<i class="fa fa-square-o text-muted" aria-hidden="true"></i>',
        ];
    }

    /**
     * Check whether the module-level AI toggle is enabled.
     *
     * @param int $cmid
     * @return bool
     */
    private function is_module_ai_toggle_enabled(int $cmid): bool {
        // Missing on Moodle 5.0 cores — no API means the toggle counts as enabled (#2328).
        if (!method_exists(ai_manager::class, 'get_ai_fields_from_course_module')) {
            return true;
        }
        try {
            $fields = ai_manager::get_ai_fields_from_course_module($cmid);
            return is_null($fields->enableaitools) || (bool)$fields->enableaitools;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get booking statistics via duck-typed provider discovery.
     *
     * The concrete implementation lives in mod_booking\local\wizard\booking\booking_readiness_provider
     * so the engine carries no compile-time dependency on mod_booking internals.
     * Non-booking contexts get neutral values.
     *
     * @return array{num_options:int,num_booked:int}
     */
    private function get_booking_statistics(): array {
        $neutral = ['num_options' => 0, 'num_booked' => 0];
        if (!$this->ctx->is_module('booking')) {
            return $neutral;
        }
        $cm = get_coursemodule_from_id('booking', (int)$this->ctx->cmid(), 0, false, IGNORE_MISSING);
        $provider = '\\mod_booking\\local\\wizard\\booking\\booking_readiness_provider';
        if ($cm && class_exists($provider) && method_exists($provider, 'get_booking_statistics')) {
            try {
                return (array)$provider::get_booking_statistics((int)$this->ctx->cmid(), (int)$cm->instance);
            } catch (\Throwable $e) {
                debugging('aiready: booking_readiness_provider failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return $neutral;
    }
}
