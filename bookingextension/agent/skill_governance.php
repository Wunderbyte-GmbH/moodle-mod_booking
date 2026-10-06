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
 * AI Skill Governance and Analysis admin page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

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
        admin_externalpage_setup('bookingextension_agent_skillgovernance');
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
    $PAGE->set_url('/mod/booking/bookingextension/agent/skill_governance.php');
    $PAGE->set_pagelayout('admin');
}

require_capability('bookingextension/agent:managegovernance', $context);

$registry = \bookingextension_agent\local\wizard\skill_registry_factory::get_default();
$contracts = $registry->get_skill_contracts();
ksort($contracts);

// Without full access (PRO license / Wunderbyte LLM subscription), PRO-gated skills are not
// toggleable in this UI: their Active checkbox renders disabled, and because a disabled checkbox
// never posts, every save path must skip them too — blindly writing '0' would wipe stored intent
// (e.g. skills enabled while a now-expired license was active, which must revive on renewal).
$hasfullaccess = \bookingextension_agent\local\wizard\services\agent_access_service::has_full_access();
$isprolocked = static function (string $skillname, $meta) use ($hasfullaccess): bool {
    if ($hasfullaccess) {
        return false;
    }
    return \bookingextension_agent\local\wizard\services\agent_access_service::skill_requires_full_access(
        !empty($meta['readonly']),
        (string)($meta['component'] ?? '')
    );
};

// Handle POST actions.
if (data_submitted() && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);
    $bulk = optional_param('bulk', '', PARAM_ALPHA);

    if ($action === 'rebuild') {
        if (class_exists('\\bookingextension_agent\\task\\rebuild_skill_catalog_embeddings_adhoc')) {
            $task = new \bookingextension_agent\task\rebuild_skill_catalog_embeddings_adhoc();
            \core\task\manager::queue_adhoc_task($task, true);
            redirect(
                $PAGE->url,
                get_string('rebuild_skills_catalog_queued', 'bookingextension_agent'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    } else if ($bulk === 'enableall') {
        foreach ($contracts as $skillname => $meta) {
            if ($isprolocked((string)$skillname, $meta)) {
                continue;
            }
            $settingname = \bookingextension_agent\local\wizard\skill_registry::get_skill_toggle_setting_name((string)$skillname);
            set_config($settingname, '1', 'bookingextension_agent');
        }
        // Per-skill toggles are now the source of truth; drop the global override.
        set_config('aiskillenableall', '0', 'bookingextension_agent');
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($bulk === 'disableall') {
        foreach ($contracts as $skillname => $meta) {
            $settingname = \bookingextension_agent\local\wizard\skill_registry::get_skill_toggle_setting_name((string)$skillname);
            set_config($settingname, '0', 'bookingextension_agent');
        }
        // Per-skill toggles are now the source of truth; drop the global override.
        set_config('aiskillenableall', '0', 'bookingextension_agent');
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        // Save individual toggles. Skill names contain dots (e.g. "wizard.list_skills") and
        // Moodle's optional_param_array() silently drops array KEYS that are not [a-z0-9_-]+,
        // so the dotted skill name is carried as the checkbox VALUE (numeric keys) and matched
        // against the known contracts here. Only checked skills are posted; everything else is
        // explicitly set to '0'.
        $enabledposted = optional_param_array('enabledskills', [], PARAM_RAW);
        $enabledset = array_flip(array_map('strval', $enabledposted));
        foreach ($contracts as $skillname => $meta) {
            if ($isprolocked((string)$skillname, $meta)) {
                // Disabled checkbox: nothing posted for it — keep the stored value untouched.
                continue;
            }
            $settingname = \bookingextension_agent\local\wizard\skill_registry::get_skill_toggle_setting_name((string)$skillname);
            $value = isset($enabledset[(string)$skillname]) ? '1' : '0';
            set_config($settingname, $value, 'bookingextension_agent');
        }
        // MCP exposure allowlist (same checkbox-value pattern as enabledskills, because skill
        // names contain dots). Saving here makes the explicit list authoritative — including
        // the empty list, which exposes nothing over MCP.
        $mcpposted = optional_param_array('mcpskills', [], PARAM_RAW);
        $mcpset = array_flip(array_map('strval', $mcpposted));
        $mcpexposed = [];
        foreach ($contracts as $skillname => $meta) {
            if (isset($mcpset[(string)$skillname])) {
                $mcpexposed[] = (string)$skillname;
            }
        }
        set_config('mcpexposedskills', implode(',', $mcpexposed), 'bookingextension_agent');
        // These per-skill toggles are now authoritative. Clearing the global "enable all"
        // override is essential: otherwise is_skill_active() short-circuits to true for every
        // skill and a box unticked here would reappear active on reload.
        set_config('aiskillenableall', '0', 'bookingextension_agent');
        redirect($PAGE->url, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Collision analysis comes from the PERSISTED result (computed by the catalog rebuild task or the
// debug page's recompute button) — the O(N²) pairwise cosine pass is far too expensive to run on
// every page load and only changes when the embeddings change.
$collisionanalyzer = new \bookingextension_agent\local\wizard\services\debug\skill_selection_debug_service();
$collisionresult = $collisionanalyzer->get_cached_collisions() ?? ['has_embeddings' => false, 'pairs' => []];
$hasembeddings = !empty($collisionresult['has_embeddings']);
$skillcollisions = [];
$highcollisioncount = 0;

if ($hasembeddings && !empty($collisionresult['pairs'])) {
    foreach ($collisionresult['pairs'] as $pair) {
        $risk = $pair['risk'] ?? 'ok';
        if ($risk === 'high' || $risk === 'warn') {
            $skillcollisions[$pair['skill_a']][] = [
                'other' => $pair['skill_b'],
                'similarity' => $pair['similarity'],
                'risk' => $risk,
            ];
            $skillcollisions[$pair['skill_b']][] = [
                'other' => $pair['skill_a'],
                'similarity' => $pair['similarity'],
                'risk' => $risk,
            ];
            if ($risk === 'high') {
                $highcollisioncount++;
            }
        }
    }
    // High collision count represents pairs, so divide by 2 for unique pair counts.
    $highcollisioncount = (int)ceil($highcollisioncount / 2);
}

// Real governance-gate evaluation (skill_executability_evaluator) for a chosen user + context.
// Defaults: the current admin and the system context. Override to test a concrete teacher in a
// concrete booking module (paste a user id and that module's context id).
$evaluserid = optional_param('evaluserid', (int)$USER->id, PARAM_INT);
$evalcontextid = optional_param('evalcontextid', (int)$context->id, PARAM_INT);

$evaluator = new \bookingextension_agent\local\wizard\skill_executability_evaluator(
    $registry,
    new \bookingextension_agent\local\wizard\services\security\authorization_service()
);
$mcpcatalog = new \bookingextension_agent\local\wizard\services\mcp\mcp_tool_catalog_service($registry, $evaluator);

$evaluations = [];
foreach ($contracts as $skillname => $meta) {
    $evaluations[(string)$skillname] = $evaluator->evaluate_skill((string)$skillname, $evaluserid, $evalcontextid);
}

// Embeddings-catalog presence per skill. A skill can be governance-"Available" yet have no current
// embedding in the catalog (missing row, empty vector, or a content-hash that drifted) — which
// silently removes it from semantic discovery even though the planner reports it as available.
// We surface that as a warning ("current"|"stale"|"empty"|"missing") in the status column.
// When no embeddings provider is installed at all, the per-skill catalog states are moot (a rebuild
// cannot produce vectors and runtime retrieval is off anyway — the same class_exists check the
// planner uses for em=off), so every skill gets the "noprovider" state and the page banner names
// the real cause instead of suggesting a futile rebuild.
$embeddingstatusbyskill = [];
$missingembeddingcount = 0;
$embeddingsprovideravailable = (new \bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service())
    ->is_wunderbyte_embeddings_available();
if (!$embeddingsprovideravailable) {
    foreach ($contracts as $skillname => $meta) {
        $embeddingstatusbyskill[(string)$skillname] = 'noprovider';
    }
} else {
    try {
        $embsettings = (new \bookingextension_agent\local\wizard\embeddings_action_config_resolver())->resolve();
        // Multi-vector catalog: a skill spans MANY anchor rows (the description #0 plus one per example
        // utterance), so aggregate ALL of a skill's rows instead of letting the last row win — otherwise
        // a single unembedded anchor (or row ordering) makes a fully-retrievable skill look "empty".
        // Rows come through the embeddings store abstraction (CSV or DB backend, per the embeddingsstore
        // flag) for the ACTIVE variant — the same source the rebuild task and runtime discovery use.
        $catalogrowsbyskill = [];
        $skillstore = \bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory::instance();
        $skillrows = $skillstore->stream_rows(
            \bookingextension_agent\local\wizard\services\retrieval\skill_row_mapper::AREA,
            (string)$embsettings['model'],
            (int)$embsettings['dimensions']
        );
        foreach ($skillrows as $skillrow) {
            $catalogrowsbyskill[(string)$skillrow->owner][] = [
                'skill' => (string)$skillrow->owner,
                'hasvector' => !empty($skillrow->embedding),
                'content_hash' => (string)$skillrow->contenthash,
            ];
        }
        // Expected per-skill anchor content-hash SET (drift detection over the whole anchor set, not a
        // single row): an added/removed/changed anchor flips the set and is surfaced as "stale".
        $expectedhashesbyskill = [];
        $expectedrows = (new \bookingextension_agent\local\wizard\services\embeddings\embeddings_catalog_builder_service())
            ->build_full_catalog_rows($registry, (string)$embsettings['model'], (int)$embsettings['dimensions']);
        foreach ($expectedrows as $expectedrow) {
            $expectedhashesbyskill[(string)($expectedrow['skill'] ?? '')][(string)($expectedrow['content_hash'] ?? '')] = true;
        }
        foreach ($contracts as $skillname => $meta) {
            $rows = $catalogrowsbyskill[(string)$skillname] ?? [];
            if (empty($rows)) {
                $state = 'missing';
            } else {
                $storedhashes = [];
                $embeddedanchors = 0;
                foreach ($rows as $r) {
                    if (!empty($r['hasvector'])) {
                        $embeddedanchors++;
                    }
                    $storedhashes[(string)($r['content_hash'] ?? '')] = true;
                }
                $expectedhashes = $expectedhashesbyskill[(string)$skillname] ?? [];
                // Sets equal iff same size and expected ⊆ stored.
                $hashesmatch = !empty($expectedhashes)
                    && count($storedhashes) === count($expectedhashes)
                    && empty(array_diff_key($expectedhashes, $storedhashes));
                if ($embeddedanchors === 0) {
                    // No anchor carries a vector → genuinely not retrievable.
                    $state = 'empty';
                } else if (!$hashesmatch) {
                    $state = 'stale';
                } else {
                    $state = 'current';
                }
            }
            $embeddingstatusbyskill[(string)$skillname] = $state;
            if ($state !== 'current') {
                $missingembeddingcount++;
            }
        }
    } catch (\Throwable $e) {
        // If the catalog cannot be read, leave the map empty: the status column then behaves as before.
        $embeddingstatusbyskill = [];
        $missingembeddingcount = 0;
    }
}

// Resolve the evaluation context label and a readable user label for the header note.
$evalcontextlabel = 'context #' . $evalcontextid;
try {
    $evalcontextlabel = context::instance_by_id($evalcontextid, MUST_EXIST)->get_context_name(false, true);
} catch (\Throwable $e) {
    unset($e);
}
$evaluserlabel = 'user #' . $evaluserid;
if ($evaluser = \core_user::get_user($evaluserid, '*', IGNORE_MISSING)) {
    $evaluserlabel = fullname($evaluser) . ' (#' . $evaluserid . ')';
}

// Map a deny reason + diagnostics to a precise, human-readable hint.
$describedeny = static function (array $evaluation) use ($evalcontextid): string {
    $reason = (string)($evaluation['deny_reason'] ?? '');
    $diagnostics = (array)($evaluation['diagnostics'] ?? []);
    switch ($reason) {
        case \bookingextension_agent\local\wizard\skill_contract_validator::DENY_NOT_REGISTERED:
            return get_string('skillgovernance_gate_deny_not_registered', 'bookingextension_agent');
        case \bookingextension_agent\local\wizard\skill_contract_validator::DENY_RUNTIME_DISABLED:
            return get_string('skillgovernance_gate_deny_runtime_disabled', 'bookingextension_agent');
        case \bookingextension_agent\local\wizard\skill_contract_validator::DENY_INACTIVE:
            return get_string('skillgovernance_gate_deny_inactive', 'bookingextension_agent');
        case \bookingextension_agent\local\wizard\skill_contract_validator::DENY_CONTEXT_INVALID:
            return get_string('skillgovernance_gate_deny_context_invalid', 'bookingextension_agent', $evalcontextid);
        case \bookingextension_agent\local\wizard\skill_contract_validator::DENY_SKILL_VERSION_UNSUPPORTED:
            return get_string('skillgovernance_gate_deny_version_unsupported', 'bookingextension_agent');
        case \bookingextension_agent\local\wizard\skill_contract_validator::DENY_REQUIRES_PRO:
            return get_string('skillgovernance_gate_deny_requires_pro', 'bookingextension_agent');
        case \bookingextension_agent\local\wizard\skill_contract_validator::DENY_MISSING_CAPABILITY:
            $caps = (array)($diagnostics['required_capabilities'] ?? []);
            if (empty($caps)) {
                return get_string('skillgovernance_gate_deny_no_capability', 'bookingextension_agent');
            }
            $parts = [];
            foreach ($caps as $cap) {
                $cap = (string)$cap;
                $key = get_capability_info($cap)
                    ? 'skillgovernance_gate_cap_user_lacks'
                    : 'skillgovernance_gate_cap_not_defined';
                $parts[] = get_string($key, 'bookingextension_agent', $cap);
            }
            return implode('; ', $parts) . '.';
        default:
            return $reason !== '' ? $reason : get_string('skillgovernance_gate_deny_generic', 'bookingextension_agent');
    }
};

// Set up Moodle Page.
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/skill_governance.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('skillgovernance', 'bookingextension_agent'));
$PAGE->set_heading(get_string('skillgovernance', 'bookingextension_agent'));

echo $OUTPUT->header();

// Title and description.
echo $OUTPUT->heading(get_string('skillgovernance', 'bookingextension_agent'), 2);
echo html_writer::tag('p', get_string('aiskillgovernanceheading_desc', 'bookingextension_agent'));

// Evaluation target selector (real governance gate). GET form so it is shareable/bookmarkable.
echo html_writer::start_div('card card-body bg-light mb-3');
echo html_writer::tag(
    'p',
    get_string('skillgovernance_gate_intro', 'bookingextension_agent', (object)[
        'user' => s($evaluserlabel),
        'context' => s($evalcontextlabel),
    ]),
    ['class' => 'mb-2 small text-muted']
);
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $PAGE->url, 'class' => 'form-inline']);
echo html_writer::tag(
    'label',
    get_string('skillgovernance_gate_userid', 'bookingextension_agent'),
    ['for' => 'evaluserid', 'class' => 'mr-1']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'evaluserid',
    'name' => 'evaluserid',
    'value' => $evaluserid,
    'class' => 'form-control mr-3',
    'style' => 'width: 120px;',
]);
echo html_writer::tag(
    'label',
    get_string('skillgovernance_gate_contextid', 'bookingextension_agent'),
    ['for' => 'evalcontextid', 'class' => 'mr-1']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'evalcontextid',
    'name' => 'evalcontextid',
    'value' => $evalcontextid,
    'class' => 'form-control mr-3',
    'style' => 'width: 120px;',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-outline-primary',
    'value' => get_string('skillgovernance_gate_evaluate', 'bookingextension_agent'),
]);
echo html_writer::end_tag('form');
echo html_writer::end_div();

// Top Actions Bar & Status Warnings. The wrapper class re-anchors alert-warning to the theme's
// warning emphasis/subtle tokens (see styles.css) — some themes render it too pale to notice.
echo html_writer::start_div('bookingextension-agent-governance-notifications');
if ($highcollisioncount > 0) {
        $message = 'Warning: There are ' . $highcollisioncount .
            ' high-similarity embedding collision pair(s) detected. ' .
            'This may cause prompt selection confusion in the planner.';
        echo $OUTPUT->notification($message, 'warning');
}

if (!$embeddingsprovideravailable) {
    echo $OUTPUT->notification(
        get_string('skillgovernance_no_embeddings_provider_warning', 'bookingextension_agent'),
        'warning'
    );
} else if ($missingembeddingcount > 0) {
    echo $OUTPUT->notification(
        get_string('skillgovernance_missing_embeddings_warning', 'bookingextension_agent', $missingembeddingcount),
        'warning'
    );
}
echo html_writer::end_div();

echo html_writer::start_div('row mb-4 align-items-center');

// Search Box (Left side).
echo html_writer::start_div('col-md-4');
echo html_writer::start_div('input-group');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'skill-search-input',
    'class' => 'form-control',
    'placeholder' => 'Search skills by name, component or capability...',
]);
echo html_writer::end_div();
echo html_writer::end_div();

// Bulk Buttons & Rebuild (Right side).
echo html_writer::start_div('col-md-8 text-right d-flex justify-content-end');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url, 'class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulk', 'value' => 'enableall']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-success mr-1', 'value' => 'Enable All']);
echo html_writer::end_tag('form');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url, 'class' => 'mr-2']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'bulk', 'value' => 'disableall']);
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-outline-danger mr-1', 'value' => 'Disable All']);
echo html_writer::end_tag('form');

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
// Without this, the POST carries no action/bulk and falls through to the per-skill
// "save toggles" branch with an empty enabledskills[] — disabling every skill (and
// never queuing the rebuild).
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'rebuild']);
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('rebuild_skills_catalog', 'bookingextension_agent'),
]);
echo html_writer::end_tag('form');

echo html_writer::end_div();
echo html_writer::end_div();

// Main Table.
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_tag('table', ['class' => 'table table-hover align-middle', 'id' => 'skills-governance-table']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
echo html_writer::tag('th', 'Active', ['style' => 'width: 80px; text-align: center;']);
echo html_writer::tag('th', get_string('skillgovernance_mcp_column', 'bookingextension_agent'), [
    'style' => 'width: 80px; text-align: center;',
    'title' => get_string('skillgovernance_mcp_column_title', 'bookingextension_agent'),
]);
echo html_writer::tag('th', get_string('skillgovernance_gate_status', 'bookingextension_agent'), ['style' => 'width: 220px;']);
echo html_writer::tag('th', 'Skill Name / Component');
echo html_writer::tag('th', 'Required Capabilities');
echo html_writer::tag('th', 'Collision Status', ['style' => 'width: 200px;']);
echo html_writer::tag('th', 'Actions', ['style' => 'width: 120px; text-align: center;']);
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');

echo html_writer::start_tag('tbody');

$rowindex = 0;
foreach ($contracts as $skillname => $meta) {
    $rowindex++;
    $skill = $registry->get_skill((string)$skillname);
    $provider = $registry->get_provider_for_skill((string)$skillname);
    // Use the engine's own activation check so the checkbox reflects the real runtime state
    // (default-off for skills that were never explicitly enabled; honours "enable all").
    $isactive = $registry->is_skill_active((string)$skillname);
    // PRO marker: mirror the runtime gate exactly (skill_executability_evaluator ->
    // DENY_REQUIRES_PRO). Only WRITE skills of Wunderbyte's own gated components are
    // license-gated; read-only skills and the engine's own course/question skills are free —
    // flagging every mutating skill here used to over-claim PRO on skills that run fine.
    $ispro = \bookingextension_agent\local\wizard\services\agent_access_service::skill_requires_full_access(
        !empty($meta['readonly']),
        (string)($meta['component'] ?? '')
    );
    $prolocked = $ispro && !$hasfullaccess;

    $capabilities = (array)($meta['capabilities'] ?? []);
    $capabilitylabel = implode('<br/>', array_map('s', $capabilities));
    if ($capabilitylabel === '') {
        $capabilitylabel = '<span class="text-muted">-</span>';
    }

    $component = s((string)($meta['component'] ?? ''));

    // Collision badge.
    $collisionshtml = '<span class="badge badge-success">Clear</span>';
    $collisionlist = $skillcollisions[$skillname] ?? [];
    if (!empty($collisionlist)) {
        $highestrisk = 'warning';
        $collisiondetails = [];
        foreach ($collisionlist as $col) {
            if ($col['risk'] === 'high') {
                $highestrisk = 'danger';
            }
            $percent = round($col['similarity'] * 100);
            $collisiondetails[] = s($col['other']) . ' (' . $percent . '%)';
        }
        $tooltip = implode(', ', $collisiondetails);
        $badgeclass = $highestrisk === 'danger' ? 'badge-danger' : 'badge-warning';
        $collisionshtml = '<span class="badge ' . $badgeclass . '" title="' . $tooltip . '" style="cursor: help;">'
            . count($collisionlist) . ' Collision(s)</span>';
    }

    // Row class for search filtering.
    echo html_writer::start_tag('tr', [
        'class' => 'skill-row',
        'data-skillname' => s((string)$skillname),
        'data-component' => $component,
        'data-capabilities' => implode(' ', $capabilities),
    ]);

    // Checkbox. PRO-locked skills render disabled (greyed): they cannot run without full access,
    // so toggling them here would only store dead intent. The save paths skip them accordingly.
    echo html_writer::start_tag('td', ['style' => 'text-align: center;']);
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'enabledskills[]',
        'value' => (string)$skillname,
        'checked' => $isactive ? 'checked' : null,
        'disabled' => $prolocked ? 'disabled' : null,
        'title' => $prolocked
            ? get_string('skillgovernance_gate_deny_requires_pro', 'bookingextension_agent')
            : null,
    ]);
    echo html_writer::end_tag('td');

    // MCP exposure checkbox (same value-carries-the-name pattern as enabledskills).
    echo html_writer::start_tag('td', ['style' => 'text-align: center;']);
    echo html_writer::empty_tag('input', [
        'type' => 'checkbox',
        'name' => 'mcpskills[]',
        'value' => (string)$skillname,
        'checked' => $mcpcatalog->is_exposed((string)$skillname) ? 'checked' : null,
    ]);
    echo html_writer::end_tag('td');

    // Status (real governance gate result for the chosen user + context).
    $evaluation = $evaluations[(string)$skillname] ?? ['executable_state' => 'deny', 'deny_reason' => ''];
    $isexecutable = (string)($evaluation['executable_state'] ?? '') === 'allow';
    $embstate = $embeddingstatusbyskill[(string)$skillname] ?? 'current';
    $hascurrentembedding = $embstate === 'current';
    if ($isexecutable && !$hascurrentembedding) {
        // Governance-allowed but not (currently) in the embeddings catalog, so the planner cannot
        // retrieve it semantically. Flag the "Available" state yellow with the precise reason.
        $embhint = get_string('skillgovernance_gate_no_embeddings_' . $embstate, 'bookingextension_agent');
        $statushtml = '<span class="badge badge-warning" title="' . s($embhint) . '" style="cursor: help;">&#9888; '
            . s(get_string('skillgovernance_gate_available', 'bookingextension_agent')) . '</span>'
            . '<br/><small class="bookingextension-agent-embedding-hint">' . s($embhint) . '</small>';
    } else if ($isexecutable) {
        $statushtml = '<span class="badge badge-success">&#10003; '
            . s(get_string('skillgovernance_gate_available', 'bookingextension_agent')) . '</span>';
    } else {
        // A PRO-locked skill may ALSO be toggled off, but the license lock is the dominant,
        // actionable cause — showing "toggled off" next to a checkbox the admin cannot click
        // would be absurd, so the lock wins regardless of the evaluator's deny ordering.
        $denyreason = (string)($evaluation['deny_reason'] ?? '');
        $showprolock = $prolocked
            || $denyreason === \bookingextension_agent\local\wizard\skill_contract_validator::DENY_REQUIRES_PRO;
        $hint = $showprolock
            ? get_string('skillgovernance_gate_deny_requires_pro', 'bookingextension_agent')
            : $describedeny($evaluation);
        $statushtml = '<span class="badge badge-danger" title="' . s($hint) . '" style="cursor: help;">&#10007; '
            . s(get_string('skillgovernance_gate_blocked', 'bookingextension_agent')) . '</span>'
            . '<br/><small class="text-danger">' . s($hint) . '</small>';
        if ($showprolock) {
            // Same upgrade target the planner's locked-skill reply links to.
            $statushtml .= '<br/>' . html_writer::link(
                get_string('aitrial_pro_license_url', 'bookingextension_agent'),
                get_string('agent_get_pro', 'bookingextension_agent'),
                ['class' => 'badge badge-warning', 'target' => '_blank', 'rel' => 'noopener']
            );
        }
    }
    echo html_writer::tag('td', $statushtml);

    // Skill Name / Component.
    echo html_writer::start_tag('td');
    echo html_writer::tag('strong', s((string)$skillname));
    if ($ispro) {
        echo ' ' . html_writer::tag(
            'span',
            s(get_string('skillgovernance_pro_badge', 'bookingextension_agent')),
            [
                'class' => 'badge badge-warning ml-1',
                'title' => s(get_string('skillgovernance_pro_badge_title', 'bookingextension_agent')),
                'style' => 'cursor: help;',
            ]
        );
    }
    echo '<br/>';
    echo html_writer::tag('small', 'Component: ' . $component, ['class' => 'text-muted']);
    echo html_writer::end_tag('td');

    // Capabilities.
    echo html_writer::tag('td', $capabilitylabel);

    // Collisions.
    echo html_writer::tag('td', $collisionshtml);

    // Actions button.
    echo html_writer::start_tag('td', ['style' => 'text-align: center;']);
    echo html_writer::link(
        '#collapse-details-' . $rowindex,
        'Details',
        [
            'class' => 'btn btn-sm btn-outline-secondary',
            'data-toggle' => 'collapse',
            'data-bs-toggle' => 'collapse',
            'data-bs-target' => '#collapse-details-' . $rowindex,
            'role' => 'button',
            'aria-expanded' => 'false',
            'aria-controls' => 'collapse-details-' . $rowindex,
        ]
    );
    echo html_writer::end_tag('td');

    echo html_writer::end_tag('tr');

    // Collapsible Row.
    echo html_writer::start_tag('tr', ['class' => 'skill-detail-row', 'id' => 'detail-row-' . $rowindex]);
    echo html_writer::start_tag('td', ['colspan' => 7, 'style' => 'padding: 0; border-top: none;']);

    // Build the collapsible inner content.
    $bodycontent = '';

    // Description. The normalized governance metadata in $meta does NOT carry the schema, so the
    // description must be read from the skill's own schema (same source build_prompt_contract uses).
    $schemadescription = '';
    if ($skill) {
        try {
            $schemaarr = (array)$skill->get_schema();
            $schemadescription = (string)($schemaarr['description'] ?? '');
        } catch (\Throwable $e) {
            $schemadescription = '';
        }
    }
    $description = s(trim($schemadescription));
    $bodycontent .= html_writer::tag('h6', 'Description');
    $bodycontent .= html_writer::tag('p', $description ?: '<span class="text-muted">No description.</span>');

    // Example Input.
    $examplehtml = '<span class="text-muted">No example input.</span>';
    if ($skill) {
        try {
            $example = $skill->get_example_input();
            if (!empty($example)) {
                $examplehtml = html_writer::tag(
                    'pre',
                    s(json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                );
            }
        } catch (\Throwable $e) {
            $examplehtml = '<span class="text-danger">Error loading example: ' . s($e->getMessage()) . '</span>';
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Example Parameter Input', ['class' => 'mt-3']);
    $bodycontent .= $examplehtml;

    // Message Triggers.
    $triggershtml = '<span class="text-muted">No message triggers.</span>';
    if ($skill instanceof \bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface) {
        try {
            $triggers = $skill->get_message_triggers();
            if (!empty($triggers)) {
                $triggeritems = [];
                foreach ($triggers as $trigger) {
                    $desc = s((string)($trigger['description'] ?? ''));
                    $examples = (array)($trigger['examples'] ?? []);
                    $exlabel = !empty($examples) ? ' (e.g. "' . implode('", "', array_map('s', $examples)) . '")' : '';
                    $triggeritems[] = html_writer::tag(
                        'li',
                        '<strong>' . s((string)($trigger['id'] ?? '')) . '</strong>: ' . $desc . $exlabel
                    );
                }
                $triggershtml = html_writer::tag('ul', implode('', $triggeritems), ['class' => 'mb-0 pl-3']);
            }
        } catch (\Throwable $e) {
            $triggershtml = '<span class="text-danger">Error loading triggers: ' . s($e->getMessage()) . '</span>';
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Message Triggers', ['class' => 'mt-3']);
    $bodycontent .= $triggershtml;

    // Guidance / Prompt Packs.
    $guidancehtml = '<span class="text-muted">No contextual guidance.</span>';
    $packs = [];
    if ($skill && method_exists($skill, 'get_contextual_prompt_packs')) {
        try {
            $packs = $skill->get_contextual_prompt_packs();
        } catch (\Throwable $e) {
            unset($e);
        }
    }
    if (empty($packs) && $provider && method_exists($provider, 'get_contextual_prompt_packs')) {
        try {
            $allpacks = $provider->get_contextual_prompt_packs();
            foreach ($allpacks as $pack) {
                if (
                    isset($pack['id']) &&
                    (strpos($skillname, $pack['id']) !== false || strpos($pack['id'], $skillname) !== false)
                ) {
                    $packs[] = $pack;
                }
            }
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    if (!empty($packs)) {
        $guidanceitems = [];
        foreach ($packs as $pack) {
            $lines = (array)($pack['guidance'] ?? []);
            foreach ($lines as $line) {
                $guidanceitems[] = html_writer::tag('li', s((string)$line));
            }
        }
        if (!empty($guidanceitems)) {
            $guidancehtml = html_writer::tag('ul', implode('', $guidanceitems), ['class' => 'mb-0 pl-3']);
        }
    }
    $bodycontent .= html_writer::tag('h6', 'Contextual Guidance (Prompts)', ['class' => 'mt-3']);
    $bodycontent .= $guidancehtml;

    // Output collapsible structure matching htmlcomponents.php.
    echo html_writer::div(
        html_writer::div(
            $bodycontent,
            'card card-body'
        ),
        '',
        [
            'class' => 'collapse',
            'id' => 'collapse-details-' . $rowindex,
        ]
    );

    echo html_writer::end_tag('td');
    echo html_writer::end_tag('tr');
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');

// Submit changes button.
echo html_writer::start_div('mt-3 text-left');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary btn-lg',
    'value' => get_string('savechanges'),
]);
echo html_writer::end_div();

echo html_writer::end_tag('form');

// Inject JavaScript for search/filter.
// The AMD footer block runs after DOMContentLoaded has already fired, so waiting
// for that event would never attach the listener — init directly when ready.
$js = "
(function() {
    var init = function() {
        var searchInput = document.getElementById('skill-search-input');
        if (!searchInput) {
            return;
        }

        searchInput.addEventListener('input', function() {
            var query = searchInput.value.toLowerCase().trim();
            var rows = document.querySelectorAll('#skills-governance-table tbody .skill-row');

            rows.forEach(function(row) {
                var skillname = (row.getAttribute('data-skillname') || '').toLowerCase();
                var component = (row.getAttribute('data-component') || '').toLowerCase();
                var capabilities = (row.getAttribute('data-capabilities') || '').toLowerCase();

                var match = skillname.indexOf(query) !== -1 ||
                            component.indexOf(query) !== -1 ||
                            capabilities.indexOf(query) !== -1;

                var nextRow = row.nextElementSibling;
                if (match) {
                    row.style.display = '';
                    if (nextRow && nextRow.classList.contains('skill-detail-row')) {
                        nextRow.style.display = '';
                    }
                } else {
                    row.style.display = 'none';
                    if (nextRow && nextRow.classList.contains('skill-detail-row')) {
                        nextRow.style.display = 'none';
                    }
                }
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
";
$PAGE->requires->js_amd_inline($js);

echo $OUTPUT->footer();
