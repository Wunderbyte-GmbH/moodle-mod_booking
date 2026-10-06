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
 * Import a previously exported benchmark run JSON file.
 *
 * Usage:
 *   php benchmark_import.php --file=/path/to/run.json [--label=override-label]
 *
 * The import assigns a new run_id but preserves run_uuid for cross-env identity.
 * If a run with the same run_uuid already exists, it is skipped (idempotent).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, ] = cli_get_params(
    ['file' => '', 'label' => '', 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || !$options['file']) {
    cli_writeln(
        "Import a benchmark run from a JSON export file.\n  " .
        "--file=/path/to/run.json\n  --label=override-label  (optional)"
    );
    exit(0);
}

$filepath = trim((string)$options['file']);
if (!file_exists($filepath)) {
    cli_error("File not found: {$filepath}");
}

$raw = file_get_contents($filepath);
$data = json_decode($raw, true);
if (!is_array($data) || !isset($data['run'], $data['scenarios'], $data['metrics'])) {
    cli_error("Invalid export file — missing required keys (run, scenarios, metrics).");
}

$version = (int)($data['_export_version'] ?? 0);
if ($version < 1) {
    cli_error("Unsupported export version: {$version}");
}

$rundata = (array)$data['run'];
$uuid    = trim((string)($rundata['run_uuid'] ?? ''));

// Idempotency check.
if ($uuid !== '' && $DB->record_exists('bx_agent_bm_runs', ['run_uuid' => $uuid])) {
    cli_writeln("Run with UUID {$uuid} already exists — skipping import.");
    exit(0);
}

// Override label if provided.
if (trim((string)$options['label']) !== '') {
    $rundata['label'] = trim((string)$options['label']);
}

// Strip old DB id so a new one is assigned.
unset($rundata['id']);
$rundata['timecreated'] = $rundata['timecreated'] ?? time();

$now = time();
$newrunid = $DB->insert_record('bx_agent_bm_runs', (object)$rundata);

foreach ((array)$data['scenarios'] as $scenario) {
    $s = (array)$scenario;
    unset($s['id']);
    $s['run_id']     = $newrunid;
    $s['timecreated'] = $s['timecreated'] ?? $now;
    $DB->insert_record('bx_agent_bm_scenarios', (object)$s);
}

foreach ((array)$data['metrics'] as $metric) {
    $m = (array)$metric;
    unset($m['id']);
    $m['run_id']     = $newrunid;
    $m['timecreated'] = $m['timecreated'] ?? $now;
    $DB->insert_record('bx_agent_bm_metrics', (object)$m);
}

// Restore baseline entries if present.
foreach ((array)($data['baselines'] ?? []) as $baseline) {
    $b = (array)$baseline;
    unset($b['id']);
    $b['run_id']     = $newrunid;
    $b['timecreated'] = $b['timecreated'] ?? $now;
    $DB->insert_record('bx_agent_bm_baselines', (object)$b);
}

cli_writeln("Imported run as ID={$newrunid} (UUID={$uuid}, label=" . ($rundata['label'] ?? '') . ")");
exit(0);
