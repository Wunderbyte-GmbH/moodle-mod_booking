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
 * Benchmark report overview page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();
// Gate on the benchmark view capability (manager archetype) rather than moodle/site:config, so a
// manager can view the report. This page sets the admin layout up manually (no admin_externalpage_setup),
// so there is no admin-tree access error to handle here.
require_capability('bookingextension/agent:viewbenchmarks', context_system::instance());

use bookingextension_agent\local\wizard\benchmark\benchmark_db_writer;
use bookingextension_agent\local\wizard\benchmark\benchmark_metrics_calculator;

$page    = optional_param('page', 0, PARAM_INT);
$perpage = 30;
$action  = optional_param('action', '', PARAM_ALPHA);
$runid   = optional_param('runid', 0, PARAM_INT);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/mod/booking/bookingextension/agent/benchmark_report.php'));
$PAGE->set_title(get_string('benchmark_report_title', 'bookingextension_agent'));
$PAGE->set_heading(get_string('benchmark_report_title', 'bookingextension_agent'));
$PAGE->set_pagelayout('admin');

// Handle actions.
if ($action === 'pinbaseline' && $runid > 0 && confirm_sesskey()) {
    // Pinning the baseline is a WRITE: gate it on managebenchmarks, not the read-only viewbenchmarks
    // the whole page uses — otherwise any report viewer (e.g. a manager) could mutate the baseline.
    require_capability('bookingextension/agent:managebenchmarks', context_system::instance());
    $label = optional_param('baselinelabel', date('Y-m-d'), PARAM_TEXT);
    $writer = new benchmark_db_writer();
    $writer->pin_baseline($runid, $label, '', $USER->id);
    redirect($PAGE->url, get_string('benchmark_baseline_pinned', 'bookingextension_agent'), 2);
}

// Run a benchmark from the interface (queued as an adhoc task). More privileged than viewing — a live
// run issues real LLM calls — so it has its own capability. Provider/model resolution is identical to
// production (env overrides only when BOOKING_TEST_AI_* is set); the effective values are shown in the
// panel next to the button.
if ($action === 'runbenchmark' && confirm_sesskey()) {
    require_capability('bookingextension/agent:runbenchmarks', context_system::instance());
    $runinstanceid = optional_param('benchinstance', 0, PARAM_INT);
    $instancename = '';
    if ($runinstanceid > 0) {
        $instancename = (string)((new \bookingextension_agent\local\wizard\benchmark\benchmark_provider_preview())
            ->list_instances()[$runinstanceid] ?? '');
    }
    $task = new \bookingextension_agent\task\run_benchmark_adhoc();
    $task->set_custom_data([
        'env'                  => 'ui',
        'label'                => date('Y-m-d H:i') . ' (UI: ' . fullname($USER)
            . ($instancename !== '' ? ', ' . $instancename : '') . ')',
        'provider_instance_id' => $runinstanceid,
    ]);
    \core\task\manager::queue_adhoc_task($task, true);
    redirect(
        $PAGE->url,
        get_string('benchmark_run_queued', 'bookingextension_agent'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

$DB->get_manager(); // Ensure DB is loaded.

// Check tables exist.
if (!$DB->get_manager()->table_exists('bx_agent_bm_runs')) {
    echo $OUTPUT->notification(get_string('benchmark_tables_not_installed', 'bookingextension_agent'), 'error');
    echo $OUTPUT->footer();
    exit;
}

// Run-a-benchmark panel — only for holders of the run capability. Lets the user pick a configured AI
// provider INSTANCE (own key/model/endpoint, set in the standard AI admin UI) and shows up front
// exactly which values the run will use. Env (BOOKING_TEST_AI_*) only applies to CLI runs — the
// web/cron path that runs an interface benchmark never sees it, hence explicit instance selection.
if (has_capability('bookingextension/agent:runbenchmarks', context_system::instance())) {
    $previewer = new \bookingextension_agent\local\wizard\benchmark\benchmark_provider_preview();
    $instancelist = $previewer->list_instances();
    $benchinstance = optional_param('benchinstance', 0, PARAM_INT);
    if (!isset($instancelist[$benchinstance])) {
        $benchinstance = $instancelist ? (int)array_key_first($instancelist) : 0;
    }
    $preview = $previewer->describe($benchinstance ?: null);
    $srcenv = get_string('benchmark_run_source_env', 'bookingextension_agent');
    $srcprov = get_string('benchmark_run_source_provider', 'bookingextension_agent');
    $provlabel = $preview['instance_name'] !== '' ? s($preview['instance_name']) : $srcprov;

    // Provider-instance picker — auto-submits (GET) to refresh the preview for the chosen instance.
    $picker = '';
    if (count($instancelist) > 1) {
        $select = new single_select(
            new moodle_url('/mod/booking/bookingextension/agent/benchmark_report.php'),
            'benchinstance',
            $instancelist,
            $benchinstance
        );
        $select->label = get_string('benchmark_run_instance', 'bookingextension_agent') . ' ';
        $picker = html_writer::div($OUTPUT->render($select), 'mb-2');
    } else if (count($instancelist) === 1) {
        $picker = html_writer::div(
            get_string('benchmark_run_instance', 'bookingextension_agent') . ': '
            . html_writer::tag('strong', $provlabel),
            'mb-2'
        );
    }

    $rows = '';
    foreach ($preview['actions'] as $a) {
        $src = $a['source'] === 'env' ? $srcenv . ' (' . s($a['envvar']) . ')' : $provlabel;
        $model = $a['model'] !== '' ? html_writer::tag('code', s($a['model'])) : '—';
        $rows .= html_writer::tag('tr', html_writer::tag('td', s($a['label']))
            . html_writer::tag('td', $model) . html_writer::tag('td', $src));
    }
    $keysrc = $preview['key']['source'] === 'env'
        ? $srcenv . ' (' . s($preview['key']['detail']) . ')' : s($preview['key']['detail']);
    $rows .= html_writer::tag('tr', html_writer::tag('td', get_string('benchmark_run_key_label', 'bookingextension_agent'))
        . html_writer::tag('td', '') . html_writer::tag('td', $keysrc));
    if ($preview['endpoint']['value'] !== '') {
        $endsrc = $preview['endpoint']['source'] === 'env' ? $srcenv : $provlabel;
        $rows .= html_writer::tag('tr', html_writer::tag('td', get_string('benchmark_run_endpoint_label', 'bookingextension_agent'))
            . html_writer::tag('td', html_writer::tag('code', s($preview['endpoint']['value']))) . html_writer::tag('td', $endsrc));
    }
    $table = html_writer::tag('table', html_writer::tag('tbody', $rows), ['class' => 'table table-sm w-auto mb-2']);

    $note = '';
    if (!$preview['provider_found'] && !$preview['env_override_active']) {
        $note .= $OUTPUT->notification(get_string('benchmark_run_provider_missing', 'bookingextension_agent'), 'warning');
    }
    // Whether embeddings are live for the run — true iff the skill catalog is current. When live, show
    // the embedding model that will be used.
    $emblabel = $preview['embeddings_active']
        ? get_string('benchmark_run_embeddings_live', 'bookingextension_agent') . ' — ' . s((string)$preview['embeddings_model'])
        : get_string('benchmark_run_embeddings_off', 'bookingextension_agent');
    $embbadge = $preview['embeddings_active'] ? 'badge badge-success' : 'badge badge-secondary';
    $note .= html_writer::div(
        get_string('benchmark_run_embeddings_label', 'bookingextension_agent') . ': '
        . html_writer::span($emblabel, $embbadge),
        'mb-2'
    );

    $button = html_writer::tag(
        'button',
        get_string('benchmark_run_button', 'bookingextension_agent'),
        ['type' => 'submit', 'class' => 'btn btn-primary']
    );
    $form = html_writer::tag(
        'form',
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'runbenchmark'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'benchinstance', 'value' => $benchinstance])
        . $button,
        ['method' => 'post', 'action' => $PAGE->url->out(false)]
    );

    $subhead = html_writer::tag(
        'div',
        get_string('benchmark_run_effective_values', 'bookingextension_agent'),
        ['class' => 'fw-bold mb-1']
    );
    echo html_writer::div(
        html_writer::tag('h4', get_string('benchmark_run_heading', 'bookingextension_agent'))
        . $picker . $subhead . $note . $table . $form,
        'card card-body mb-4'
    );
}

$total = $DB->count_records('bx_agent_bm_runs');
$runs  = $DB->get_records_sql(
    'SELECT r.*, b.label AS baseline_label
       FROM {bx_agent_bm_runs} r
       LEFT JOIN {bx_agent_bm_baselines} b ON b.run_id = r.id
      ORDER BY r.timecreated DESC',
    [],
    $page * $perpage,
    $perpage
);

$calc = new benchmark_metrics_calculator();
$thresholds = $calc->get_thresholds();

// Trend chart — all runs.
// Select m.id first so get_records_sql keys by m.id (unique per row).
// Multiple metrics per run share the same r.id, which would cause overwrites otherwise.
$trendruns = $DB->get_records_sql(
    'SELECT m.id, r.id AS run_id, r.timecreated, m.metric_key, m.metric_value
       FROM {bx_agent_bm_runs} r
       JOIN {bx_agent_bm_metrics} m ON m.run_id = r.id
      WHERE m.metric_key IN (\'e2e_success_rate\', \'skill_hit_rate\', \'json_validity_rate\')
        AND m.scenario_class IS NULL
      ORDER BY r.timecreated ASC'
);

// Group by run_id; each run contributes up to 3 metric rows.
$runmetrics = [];
$runorder   = [];
foreach ($trendruns as $t) {
    $rid = (int)$t->run_id;
    if (!isset($runmetrics[$rid])) {
        $runmetrics[$rid] = ['timecreated' => (int)$t->timecreated];
        $runorder[] = $rid;
    }
    $runmetrics[$rid][$t->metric_key] = (float)$t->metric_value;
}

$chartdata = ['labels' => [], 'success' => [], 'skillhit' => [], 'jsonok' => []];
foreach ($runorder as $rid) {
    $m = $runmetrics[$rid];
    $chartdata['labels'][]  = date('d.m H:i', $m['timecreated']);
    $chartdata['success'][] = $m['e2e_success_rate'] ?? null;
    $chartdata['skillhit'][] = $m['skill_hit_rate'] ?? null;
    $chartdata['jsonok'][]  = $m['json_validity_rate'] ?? null;
}

echo html_writer::tag('h2', get_string('benchmark_runs', 'bookingextension_agent'));

// Trend chart (Moodle Chart API) + fallback trend table.
if (!empty($chartdata['labels'])) {
    $nruns = count($chartdata['labels']);
    echo html_writer::tag('h3', get_string('benchmark_trend', 'bookingextension_agent', $nruns));

    // Moodle line chart.
    if (class_exists('\core\chart_line')) {
        $chart = new \core\chart_line();
        $chart->set_smooth(true);

        $sset = new \core\chart_series(
            get_string('benchmark_success', 'bookingextension_agent') . ' %',
            array_pad($chartdata['success'], $nruns, null)
        );
        $sset->set_color('#2d6a4f');
        $chart->add_series($sset);

        $tset = new \core\chart_series(
            get_string('benchmark_skill_hit', 'bookingextension_agent') . ' %',
            array_pad($chartdata['skillhit'], $nruns, null)
        );
        $tset->set_color('#457b9d');
        $chart->add_series($tset);

        $jset = new \core\chart_series(
            get_string('benchmark_json_valid', 'bookingextension_agent') . ' %',
            array_pad($chartdata['jsonok'], $nruns, null)
        );
        $jset->set_color('#e9c46a');
        $chart->add_series($jset);

        $chart->set_labels($chartdata['labels']);

        $xaxis = new \core\chart_axis();
        $chart->set_xaxis($xaxis);
        $yaxis = new \core\chart_axis();
        $yaxis->set_min(0);
        $yaxis->set_max(100);
        $chart->set_yaxis($yaxis);
        $charthtml = $OUTPUT->render($chart);
        echo $OUTPUT->render_from_template('bookingextension_agent/benchmark_trend_chart', [
            'containerid' => 'benchmark-chart-container',
            'minwidth' => max(800, $nruns * 35),
            'charthtml' => $charthtml,
        ]);
    }
}

// Runs table.
$table = new html_table();
$table->head = [
    get_string('benchmark_id', 'bookingextension_agent'),
    get_string('benchmark_label', 'bookingextension_agent'),
    get_string('benchmark_model', 'bookingextension_agent'),
    get_string('benchmark_set', 'bookingextension_agent'),
    get_string('benchmark_embeddings', 'bookingextension_agent'),
    get_string('benchmark_success', 'bookingextension_agent'),
    get_string('benchmark_passed', 'bookingextension_agent'),
    get_string('benchmark_duration', 'bookingextension_agent'),
    get_string('benchmark_tokens', 'bookingextension_agent'),
    get_string('benchmark_env', 'bookingextension_agent'),
    get_string('benchmark_git', 'bookingextension_agent'),
    get_string('benchmark_date', 'bookingextension_agent'),
    get_string('benchmark_actions', 'bookingextension_agent'),
];
$table->attributes['class'] = 'table table-hover generaltable';
$table->data = [];

foreach ($runs as $run) {
    $rate    = (float)$run->success_rate;
    $color   = $rate >= 95 ? 'success' : ($rate >= 85 ? 'warning' : 'danger');
    $baseline = $run->is_baseline ? ' ' . html_writer::span(
        get_string('benchmark_baseline_label', 'bookingextension_agent'),
        'badge badge-primary'
    ) : '';
    $regression = $run->regression_detected ? ' ' . html_writer::span(
        get_string('benchmark_regression', 'bookingextension_agent'),
        'badge badge-danger'
    ) : '';

    $detailurl  = new moodle_url('/mod/booking/bookingextension/agent/benchmark_run_detail.php', ['id' => $run->id]);
    $compareurl = new moodle_url('/mod/booking/bookingextension/agent/benchmark_compare.php', ['run_a' => $run->id]);

    $actions = html_writer::link(
        $detailurl,
        get_string('benchmark_detail', 'bookingextension_agent'),
        ['class' => 'btn btn-xs btn-secondary']
    ) . ' ';
    $actions .= html_writer::link(
        $compareurl,
        get_string('benchmark_compare', 'bookingextension_agent'),
        ['class' => 'btn btn-xs btn-info']
    );

    if (!$run->is_baseline) {
        $pinurl = new moodle_url($PAGE->url, [
            'action' => 'pinbaseline',
            'runid' => $run->id,
            'sesskey' => sesskey(),
        ]);
        $actions .= ' ' . html_writer::link(
            $pinurl,
            get_string('benchmark_pin_baseline', 'bookingextension_agent'),
            ['class' => 'btn btn-xs btn-warning']
        );
    }

    $table->data[] = [
        $run->id,
        htmlspecialchars($run->label) . $baseline . $regression,
        htmlspecialchars($run->model_id),
        htmlspecialchars($run->skill_set),
        !empty($run->embeddings_used)
            ? html_writer::span(
                s((string)($run->embeddings_model ?? '') ?: get_string('benchmark_embeddings_on', 'bookingextension_agent')),
                'badge badge-success'
            )
            : html_writer::span(get_string('benchmark_embeddings_off', 'bookingextension_agent'), 'badge badge-secondary'),
        html_writer::span("{$rate}%", "badge badge-{$color}"),
        "{$run->passed}/{$run->total_scenarios}",
        number_format($run->duration_ms / 1000, 1) . 's',
        number_format((int)$run->total_tokens),
        htmlspecialchars($run->environment),
        html_writer::tag('small', htmlspecialchars(substr($run->git_ref, 0, 8))),
        html_writer::tag('small', userdate($run->timecreated, '%d.%m %H:%M')),
        $actions,
    ];
}
echo html_writer::table($table);

echo $OUTPUT->paging_bar($total, $page, $perpage, $PAGE->url);
echo $OUTPUT->footer();
