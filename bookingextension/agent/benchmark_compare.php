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
 * Compare two benchmark runs side-by-side.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

use bookingextension_agent\local\wizard\benchmark\benchmark_metrics_calculator;
use bookingextension_agent\local\wizard\benchmark\benchmark_db_writer;

$runaid  = required_param('run_a', PARAM_INT);
$runbid  = optional_param('run_b', 0, PARAM_INT);

// If run_b not given, compare against latest baseline.
$runa = $DB->get_record('bx_agent_bm_runs', ['id' => $runaid], '*', MUST_EXIST);

$writer = new benchmark_db_writer();
if ($runbid <= 0) {
    $runb = $writer->get_latest_baseline();
    if (!$runb) {
        // Fall back to second most recent run.
        $rows = $DB->get_records_sql(
            'SELECT * FROM {bx_agent_bm_runs} WHERE id != :id ORDER BY timecreated DESC LIMIT 1',
            ['id' => $runaid]
        );
        $runb = $rows ? reset($rows) : null;
    }
} else {
    $runb = $DB->get_record('bx_agent_bm_runs', ['id' => $runbid], '*', MUST_EXIST);
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url(
    '/mod/booking/bookingextension/agent/benchmark_compare.php',
    ['run_a' => $runaid, 'run_b' => $runbid]
));
$PAGE->set_title(get_string('benchmark_compare_title', 'bookingextension_agent'));
$PAGE->set_heading(get_string('benchmark_compare_heading', 'bookingextension_agent', (object)[
    'runa' => $runaid,
    'runb' => $runb ? '#' . $runb->id : get_string('benchmark_no_baseline', 'bookingextension_agent', 'no baseline'),
]));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

$calc = new benchmark_metrics_calculator();

// Run selector dropdown (using Moodle's single_select component).
$allruns = $DB->get_records_sql(
    'SELECT id, label, timecreated FROM {bx_agent_bm_runs} ORDER BY timecreated DESC LIMIT 50'
);
$options = [];
foreach ($allruns as $r) {
    $options[$r->id] = '#' . $r->id . ' ' . $r->label . ' (' . userdate($r->timecreated, '%d.%m') . ')';
}
$selecturl = new moodle_url('/mod/booking/bookingextension/agent/benchmark_compare.php', ['run_a' => $runaid]);
$select = new single_select($selecturl, 'run_b', $options, $runb ? $runb->id : '', null, 'compare-select');
$select->label = get_string('benchmark_compare_with', 'bookingextension_agent');
$select->class = 'mb-3';
echo $OUTPUT->render($select);

if (!$runb) {
    echo $OUTPUT->notification(get_string('benchmark_no_comparison_run', 'bookingextension_agent'), 'info');
    echo $OUTPUT->footer();
    exit;
}

// Load metrics.
$metaa = $DB->get_records('bx_agent_bm_metrics', ['run_id' => $runa->id]);
$metab = $DB->get_records('bx_agent_bm_metrics', ['run_id' => $runb->id]);

$mapa  = [];
$units = [];
foreach ($metaa as $m) {
    if ($m->scenario_class === null) {
        $mapa[$m->metric_key] = (float)$m->metric_value;
        $units[$m->metric_key] = $m->metric_unit;
    }
}
$mapb  = [];
foreach ($metab as $m) {
    if ($m->scenario_class === null) {
        $mapb[$m->metric_key] = (float)$m->metric_value;
        if (!isset($units[$m->metric_key])) {
            $units[$m->metric_key] = $m->metric_unit;
        }
    }
}

$comparison = $calc->compare(
    array_map('floatval', $mapa),
    array_map('floatval', $mapb)
);

// Header summary.
echo html_writer::start_div('row mb-3');
foreach ([['A', $runa], ['B', $runb]] as [$tag, $r]) {
    echo html_writer::start_div('col-md-6');
    echo html_writer::start_div('card');

    $runstr = get_string('benchmark_run_a', 'bookingextension_agent');
    if ($tag === 'B') {
        $runstr = get_string('benchmark_run_b', 'bookingextension_agent');
    }

    echo html_writer::div(
        html_writer::tag('strong', $runstr) . ': #' . $r->id . ' ' . htmlspecialchars($r->label),
        'card-header'
    );

    $cardcontent = '';
    $cardcontent .= get_string('benchmark_model', 'bookingextension_agent') . ': ' .
        htmlspecialchars($r->model_id) . html_writer::empty_tag('br');
    $cardcontent .= get_string('benchmark_set', 'bookingextension_agent') . ': ' .
        htmlspecialchars($r->skill_set) . html_writer::empty_tag('br');
    $cardcontent .= get_string('benchmark_success', 'bookingextension_agent') . ': ' .
        $r->success_rate . '% (' . $r->passed . '/' . $r->total_scenarios . ')' .
        html_writer::empty_tag('br');
    $cardcontent .= get_string('benchmark_date', 'bookingextension_agent') . ': ' . userdate($r->timecreated, '%d.%m.%Y %H:%M');

    echo html_writer::div(html_writer::tag('small', $cardcontent), 'card-body p-2');
    echo html_writer::end_div(); // Card.
    echo html_writer::end_div(); // Col-md-6.
}
echo html_writer::end_div(); // Row.

// Delta table.
echo html_writer::tag('h3', get_string('benchmark_metric_delta', 'bookingextension_agent'));

$table = new html_table();
$table->head = [
    get_string('benchmark_metric', 'bookingextension_agent'),
    get_string('benchmark_run_a', 'bookingextension_agent'),
    get_string('benchmark_run_b', 'bookingextension_agent'),
    get_string('benchmark_delta', 'bookingextension_agent'),
    get_string('benchmark_threshold', 'bookingextension_agent'),
    get_string('benchmark_status', 'bookingextension_agent'),
];
$table->attributes['class'] = 'table table-bordered table-sm';
$table->data = [];

$allkeys = array_unique(array_merge(array_keys($mapa), array_keys($mapb)));
sort($allkeys);
foreach ($allkeys as $key) {
    $va   = isset($mapa[$key]) ? round((float)$mapa[$key], 2) : '—';
    $vb   = isset($mapb[$key]) ? round((float)$mapb[$key], 2) : '—';
    $comp = $comparison[$key] ?? null;
    $delta = $comp ? ($comp['delta'] >= 0 ? '+' : '') . $comp['delta'] : '—';
    $thresh = $comp ? $comp['threshold'] : '—';
    $status = '';
    $rowclass = '';
    if ($comp) {
        if ($comp['status'] === 'green') {
            $status = '✅';
        } else if ($comp['status'] === 'yellow') {
            $status = '⚠️';
            $rowclass = 'table-warning';
        } else {
            $status = '❌';
            $rowclass = 'table-danger';
        }
    }
    $unit = $units[$key] ?? 'percent';
    $unitstr = '';
    if ($unit === 'percent') {
        $unitstr = '%';
    } else if ($unit === 'ms') {
        $unitstr = 'ms';
    } else if ($unit === 'tokens') {
        $unitstr = '';
    }

    $threshstr = $thresh;
    if ($thresh !== '—') {
        $threshstr = "{$thresh}%";
    }

    $row = new html_table_row([
        $key,
        "{$va}{$unitstr}",
        "{$vb}{$unitstr}",
        html_writer::tag('strong', "{$delta}" . ($unitstr === '%' ? '%' : '')),
        $threshstr,
        $status,
    ]);
    if ($rowclass) {
        $row->attributes['class'] = $rowclass;
    }
    $table->data[] = $row;
}
echo html_writer::table($table);

// Scenario diff.
$scenaa = $DB->get_records('bx_agent_bm_scenarios', ['run_id' => $runa->id]);
$scenab = $DB->get_records('bx_agent_bm_scenarios', ['run_id' => $runb->id]);
$bykeya = array_combine(array_column((array)$scenaa, 'scenario_key'), (array)$scenaa);
$bykeyb = array_combine(array_column((array)$scenab, 'scenario_key'), (array)$scenab);
$allscenkeys = array_unique(array_merge(array_keys($bykeya), array_keys($bykeyb)));
sort($allscenkeys);

$diffs = array_filter($allscenkeys, function ($k) use ($bykeya, $bykeyb) {
    $pa = isset($bykeya[$k]) ? (int)$bykeya[$k]->passed : -1;
    $pb = isset($bykeyb[$k]) ? (int)$bykeyb[$k]->passed : -1;
    return $pa !== $pb;
});

if (!empty($diffs)) {
    echo html_writer::tag('h3', get_string('benchmark_scenario_differences', 'bookingextension_agent'));

    $table = new html_table();
    $table->head = [
        get_string('benchmark_scenario', 'bookingextension_agent'),
        get_string('benchmark_run_a', 'bookingextension_agent'),
        get_string('benchmark_run_b', 'bookingextension_agent'),
    ];
    $table->attributes['class'] = 'table table-sm table-bordered';
    $table->data = [];

    foreach ($diffs as $k) {
        $pa = '—';
        if (isset($bykeya[$k])) {
            $pa = $bykeya[$k]->passed ?
                '✅ ' . get_string('benchmark_passed', 'bookingextension_agent') :
                '❌ ' . get_string('benchmark_failed', 'bookingextension_agent');
        }
        $pb = '—';
        if (isset($bykeyb[$k])) {
            $pb = $bykeyb[$k]->passed ?
                '✅ ' . get_string('benchmark_passed', 'bookingextension_agent') :
                '❌ ' . get_string('benchmark_failed', 'bookingextension_agent');
        }

        $rowclass = (strpos($pa, 'fail') !== false || strpos($pb, 'fail') !== false) ? 'table-warning' : '';

        $row = new html_table_row([
            html_writer::tag('small', $k),
            $pa,
            $pb,
        ]);
        if ($rowclass) {
            $row->attributes['class'] = $rowclass;
        }
        $table->data[] = $row;
    }
    echo html_writer::table($table);
} else {
    echo html_writer::tag(
        'p',
        get_string('benchmark_no_scenario_differences', 'bookingextension_agent'),
        ['class' => 'text-muted']
    );
}

echo $OUTPUT->footer();
