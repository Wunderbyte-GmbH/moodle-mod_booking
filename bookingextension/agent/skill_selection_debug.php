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
 * Skill selection debug page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use bookingextension_agent\local\wizard\services\debug\skill_selection_debug_service;

$context = context_system::instance();

// Only call the site-administration page setup for users who can navigate the admin tree
// (moodle/site:config). For anyone else admin_externalpage_setup() would throw a KNOWN accessdenied,
// so set the page up manually instead. The real access gate is the require_capability() below, so a
// manager holding debugskillselection gets in while everyone else is denied.
$adminpagesetup = false;
if (has_capability('moodle/site:config', $context)) {
    try {
        admin_externalpage_setup('bookingextension_agent_skillselectiondebug');
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
    $PAGE->set_url('/mod/booking/bookingextension/agent/skill_selection_debug.php');
    $PAGE->set_pagelayout('admin');
}

require_capability('bookingextension/agent:debugskillselection', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$input = optional_param('input', '', PARAM_RAW_TRIMMED);
$cmid = optional_param('cmid', 0, PARAM_INT);
$topk = optional_param('topk', 10, PARAM_INT);
// Default to the live planner pool (executable skills only) so the simulation matches discovery 1:1;
// pass includeunavailable=1 explicitly to also inspect skills the user cannot currently run.
$includeunavailable = optional_param('includeunavailable', 0, PARAM_BOOL);
$collisionlimit = optional_param('collisionlimit', 40, PARAM_INT);

$service = new skill_selection_debug_service();
$simresult = null;
$collisionresult = null;

if ($action !== '' && confirm_sesskey()) {
    if ($action === 'simulate') {
        $simresult = $service->simulate_selection(
            $input,
            (int)$USER->id,
            (int)$cmid,
            (int)$topk,
            (bool)$includeunavailable
        );
    } else if ($action === 'collisions') {
        // Explicit recompute: runs the O(N²) analysis fresh and persists it (also feeds the
        // governance page, which only ever reads the persisted result).
        $collisionresult = $service->compute_and_cache_collisions((int)$collisionlimit);
    }
}

// Without an explicit recompute, show the persisted analysis (computed by the catalog rebuild task
// or a previous button click) instead of recomputing on every page load.
if ($collisionresult === null) {
    $collisionresult = $service->get_cached_collisions();
}

$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/skill_selection_debug.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('skillselectiondebug', 'bookingextension_agent'));
$PAGE->set_heading(get_string('skillselectiondebug', 'bookingextension_agent'));

$simulateurl = new moodle_url('/mod/booking/bookingextension/agent/skill_selection_debug.php', ['action' => 'simulate']);
$collisionurl = new moodle_url('/mod/booking/bookingextension/agent/skill_selection_debug.php', ['action' => 'collisions']);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('skillselectiondebug', 'bookingextension_agent'));

echo html_writer::tag('p', get_string('skillselectiondebug_desc', 'bookingextension_agent'));

// Selection simulator form.
echo $OUTPUT->heading(get_string('skillselectiondebug_simulator', 'bookingextension_agent'), 3);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $simulateurl]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::start_div('fitem');
echo html_writer::tag('label', get_string('skillselectiondebug_input', 'bookingextension_agent'), ['for' => 'id_input']);
echo html_writer::tag('textarea', s($input), [
    'id' => 'id_input',
    'name' => 'input',
    'rows' => 4,
    'cols' => 120,
]);
echo html_writer::end_div();

echo html_writer::start_div('fitem');
echo html_writer::tag('label', get_string('skillselectiondebug_cmid', 'bookingextension_agent'), ['for' => 'id_cmid']);
echo html_writer::empty_tag('input', ['type' => 'number', 'id' => 'id_cmid', 'name' => 'cmid', 'value' => (string)$cmid]);
echo html_writer::end_div();

echo html_writer::start_div('fitem');
echo html_writer::tag('label', get_string('skillselectiondebug_topk', 'bookingextension_agent'), ['for' => 'id_topk']);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'id_topk',
    'name' => 'topk',
    'value' => (string)$topk,
    'min' => '1',
    'max' => '50',
]);
echo html_writer::end_div();

echo html_writer::start_div('fitem');
echo html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'id' => 'id_includeunavailable',
    'name' => 'includeunavailable',
    'value' => '1',
    'checked' => $includeunavailable ? 'checked' : null,
]);
echo html_writer::tag(
    'label',
    get_string('skillselectiondebug_includeunavailable', 'bookingextension_agent'),
    ['for' => 'id_includeunavailable']
);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('skillselectiondebug_runsimulation', 'bookingextension_agent'),
]);
echo html_writer::end_tag('form');

if (is_array($simresult)) {
    echo $OUTPUT->heading(get_string('skillselectiondebug_simulationresult', 'bookingextension_agent'), 4);
    $selected = (string)($simresult['selected_skill'] ?? '');
    if ($selected === '') {
        echo $OUTPUT->notification(get_string('skillselectiondebug_noselection', 'bookingextension_agent'), 'warning');
    } else {
        echo $OUTPUT->notification(get_string('skillselectiondebug_selectedskill', 'bookingextension_agent', $selected), 'success');
    }

    $table = new html_table();
    $table->head = [
        get_string('skillselectiondebug_skill', 'bookingextension_agent'),
        get_string('skillselectiondebug_combinedscore', 'bookingextension_agent'),
        get_string('skillselectiondebug_lexicalscore', 'bookingextension_agent'),
        get_string('skillselectiondebug_embeddingscore', 'bookingextension_agent'),
        get_string('skillselectiondebug_source', 'bookingextension_agent'),
        get_string('skillselectiondebug_matchterms', 'bookingextension_agent'),
    ];

    foreach ((array)($simresult['candidates'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }

        $table->data[] = [
            s((string)($row['skill'] ?? '')),
            format_float((float)($row['combined_score'] ?? 0.0), 4),
            format_float((float)($row['lexical_score'] ?? 0.0), 4),
            $row['embedding_score'] === null ? '-' : format_float((float)$row['embedding_score'], 4),
            s((string)($row['source'] ?? '')),
            s(implode(', ', (array)($row['match_terms'] ?? []))),
        ];
    }

    echo html_writer::table($table);

    $rawjson = json_encode($simresult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($rawjson)) {
        echo $OUTPUT->heading(get_string('skillselectiondebug_rawjson', 'bookingextension_agent'), 5);
        echo html_writer::tag('pre', s($rawjson));
    }
}

// Collision analyzer form.
echo $OUTPUT->heading(get_string('skillselectiondebug_collisions', 'bookingextension_agent'), 3);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $collisionurl]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('fitem');
echo html_writer::tag(
    'label',
    get_string('skillselectiondebug_collisionlimit', 'bookingextension_agent'),
    ['for' => 'id_collisionlimit']
);
echo html_writer::empty_tag('input', [
    'type' => 'number',
    'id' => 'id_collisionlimit',
    'name' => 'collisionlimit',
    'value' => (string)$collisionlimit,
    'min' => '1',
    'max' => '500',
]);
echo html_writer::end_div();

echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-secondary',
    'value' => get_string('skillselectiondebug_runcollisions', 'bookingextension_agent'),
]);
echo html_writer::end_tag('form');

if (is_array($collisionresult)) {
    echo $OUTPUT->heading(get_string('skillselectiondebug_collisionresult', 'bookingextension_agent'), 4);

    if (!empty($collisionresult['computedat'])) {
        echo html_writer::tag(
            'p',
            get_string(
                'skillselectiondebug_collisions_computedat',
                'bookingextension_agent',
                userdate((int)$collisionresult['computedat'])
            ),
            ['class' => 'text-muted small']
        );
    }

    if (empty($collisionresult['has_embeddings'])) {
        echo $OUTPUT->notification(get_string('skillselectiondebug_embeddingsmissing', 'bookingextension_agent'), 'warning');
    } else {
        $table = new html_table();
        $table->head = [
            get_string('skillselectiondebug_skill_a', 'bookingextension_agent'),
            get_string('skillselectiondebug_skill_b', 'bookingextension_agent'),
            get_string('skillselectiondebug_similarity', 'bookingextension_agent'),
            get_string('skillselectiondebug_risk', 'bookingextension_agent'),
        ];

        foreach ((array)($collisionresult['pairs'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $table->data[] = [
                s((string)($row['skill_a'] ?? '')),
                s((string)($row['skill_b'] ?? '')),
                format_float((float)($row['similarity'] ?? 0.0), 4),
                s((string)($row['risk'] ?? 'ok')),
            ];
        }

        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
