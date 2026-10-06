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
 * Export a benchmark run to JSON for archiving or cross-environment comparison.
 *
 * Usage:
 *   php benchmark_export.php --run-id=N [--output=/path/to/file.json]
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, ] = cli_get_params(
    ['run-id' => 0, 'output' => '', 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || !$options['run-id']) {
    cli_writeln("Export a benchmark run to JSON.\n  --run-id=N\n  --output=/path/to/file.json  (stdout if omitted)");
    exit(0);
}

$runid = (int)$options['run-id'];
$run   = $DB->get_record('bx_agent_bm_runs', ['id' => $runid], '*', MUST_EXIST);
$scenarios = $DB->get_records('bx_agent_bm_scenarios', ['run_id' => $runid], 'scenario_key ASC');
$metrics   = $DB->get_records('bx_agent_bm_metrics', ['run_id' => $runid], 'metric_key ASC');
$baselines = $DB->get_records('bx_agent_bm_baselines', ['run_id' => $runid]);

$export = [
    '_export_version' => 1,
    '_exported_at'    => date('c'),
    'run'             => (array)$run,
    'scenarios'       => array_values(array_map('get_object_vars', (array)$scenarios)),
    'metrics'         => array_values(array_map('get_object_vars', (array)$metrics)),
    'baselines'       => array_values(array_map('get_object_vars', (array)$baselines)),
];

$json = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$outfile = trim((string)$options['output']);
if ($outfile !== '') {
    file_put_contents($outfile, $json);
    cli_writeln("Exported run #{$runid} to {$outfile}");
} else {
    echo $json . "\n";
}

exit(0);
