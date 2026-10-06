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
 * Site search governance admin page: per-area indexing enablement + effort estimate.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\site_content_row_mapper;
use bookingextension_agent\local\wizard\services\sitesearch\index_scope_estimator;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_chunk_pipeline;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_state_repository;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_readiness_service;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$context = context_system::instance();

// Only call the site-administration page setup for users who can navigate the admin tree
// (moodle/site:config). For anyone else admin_externalpage_setup() would throw a KNOWN accessdenied,
// so we set the page up manually instead — no point catching an error we expect to fire. The real
// access gate is the require_capability() below: a manager holding our own capability gets in either
// way, a user without it is denied either way.
$adminpagesetup = false;
if (has_capability('moodle/site:config', $context)) {
    try {
        admin_externalpage_setup('bookingextension_agent_sitesearchgovernance');
        $adminpagesetup = true;
    } catch (\core\exception\moodle_exception $e) {
        // Stale admin-tree cache / mid-upgrade: the node is not located yet → manual setup below.
        if ($e->errorcode !== 'sectionerror') {
            throw $e;
        }
    }
}
if (!$adminpagesetup) {
    require_login();
    $PAGE->set_context($context);
    $PAGE->set_url('/mod/booking/bookingextension/agent/sitesearch_governance.php');
    $PAGE->set_pagelayout('admin');
}

require_capability('bookingextension/agent:configuresitesearch', $context);

// Hard gate (blueprint §16): no Wunderbyte embeddings provider / Moodle < 5 / non-DB embeddings
// backend → the page renders ONLY the requirement notice — no toggles, no estimates, nothing else.
$readiness = (new sitesearch_readiness_service())->is_ready();
$ready = !empty($readiness['ready']);

$registry = new site_content_area_registry();
$scoperepository = new sitesearch_scope_repository();

// Handle POST actions (only while the feature gate is open; sesskey-checked).
if ($ready && data_submitted() && confirm_sesskey()) {
    // Category/course scope rules (context governance, K4): enable/disable, file flag and removal
    // of one rule row. All writes go through the repository — its delta-sync chokepoint queues the
    // targeted backfill/prune adhoc task(s), never a site rebuild. Besides the enumerated areas,
    // the wildcard '*' is a valid rule area (§3.0 — "all content areas" rules); only for it a
    // SITE row (scopeid 0) is also actionable here, since wildcard site rows have no toggle in
    // the area table.
    $ruleaction = optional_param('ruleaction', '', PARAM_ALPHA);
    if ($ruleaction !== '') {
        $rulearea = optional_param('rulearea', '', PARAM_RAW_TRIMMED);
        $rulescopetype = optional_param('rulescopetype', '', PARAM_ALPHA);
        $rulescopeid = optional_param('rulescopeid', 0, PARAM_INT);
        $rulescopetypes = [
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
        ];
        $iswildcardrule = site_content_area_registry::is_wildcard($rulearea);
        $validrulearea = $iswildcardrule || in_array($rulearea, $registry->all_area_keys(), true);
        $validrulescope = (in_array($rulescopetype, $rulescopetypes, true) && $rulescopeid > 0)
            || ($iswildcardrule
                && $rulescopetype === sitesearch_scope_repository::SCOPETYPE_SITE
                && $rulescopeid === 0);
        if ($validrulearea && $validrulescope) {
            if ($ruleaction === 'toggle') {
                $scoperepository->set_enabled(
                    $rulearea,
                    optional_param('ruleenable', 0, PARAM_INT) === 1,
                    $rulescopetype,
                    $rulescopeid
                );
            } else if ($ruleaction === 'files') {
                $scoperepository->set_includefiles(
                    $rulearea,
                    optional_param('ruleincludefiles', 0, PARAM_INT) === 1,
                    $rulescopetype,
                    $rulescopeid
                );
            } else if ($ruleaction === 'delete') {
                $scoperepository->delete_rule($rulearea, $rulescopetype, $rulescopeid);
            }
            redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
    // Per-area file-indexing flag (§14.2, PDF v1) of the SITE rule: governed exactly like
    // enablement itself — one flag per area x scope row. The repository purges the effort-estimate
    // cache (fresh figures below right away) and queues the targeted delta sync.
    $togglefilesarea = optional_param('togglefilesarea', '', PARAM_RAW_TRIMMED);
    if ($togglefilesarea !== '' && in_array($togglefilesarea, $registry->all_area_keys(), true)) {
        $scoperepository->set_includefiles($togglefilesarea, optional_param('includefiles', 0, PARAM_INT) === 1);
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $togglearea = optional_param('togglearea', '', PARAM_RAW_TRIMMED);
    $enable = optional_param('enable', 0, PARAM_INT) === 1;
    if (in_array($togglearea, $registry->all_area_keys(), true)) {
        $scoperepository->set_enabled($togglearea, $enable);
        if (!$enable) {
            // Prune inline for immediacy (deliberate choice): the next indexer run would prune the
            // disabled area anyway (delete_owner) and the query-time enablement gate already stops
            // it from being served, but the two DELETEs are cheap and the admin sees the index
            // status drop to zero right away instead of after the next cron pass.
            $variant = (new embeddings_action_config_resolver())->resolve();
            $variantmodel = (string)$variant['model'];
            $variantdims = (int)$variant['dimensions'];
            embeddings_store_factory::instance()
                ->delete_owner(site_content_row_mapper::AREA, $variantmodel, $variantdims, $togglearea);
            (new site_content_state_repository())->delete($togglearea, $variantmodel, $variantdims);
        }
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Set up Moodle Page.
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/sitesearch_governance.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('sitesearchgovernance', 'bookingextension_agent'));
$PAGE->set_heading(get_string('sitesearchgovernance', 'bookingextension_agent'));

if ($ready) {
    // Add-rule modal launcher (core_form/modalform around sitesearch_scope_rule_form).
    $PAGE->requires->js_call_amd('bookingextension_agent/sitesearch_governance', 'init');
}

echo $OUTPUT->header();

if (!$ready) {
    echo $OUTPUT->notification(
        get_string('sitesearchgovernance_gate_notice', 'bookingextension_agent'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    exit;
}

// Title and description.
echo $OUTPUT->heading(get_string('sitesearchgovernance', 'bookingextension_agent'), 2);
echo html_writer::tag('p', get_string('sitesearchgovernance_desc', 'bookingextension_agent'));
echo html_writer::tag(
    'p',
    get_string('sitesearchgovernance_scopenote', 'bookingextension_agent'),
    ['class' => 'small text-muted']
);

// File-indexing note (§14.2, PDF v1): the per-area "Index files (PDF)" toggle lives in the table
// below (only for areas that use file indexing). Estimates include file chunks while an area's
// flag is on, so this page shows the honest cost of the decision.
echo html_writer::tag(
    'p',
    get_string('sitesearchgovernance_files_desc', 'bookingextension_agent'),
    ['class' => 'small text-muted']
);
if (!site_content_chunk_pipeline::extractor_available()) {
    // No pdftotext binary and no bundled parser usable: flags can be set, but the pipeline stays
    // content-only (and the fingerprint says so) until an extractor becomes available.
    echo $OUTPUT->notification(
        get_string('sitesearchgovernance_files_noextractor', 'bookingextension_agent'),
        \core\output\notification::NOTIFY_WARNING
    );
}

$estimator = new index_scope_estimator();
$resolved = (new embeddings_action_config_resolver())->resolve();
$model = (string)$resolved['model'];
$dims = (int)$resolved['dimensions'];
$store = embeddings_store_factory::instance();
$staterepository = new site_content_state_repository();

// Current index status, once: committed chunk counts per owner (= per content area, #2342).
$ownercounts = $store->count_rows_by_owner(site_content_row_mapper::AREA, $model, $dims);

// Freshness header (#2341): when the index last ran and when it will next — otherwise newly
// created content silently looks unfindable until the next scheduled run.
$indextask = \core\task\manager::get_scheduled_task(\bookingextension_agent\task\rebuild_site_content_embeddings::class);
if ($indextask) {
    $lastrun = (int)$indextask->get_last_run_time();
    $nextrun = (int)$indextask->get_next_run_time();
    echo $OUTPUT->notification(
        get_string('sitesearchgovernance_freshness', 'bookingextension_agent', (object)[
            'last' => $lastrun > 0 ? userdate($lastrun) : get_string('never'),
            'next' => $nextrun > 0 ? userdate($nextrun) : get_string('never'),
        ]),
        'info',
        false
    );
}

// Threshold legend, so the traffic light is self-explanatory.
echo html_writer::tag(
    'p',
    get_string('sitesearchgovernance_thresholds', 'bookingextension_agent', (object)[
        'green' => $estimator->green_threshold(),
        'red' => $estimator->red_threshold(),
    ]),
    ['class' => 'small text-muted']
);

$ampelbadgeclasses = ['green' => 'badge-success', 'yellow' => 'badge-warning', 'red' => 'badge-danger'];

// Shared renderers, so the area row, every rule row and the summaries show identical figures.
$renderampel = function (string $ampel) use ($ampelbadgeclasses): string {
    $class = $ampelbadgeclasses[$ampel] ?? 'badge-secondary';
    $label = get_string('sitesearchgovernance_ampel_' . $ampel, 'bookingextension_agent');
    return ' <span class="badge ' . $class . '">' . s($label) . '</span>';
};
$renderestimate = function (?array $estimate) use ($renderampel): string {
    if ($estimate === null) {
        return html_writer::tag(
            'span',
            s(get_string('sitesearchgovernance_estimate_unavailable', 'bookingextension_agent')),
            ['class' => 'text-muted']
        );
    }
    // A capped count aborted at a bound (red threshold / course-sum limit) → honest ">N" (§5b.4).
    $prefix = $estimate['capped'] ? '&gt;' : '';
    return get_string('sitesearchgovernance_doccount', 'bookingextension_agent') . ': '
        . $prefix . $estimate['doccount']
        . ' &middot; ' . get_string('sitesearchgovernance_estchunks', 'bookingextension_agent') . ': '
        . $prefix . $estimate['estchunks']
        . $renderampel($estimate['ampel']);
};
$renderonoffbadge = function (bool $on): string {
    return $on
        ? '<span class="badge badge-success">' . s(get_string('yes')) . '</span>'
        : '<span class="badge badge-secondary">' . s(get_string('no')) . '</span>';
};
// One mini POST form (sesskey included) per toggle/removal action.
$renderactionform = function (array $params, string $label, string $buttonclass) use ($PAGE): string {
    $out = html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url, 'class' => 'd-inline-block m-1']);
    $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    foreach ($params as $name => $value) {
        $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    $out .= html_writer::empty_tag('input', [
        'type' => 'submit',
        'class' => 'btn btn-sm ' . $buttonclass,
        'value' => $label,
    ]);
    $out .= html_writer::end_tag('form');
    return $out;
};
// Rule list shared by the wildcard section and the per-area scope panels: one row per rule with
// status, scope label, rule-scoped estimate (for the wildcard: the bounded sum over every covered
// area), files flag and toggle/remove actions (ruleaction POST handlers above).
$renderruletable = function (
    string $rulearea,
    array $rules,
    bool $usesfiles
) use (
    $DB,
    $estimator,
    $renderactionform,
    $renderestimate,
    $renderonoffbadge
): string {
    $out = html_writer::start_tag('table', ['class' => 'table table-sm mb-2']);
    $out .= html_writer::start_tag('thead');
    $out .= html_writer::start_tag('tr');
    $out .= html_writer::tag('th', get_string('status'), ['style' => 'width: 80px;']);
    $out .= html_writer::tag('th', get_string('sitesearchgovernance_rulescope', 'bookingextension_agent'));
    $out .= html_writer::tag('th', get_string('sitesearchgovernance_estimate', 'bookingextension_agent'));
    $out .= html_writer::tag('th', get_string('sitesearchgovernance_files', 'bookingextension_agent'));
    $out .= html_writer::tag('th', get_string('actions'), ['style' => 'width: 220px;']);
    $out .= html_writer::end_tag('tr');
    $out .= html_writer::end_tag('thead');
    $out .= html_writer::start_tag('tbody');

    foreach ($rules as $rule) {
        $rulescopeid = (int)$rule->scopeid;
        $ruleenabled = !empty($rule->enabled);
        $rulefiles = !empty($rule->includefiles);

        // Scope label: category name / linked course fullname; vanished targets stay listed
        // (removable) instead of breaking the page. Site rows only occur in the wildcard list.
        if ($rule->scopetype === sitesearch_scope_repository::SCOPETYPE_SITE) {
            $scopehtml = s(get_string('sitesearchgovernance_rulescope_site', 'bookingextension_agent'));
        } else if ($rule->scopetype === sitesearch_scope_repository::SCOPETYPE_CATEGORY) {
            $category = core_course_category::get($rulescopeid, IGNORE_MISSING, true);
            // The category's get_formatted_name() is already output-safe — no extra s().
            $scopehtml = $category !== null
                ? get_string(
                    'sitesearchgovernance_rulescope_category',
                    'bookingextension_agent',
                    $category->get_formatted_name()
                )
                : s(get_string('sitesearchgovernance_rulescope_missing', 'bookingextension_agent', $rulescopeid));
        } else {
            $course = $DB->get_record('course', ['id' => $rulescopeid], 'id, fullname');
            if ($course) {
                $courselink = html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $course->id]),
                    format_string($course->fullname)
                );
                $scopehtml = get_string('sitesearchgovernance_rulescope_course', 'bookingextension_agent', $courselink);
            } else {
                $scopehtml = s(get_string('sitesearchgovernance_rulescope_missing', 'bookingextension_agent', $rulescopeid));
            }
        }

        $ruleparams = [
            'rulearea' => $rulearea,
            'rulescopetype' => $rule->scopetype,
            'rulescopeid' => $rulescopeid,
        ];

        $out .= html_writer::start_tag('tr');
        $out .= html_writer::tag('td', $renderonoffbadge($ruleenabled));
        $out .= html_writer::tag('td', $scopehtml);
        // The rule's own estimate, file-inclusive per ITS flag (per-rule, concept §5).
        $out .= html_writer::tag(
            'td',
            $renderestimate($estimator->estimate_for_scope($rulearea, $rule->scopetype, $rulescopeid, $rulefiles))
        );
        $out .= html_writer::start_tag('td');
        if ($usesfiles) {
            $out .= $renderonoffbadge($rulefiles);
            $out .= $renderactionform(
                $ruleparams + ['ruleaction' => 'files', 'ruleincludefiles' => $rulefiles ? 0 : 1],
                $rulefiles ? get_string('disable') : get_string('enable'),
                $rulefiles ? 'btn-outline-danger' : 'btn-outline-success'
            );
        } else {
            $out .= html_writer::tag('span', '&mdash;', ['class' => 'text-muted']);
        }
        $out .= html_writer::end_tag('td');
        $out .= html_writer::start_tag('td');
        $out .= $renderactionform(
            $ruleparams + ['ruleaction' => 'toggle', 'ruleenable' => $ruleenabled ? 0 : 1],
            $ruleenabled ? get_string('disable') : get_string('enable'),
            $ruleenabled ? 'btn-outline-danger' : 'btn-outline-success'
        );
        $out .= $renderactionform(
            $ruleparams + ['ruleaction' => 'delete'],
            get_string('remove'),
            'btn-outline-secondary'
        );
        $out .= html_writer::end_tag('td');
        $out .= html_writer::end_tag('tr');
    }

    $out .= html_writer::end_tag('tbody');
    $out .= html_writer::end_tag('table');
    return $out;
};
// Add-rule launchers (modal via core_form/modalform, see amd/src/sitesearch_governance.js) —
// shared by the wildcard section (area '*') and the per-area panels.
$renderaddrulebuttons = function (string $rulearea): string {
    $out = '';
    foreach (
        [
            sitesearch_scope_repository::SCOPETYPE_CATEGORY => 'sitesearchgovernance_addrule_category',
            sitesearch_scope_repository::SCOPETYPE_COURSE => 'sitesearchgovernance_addrule_course',
        ] as $scopetype => $stringkey
    ) {
        $out .= html_writer::tag('button', s(get_string($stringkey, 'bookingextension_agent')), [
            'type' => 'button',
            'class' => 'btn btn-sm btn-outline-primary mr-2',
            'data-bxagent-addscoperule' => '1',
            'data-area' => $rulearea,
            'data-scopetype' => $scopetype,
            'data-title' => get_string($stringkey, 'bookingextension_agent'),
        ]);
    }
    return $out;
};

// Wildcard section (§3.0), ABOVE the area table: course and category rules covering ALL content
// areas with a course dimension ('other'-support areas stay site-row-only, §9). Same rendering
// and the same ruleaction POST handlers as the per-area rule lists; a wildcard rule's estimate is
// the bounded sum over every covered area. The area panels below remain for the fine-tuning.
echo $OUTPUT->heading(get_string('sitesearchgovernance_wildcardrules', 'bookingextension_agent'), 3);
echo html_writer::tag(
    'p',
    s(get_string('sitesearchgovernance_wildcardrules_desc', 'bookingextension_agent')),
    ['class' => 'small text-muted']
);
$wildcardrules = array_values($scoperepository->list_rules(site_content_area_registry::WILDCARD));
if ($wildcardrules !== []) {
    echo $renderruletable(site_content_area_registry::WILDCARD, $wildcardrules, true);
}
echo html_writer::tag(
    'p',
    $renderaddrulebuttons(site_content_area_registry::WILDCARD),
    ['class' => 'mb-4']
);

echo html_writer::start_tag('table', ['class' => 'table table-hover align-middle']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', get_string('sitesearchgovernance_area', 'bookingextension_agent'));
echo html_writer::tag(
    'th',
    get_string('sitesearchgovernance_sitedefault', 'bookingextension_agent'),
    ['style' => 'width: 110px; text-align: center;']
);
echo html_writer::tag(
    'th',
    get_string('sitesearchgovernance_files', 'bookingextension_agent'),
    ['style' => 'width: 150px; text-align: center;']
);
echo html_writer::tag('th', get_string('sitesearchgovernance_contextsupport', 'bookingextension_agent'));
echo html_writer::tag('th', get_string('sitesearchgovernance_estimate', 'bookingextension_agent'));
echo html_writer::tag('th', get_string('sitesearchgovernance_indexstatus', 'bookingextension_agent'));
echo html_writer::tag('th', get_string('actions'), ['style' => 'width: 140px; text-align: center;']);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

// Page-level total over the EFFECTIVE coverage of every area with active rules (concept §5):
// bounded sums propagate their ">" honestly — the total never caps silently.
$effectivetotal = 0;
$effectivetotalcapped = false;

foreach (site_content_area_registry::all_areas() as $areakey => $descriptor) {
    $isenabled = $scoperepository->is_enabled($areakey);
    $usesfiles = $descriptor['instance']->uses_file_indexing();
    $support = (string)$descriptor['contextsupport'];

    // Area label: the area's own visible name (§11.27 — kills the dynamic-lang-key trap of the
    // former per-area agent strings).
    $label = $descriptor['instance']->get_visible_name();

    echo html_writer::start_tag('tr');

    // Label.
    echo html_writer::start_tag('td');
    echo html_writer::tag('strong', s($label));
    echo '<br/>';
    echo html_writer::tag('small', s($areakey), ['class' => 'text-muted']);
    echo html_writer::end_tag('td');

    // Site-default state (the site rule of the cascade; overridable per category/course below).
    echo html_writer::tag('td', $renderonoffbadge($isenabled), ['style' => 'text-align: center;']);

    // Per-area file-indexing flag (§14.2) of the SITE rule: second toggle, same POST + sesskey
    // pattern as the enable action. Only file-capable areas (uses_file_indexing()) get it.
    echo html_writer::start_tag('td', ['style' => 'text-align: center;']);
    if ($usesfiles) {
        $fileson = $scoperepository->is_includefiles($areakey);
        echo $renderonoffbadge($fileson);
        echo $renderactionform(
            ['togglefilesarea' => $areakey, 'includefiles' => $fileson ? 0 : 1],
            $fileson ? get_string('disable') : get_string('enable'),
            $fileson ? 'btn-outline-danger' : 'btn-outline-success'
        );
    } else {
        echo html_writer::tag('span', '&mdash;', ['class' => 'text-muted']);
    }
    echo html_writer::end_tag('td');

    // Context-support class: module/course areas are reachable through the access prefilter;
    // 'other' areas are fail-closed there (indexable, but hits stay invisible to regular users).
    if ($support === site_content_area_registry::SUPPORT_OTHER) {
        $supporthtml = html_writer::tag(
            'span',
            s(get_string('sitesearchgovernance_contextsupport_other', 'bookingextension_agent')),
            ['class' => 'badge badge-warning']
        );
    } else {
        $supporthtml = html_writer::tag(
            'span',
            s(get_string('sitesearchgovernance_contextsupport_' . $support, 'bookingextension_agent')),
            ['class' => 'text-muted']
        );
    }
    echo html_writer::tag('td', $supporthtml);

    // Estimate column — RULE-SCOPE-AWARE: for active areas the EFFECTIVE coverage across its rules
    // is the honest figure (a course-scoped area must not show the site-wide number up here); the
    // site-wide estimate is shown only for inactive areas, as the pre-enable potential (§5b.4/§11.28).
    // Computed once, up front — it also feeds the page total and the panel's coverage line below.
    $effective = $estimator->estimate_effective($areakey);
    if ($effective !== null) {
        $effectivetotal += (int)$effective['estchunks'];
        $effectivetotalcapped = $effectivetotalcapped || !empty($effective['capped']);
        if (empty($effective['measured'])) {
            $estimatecell = html_writer::tag(
                'span',
                s(get_string(
                    'sitesearchgovernance_effective_unavailable',
                    'bookingextension_agent',
                    (int)$effective['courses']
                )),
                ['class' => 'text-muted']
            );
        } else {
            $estimatecell = s(get_string('sitesearchgovernance_effective', 'bookingextension_agent', (object)[
                'courses' => (int)$effective['courses'],
                'chunks' => (!empty($effective['capped']) ? '>' : '') . (int)$effective['estchunks'],
            ])) . $renderampel((string)$effective['ampel']);
        }
    } else {
        $estimatecell = $renderestimate($estimator->estimate($areakey));
    }
    echo html_writer::tag('td', $estimatecell);

    // Current index status: this area's own committed chunk count (#2342).
    $cursor = $staterepository->get_cursor($areakey, $model, $dims);
    $statushtml = get_string('sitesearchgovernance_indexedchunks', 'bookingextension_agent', $ownercounts[$areakey] ?? 0)
        . '<br/><small class="text-muted">'
        . ($cursor > 0
            ? s(get_string('sitesearchgovernance_cursor', 'bookingextension_agent', userdate($cursor)))
            : s(get_string('sitesearchgovernance_cursor_never', 'bookingextension_agent')))
        . '</small>';
    echo html_writer::tag('td', $statushtml);

    // Site-default toggle action (POST + sesskey).
    echo html_writer::start_tag('td', ['style' => 'text-align: center;']);
    echo $renderactionform(
        ['togglearea' => $areakey, 'enable' => $isenabled ? 0 : 1],
        $isenabled ? get_string('disable') : get_string('enable'),
        $isenabled ? 'btn-outline-danger' : 'btn-outline-success'
    );
    echo html_writer::end_tag('td');

    echo html_writer::end_tag('tr');

    // Note: $effective was computed up front for the estimate column; it also feeds the page total
    // and the panel's coverage line below. For 'other'-support areas it is simply the site rule (§9).

    // Scope panel: category/course rules only exist for areas with a course dimension; for
    // 'other'-support areas only the site row applies, so the rule controls are hidden (§9).
    if ($support === site_content_area_registry::SUPPORT_OTHER) {
        continue;
    }

    $rules = array_values(array_filter(
        $scoperepository->list_rules($areakey),
        static function (stdClass $rule): bool {
            return $rule->scopetype !== sitesearch_scope_repository::SCOPETYPE_SITE;
        }
    ));

    echo html_writer::start_tag('tr');
    echo html_writer::start_tag('td', ['colspan' => 7, 'class' => 'py-2']);
    echo html_writer::start_tag('details');
    echo html_writer::tag(
        'summary',
        s(get_string('sitesearchgovernance_rules_summary', 'bookingextension_agent', count($rules))),
        ['class' => 'font-weight-bold']
    );
    echo html_writer::tag(
        'p',
        s(get_string('sitesearchgovernance_rules_desc', 'bookingextension_agent')),
        ['class' => 'small text-muted mt-2 mb-2']
    );

    if ($rules !== []) {
        echo $renderruletable($areakey, $rules, $usesfiles);
    }

    // Add-rule launchers (modal via core_form/modalform, see amd/src/sitesearch_governance.js).
    echo $renderaddrulebuttons($areakey);

    // Effective coverage line (bounded sum over the allowed course set; ">" when truncated).
    if ($effective === null) {
        $effectivehtml = html_writer::tag(
            'span',
            s(get_string('sitesearchgovernance_effective_none', 'bookingextension_agent')),
            ['class' => 'text-muted']
        );
    } else if (empty($effective['measured'])) {
        $effectivehtml = html_writer::tag(
            'span',
            s(get_string(
                'sitesearchgovernance_effective_unavailable',
                'bookingextension_agent',
                (int)$effective['courses']
            )),
            ['class' => 'text-muted']
        );
    } else {
        $effectivehtml = s(get_string('sitesearchgovernance_effective', 'bookingextension_agent', (object)[
            'courses' => (int)$effective['courses'],
            'chunks' => (!empty($effective['capped']) ? '>' : '') . (int)$effective['estchunks'],
        ])) . $renderampel((string)$effective['ampel']);
    }
    echo html_writer::tag('p', $effectivehtml, ['class' => 'mt-2 mb-0 font-weight-bold']);

    echo html_writer::end_tag('details');
    echo html_writer::end_tag('td');
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

// Budget signal of the whole site: sum of the effective estimates of all active areas (concept
// §5). Bounded sub-sums propagate as ">" — the figure is a floor, never a silently capped value.
echo html_writer::tag(
    'p',
    get_string(
        'sitesearchgovernance_effectivetotal',
        'bookingextension_agent',
        ($effectivetotalcapped ? '&gt;' : '') . $effectivetotal
    ) . $renderampel($estimator->ampel_for($effectivetotal, $effectivetotalcapped)),
    ['class' => 'font-weight-bold']
);

echo $OUTPUT->footer();
