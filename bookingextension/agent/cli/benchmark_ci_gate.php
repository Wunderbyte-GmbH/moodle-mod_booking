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
 * CI gate: exits 1 if the latest benchmark run has a critical regression.
 *
 * Usage:
 *   php benchmark_ci_gate.php [--run-id=N]
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use bookingextension_agent\local\wizard\benchmark\benchmark_metrics_calculator;
use bookingextension_agent\local\wizard\benchmark\benchmark_db_writer;

[$options, ] = cli_get_params(['run-id' => 0, 'help' => false], ['h' => 'help']);

if ($options['help']) {
    cli_writeln("CI gate: exits 1 if latest benchmark run has critical regression.\n  --run-id=N  (default: latest run)");
    exit(0);
}

$runid = (int)$options['run-id'];
$writer = new benchmark_db_writer();
$calc   = new benchmark_metrics_calculator();

if ($runid <= 0) {
    $run = $DB->get_record_sql(
        'SELECT * FROM {bx_agent_bm_runs} ORDER BY timecreated DESC LIMIT 1'
    );
} else {
    $run = $DB->get_record('bx_agent_bm_runs', ['id' => $runid]);
}

if (!$run) {
    cli_writeln('ERROR: No benchmark run found.');
    exit(2);
}

$metrics = $DB->get_records('bx_agent_bm_metrics', ['run_id' => $run->id]);
$metricsmap = [];
foreach ($metrics as $m) {
    $metricsmap[$m->metric_key] = (float)$m->metric_value;
}

$comparison = [];
$baseline = $writer->get_latest_baseline();
if ($baseline) {
    $basemetrics = $DB->get_records('bx_agent_bm_metrics', ['run_id' => $baseline->id]);
    $baselinemap = [];
    foreach ($basemetrics as $m) {
        $baselinemap[$m->metric_key] = (float)$m->metric_value;
    }
    $comparison = $calc->compare($metricsmap, $baselinemap);
}

cli_writeln("=== CI Gate: Run #{$run->id} ({$run->label}) ===");
cli_writeln("Success rate: {$run->success_rate}% | Scenarios: {$run->passed}/{$run->total_scenarios}");

$thresholds = $calc->get_thresholds();
$hasfailure = false;
foreach ($thresholds as $key => $threshold) {
    $val    = $metricsmap[$key] ?? 0.0;
    $delta  = isset($comparison[$key]) ? ($comparison[$key]['delta'] > 0 ? '+' : '') . $comparison[$key]['delta'] : 'n/a';
    $status = $val >= $threshold ? 'OK' : 'FAIL';
    if ($status === 'FAIL') {
        $hasfailure = true;
    }
    cli_writeln("  {$key}: {$val}% (threshold {$threshold}%, delta {$delta}) [{$status}]");
}

if ($hasfailure) {
    cli_writeln("\nCRITICAL REGRESSION — blocking rollout.");
    exit(1);
}

cli_writeln("\nAll critical metrics within thresholds. Gate passed.");
exit(0);
