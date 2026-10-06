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
 * Capabilities for bookingextension_agent skills.
 *
 * @package    bookingextension_agent
 * @category   access
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'bookingextension/agent:useaiinstructions' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            // Managers can use the agent too, so the global navbar magic wand (gated by
            // agent:seemagicwand, manager-only) is functional for them.
            'manager' => CAP_ALLOW,
        ],
    ],
    // Visibility of the GLOBAL navbar "magic wand" entry point. It appears on every page and is a
    // more privileged, site-wide entry than the per-module Booking Wizard (useaiinstructions), so
    // its visibility is restricted to managers (site admins pass via moodle/site:doanything). This
    // only governs whether the wand is shown; actual agent usage is still gated by
    // useaiinstructions on every server-side call.
    'bookingextension/agent:seemagicwand' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'bookingextension/agent:debugskillselection' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Visibility of the agent's debug information (the per-thread LLM debug logs and the compact
    // per-message runtime meta shown in the chat panel). The site-wide "aidebugmode" setting only
    // controls whether debug logging happens; whether a user actually SEES that information is gated
    // separately by this capability, so turning debug logging on does not expose it to every user.
    // Managers see it by default; admins pass via moodle/site:doanything.
    'bookingextension/agent:viewdebug' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Availability bypass: holders ignore the course/module "enableaitools" toggles.
    // The toggles are an AVAILABILITY layer aimed at non-privileged users (teachers,
    // later students) — site admins pass implicitly via moodle/site:doanything, and
    // managers get it by default. Assignable per course (category), so an admin can
    // also grant it to selected trusted teachers. See
    // docs/Blueprints/agent_permissions_concept_2026-06-10.md.
    'bookingextension/agent:ignoreaiavailability' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Start the guided AI trial setup (request a Wunderbyte trial key and create the
    // provider instance). Site-level action: admins pass via moodle/site:doanything,
    // and managers are granted explicitly so they can onboard without full site config.
    'bookingextension/agent:requesttrial' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Call agent skills through the MCP web service facade (external MCP clients such as
    // Claude). Deliberately NO archetype defaults: connecting an external AI client is an
    // explicit per-user admin decision (grant the capability + authorise the user on the
    // restricted MCP service + mint a token). Skill execution itself is additionally gated
    // by useaiinstructions and the per-skill capabilities, exactly like the chat entry.
    'bookingextension/agent:mcpaccess' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
];

// Each skill capability is declared at the context level the skill actually operates on:
// - CONTEXT_MODULE: acts on a single booking instance (options, instance config, per-option diagnoses).
// - CONTEXT_COURSE: acts on a whole course (activities, groups, enrolments, sections, calendar, grades).
// - CONTEXT_SYSTEM: acts site-wide or on cross-course/user-global data (search, site summary, user
// profiles/preferences/messages, agent memories, skill catalog, site-global booking data).
// The level governs where the capability shows up in the roles UI and where it can be overridden;
// the runtime gate (skill_executability_evaluator) checks in the thread context and inherits upwards,
// so higher-level declarations keep working for module-context threads.
// Only skills this component actually registers get a capability here. The booking skills
// moved to mod_booking (their capabilities are mod/booking:skill_mod_booking_* in that
// plugin's access.php), and the example skills were removed; dropping the definitions makes
// capabilities_cleanup() delete the stale capabilities from upgraded sites automatically.
$teacherskills = [
    'core_diagnose_notifications' => CONTEXT_SYSTEM,
    'core_diagnose_permissions' => CONTEXT_SYSTEM,
    'core_find_content' => CONTEXT_SYSTEM,
    'core_get_current_user' => CONTEXT_SYSTEM,
    'core_search_users' => CONTEXT_SYSTEM,
    'course_add_activity' => CONTEXT_COURSE,
    'course_add_quiz' => CONTEXT_COURSE,
    'course_analyze_course_structure' => CONTEXT_COURSE,
    'course_diagnose_user_in_course' => CONTEXT_COURSE,
    'course_enrol_user' => CONTEXT_COURSE,
    // Lists all course categories visible to the user (site-wide read).
    'course_list_categories' => CONTEXT_SYSTEM,
    'course_scaffold_course_content' => CONTEXT_COURSE,
    // Searches across all courses on the site.
    'course_search_courses' => CONTEXT_SYSTEM,
    'course_update_activity' => CONTEXT_COURSE,
    'course_update_quiz' => CONTEXT_COURSE,
    'question_generate_questions' => CONTEXT_COURSE,
    'wizard_explain_docs' => CONTEXT_SYSTEM,
    'wizard_list_skills' => CONTEXT_SYSTEM,
    'wizard_recall_memory' => CONTEXT_SYSTEM,
    'wizard_scaffold_skill' => CONTEXT_SYSTEM,
];

$managerskills = [
    // Creates whole Moodle courses — classic manager/coursecreator territory, so the skill
    // capability is manager-only (fail-closed; Gate 2 additionally requires
    // moodle/course:create at the resolved category). Declared at CONTEXT_SYSTEM because the
    // category is resolved in preflight, not from the thread context.
    'course_create_course' => CONTEXT_SYSTEM,
    // Moodle Report Builder family (report.*): custom reports live in the system context and
    // core grants moodle/reportbuilder:view/edit to managers only, so the skill capabilities
    // mirror that (fail-closed; Gate 2 additionally requires the native reportbuilder
    // capabilities and core_reportbuilder\permission checks for the acting user).
    'report_create_report' => CONTEXT_SYSTEM,
    'report_describe_report_source' => CONTEXT_SYSTEM,
    'report_get_report_details' => CONTEXT_SYSTEM,
    'report_list_report_sources' => CONTEXT_SYSTEM,
    'report_query_report' => CONTEXT_SYSTEM,
    'report_schedule_report' => CONTEXT_SYSTEM,
    'report_search_reports' => CONTEXT_SYSTEM,
    'report_set_report_audience' => CONTEXT_SYSTEM,
    'report_update_report' => CONTEXT_SYSTEM,
    // Rebuilds the site-global skill-catalog embeddings (cost-bearing) — manager/admin only,
    // not teacher-grantable (audit CAP-03). Execution additionally requires moodle/site:config
    // via the skill's native capability (Gate 2).
    'wizard_recreate_skill_catalog' => CONTEXT_SYSTEM,
];

// Authorized-user skills: act only on the acting user's own data (e.g. their stored agent
// memories), so any authenticated user permitted to use the agent may run them.
$authorizeduserskills = [
    'wizard_forget' => CONTEXT_SYSTEM,
    'wizard_list_memories' => CONTEXT_SYSTEM,
    'wizard_remember' => CONTEXT_SYSTEM,
];

$buildskillcapability = static function (string $skillsuffix, string $role, int $contextlevel): array {
    $definition = [
        'riskbitmask' => RISK_DATALOSS | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => $contextlevel,
    ];

    if ($role === 'teacher') {
        $definition['archetypes'] = [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ];
    } else if ($role === 'manager') {
        $definition['archetypes'] = [
            'manager' => CAP_ALLOW,
        ];
    } else if ($role === 'authorizeduser') {
        $definition['archetypes'] = [
            'user' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ];
    }
    return ['bookingextension/agent:skill_' . $skillsuffix => $definition];
};

foreach ($teacherskills as $skillsuffix => $contextlevel) {
    $capabilities += $buildskillcapability($skillsuffix, 'teacher', $contextlevel);
}

// Read-only discovery fallback: every agent user holds it; admins can still withdraw per role.
$capabilities['bookingextension/agent:skill_wizard_search_skills'] = [
    'captype' => 'read',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes' => [
        'user' => CAP_ALLOW,
        'teacher' => CAP_ALLOW,
        'editingteacher' => CAP_ALLOW,
        'manager' => CAP_ALLOW,
    ],
];

foreach ($managerskills as $skillsuffix => $contextlevel) {
    $capabilities += $buildskillcapability($skillsuffix, 'manager', $contextlevel);
}

foreach ($authorizeduserskills as $skillsuffix => $contextlevel) {
    $capabilities += $buildskillcapability($skillsuffix, 'authorizeduser', $contextlevel);
}

// Benchmark capabilities.
$capabilities['bookingextension/agent:viewbenchmarks'] = [
    'captype'      => 'read',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [
        'manager' => CAP_ALLOW,
    ],
];

$capabilities['bookingextension/agent:managebenchmarks'] = [
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [],
];

// Run a benchmark from the interface (the "Run benchmark" button on benchmark_report.php). Distinct
// from viewbenchmarks (reading reports): a live run issues real LLM calls and consumes credits, so it
// is admin-only by default (empty archetypes) and must be granted explicitly to delegate it.
$capabilities['bookingextension/agent:runbenchmarks'] = [
    'riskbitmask'  => RISK_CONFIG,
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [],
];

// Skill Governance & Analysis admin page: inspect skill contracts, enable/disable skills and
// rebuild the skill embedding catalog. This is a site-wide, config-style action. Previously the
// page was gated by moodle/site:config (admin-only); it now has its own capability so the page can
// be delegated to managers without granting full site config. Admins still pass implicitly via
// moodle/site:doanything.
$capabilities['bookingextension/agent:managegovernance'] = [
    'riskbitmask'  => RISK_CONFIG,
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [
        'manager' => CAP_ALLOW,
    ],
];

// Offer the "confirm for this session" button on agent confirmations: it suspends the
// per-action confirmation gate for ALL agent mutations in the context for a short period
// (conversation_store::CONFIRMATION_SESSION_ALLOWLIST_TTL). Because that weakens the
// risk-class confirm gate wholesale, it is admin-only by default (empty archetypes; site
// admins pass via moodle/site:doanything) and must be granted explicitly to trusted roles.
// Enforced server-side in ai_confirm_run (the flag is ignored without the capability) and
// mirrored in the UI (aiready hides the button).
$capabilities['bookingextension/agent:confirmforsession'] = [
    'captype'      => 'write',
    'contextlevel' => CONTEXT_MODULE,
    'archetypes'   => [],
];

// Configure the site-wide AI provider credentials (store an API key, adopt an existing provider) used
// by the agent. This writes site-global secrets, so it is admin-only by default (empty archetypes) and
// must be granted explicitly to delegate it — there is deliberately no automatic role assignment.
// Previously these endpoints reused the manager-grantable requesttrial cap (audit 15-F02).
$capabilities['bookingextension/agent:manageaiproviders'] = [
    'riskbitmask'  => RISK_CONFIG,
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [],
];

// Configure the semantic site search: gates the governance page and every enable/disable toggle of
// which search areas and course scopes get indexed. Each enabled scope triggers indexing work and
// thereby embedding costs, so this is a site-wide, config-style action: admin-only by default
// (empty archetypes, permissions cloned from moodle/site:config) but freely grantable to other roles.
$capabilities['bookingextension/agent:configuresitesearch'] = [
    'riskbitmask'  => RISK_CONFIG,
    'captype'      => 'write',
    'contextlevel' => CONTEXT_SYSTEM,
    'archetypes'   => [],
    'clonepermissionsfrom' => 'moodle/site:config',
];
