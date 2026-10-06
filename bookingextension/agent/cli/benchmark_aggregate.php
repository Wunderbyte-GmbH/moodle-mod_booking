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
 * Aggregate the last N benchmark runs into a denoised per-scenario pass-rate.
 *
 * The single-run benchmark is too noisy (the planner LLM is non-deterministic) to
 * judge a 1-2 scenario discovery change. This averages the most recent N runs of a
 * scenario set so a real signal can be separated from model noise.
 *
 * Usage:
 *   php benchmark_aggregate.php --runs=5 [--set=agent_core_v1] [--label=prefix]
 *
 * Options:
 *   --runs=N        Aggregate the last N runs (default 5).
 *   --set=NAME      Scenario set to filter on (default agent_core_v1).
 *   --label=PREFIX  Only runs whose label starts with PREFIX (default: all).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(
    [
        'runs'  => 5,
        'set'   => 'agent_core_v1',
        'label' => '',
        'help'  => false,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln("Aggregate the last N benchmark runs into a denoised per-scenario pass-rate.");
    cli_writeln("  --runs=N   last N runs (default 5)   --set=NAME   scenario set   --label=PREFIX   label filter");
    exit(0);
}

global $DB;

$runstable = 'bx_agent_bm_runs';
$scentable = 'bx_agent_bm_scenarios';

$n = max(1, (int)$options['runs']);
$set = (string)$options['set'];
$labelprefix = trim((string)$options['label']);

// Pick the most recent N runs for the set (optionally label-filtered).
$where = 'skill_set = :set';
$params = ['set' => $set];
if ($labelprefix !== '') {
    $where .= ' AND ' . $DB->sql_like('label', ':label');
    $params['label'] = $labelprefix . '%';
}
$runs = $DB->get_records_select($runstable, $where, $params, 'timecreated DESC', '*', 0, $n);

if (empty($runs)) {
    cli_error("No runs found for set '{$set}'" . ($labelprefix !== '' ? " with label '{$labelprefix}*'" : '') . '.');
}

$runids = array_keys($runs);
$actualn = count($runs);

cli_writeln('=== Benchmark aggregate ===');
cli_writeln("Set: {$set} | runs aggregated: {$actualn}" . ($labelprefix !== '' ? " | label~ {$labelprefix}*" : ''));
cli_writeln(str_repeat('-', 70));

// Per-run overall success rates.
$rates = [];
cli_writeln('Per-run success rate (newest first):');
foreach ($runs as $r) {
    $rates[] = (float)$r->success_rate;
    cli_writeln(sprintf(
        '  id=%-4s %5.1f%%  %2d/%-2d  model=%-22s %s',
        $r->id,
        $r->success_rate,
        $r->passed,
        $r->total_scenarios,
        $r->model_id,
        userdate($r->timecreated, '%Y-%m-%d %H:%M')
    ));
}
$mean = array_sum($rates) / count($rates);
cli_writeln(sprintf(
    '  => mean %.1f%%  (min %.1f%%, max %.1f%%, spread %.1f pp)',
    $mean,
    min($rates),
    max($rates),
    max($rates) - min($rates)
));
cli_writeln(str_repeat('-', 70));

// Per-scenario pass count across the N runs.
[$insql, $inparams] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED);
$rows = $DB->get_records_select($scentable, "run_id {$insql}", $inparams, 'scenario_key');

$byscenario = [];
foreach ($rows as $row) {
    $key = (string)$row->scenario_key;
    if (!isset($byscenario[$key])) {
        $byscenario[$key] = ['pass' => 0, 'total' => 0, 'selected' => []];
    }
    $byscenario[$key]['total']++;
    if (!empty($row->passed)) {
        $byscenario[$key]['pass']++;
    }
    $sel = trim((string)($row->skill_selected ?? ''));
    if ($sel !== '') {
        $byscenario[$key]['selected'][$sel] = ($byscenario[$key]['selected'][$sel] ?? 0) + 1;
    }
}

// Sort worst pass-rate first (the scenarios that need attention).
uasort($byscenario, static function ($a, $b): int {
    $ra = $a['total'] > 0 ? $a['pass'] / $a['total'] : 0;
    $rb = $b['total'] > 0 ? $b['pass'] / $b['total'] : 0;
    return $ra <=> $rb;
});

cli_writeln('Per-scenario pass-rate (worst first):');
foreach ($byscenario as $key => $s) {
    $rate = $s['total'] > 0 ? 100.0 * $s['pass'] / $s['total'] : 0.0;
    arsort($s['selected']);
    $topsel = '';
    foreach ($s['selected'] as $skill => $cnt) {
        $topsel = $skill . " ({$cnt}/{$s['total']})";
        break;
    }
    $flag = $rate < 50 ? ' <<' : ($rate < 100 ? ' ~' : '');
    cli_writeln(sprintf(
        '  %5.0f%%  %d/%d  %-40s sel=%s%s',
        $rate,
        $s['pass'],
        $s['total'],
        $key,
        $topsel,
        $flag
    ));
}
cli_writeln(str_repeat('-', 70));
cli_writeln(sprintf('Stable fails (0%% over %d runs) are real; flipping scenarios are model noise.', $actualn));
