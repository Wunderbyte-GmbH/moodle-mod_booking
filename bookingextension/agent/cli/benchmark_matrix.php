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
 * Per-scenario pass matrix across the last N benchmark runs.
 *
 * The single-run success rate is dominated by planner non-determinism: scenarios that flip
 * pass/fail run-to-run swing the headline percentage far more than any real change. This tool shows
 * each scenario's pass/fail across the last N runs side by side, so a STABLE FAIL (0/N — a real
 * target) is told apart from a flipping scenario (pure noise). Use it instead of comparing
 * single-run percentages when judging a discovery/selection change.
 *
 * Usage:
 *   php benchmark_matrix.php --runs=6 [--set=agent_core_v1] [--label=prefix]
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
        'runs'  => 6,
        'set'   => 'agent_core_v1',
        'label' => '',
        'help'  => false,
    ],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln('Per-scenario pass matrix across the last N benchmark runs (stable fail vs flip).');
    cli_writeln('  --runs=N   last N runs (default 6)   --set=NAME   scenario set   --label=PREFIX   label filter');
    exit(0);
}

global $DB;

$n = max(1, (int)$options['runs']);
$set = (string)$options['set'];
$labelprefix = trim((string)$options['label']);

$where = 'skill_set = :set';
$params = ['set' => $set];
if ($labelprefix !== '') {
    $where .= ' AND ' . $DB->sql_like('label', ':label');
    $params['label'] = $labelprefix . '%';
}
$runs = $DB->get_records_select('bx_agent_bm_runs', $where, $params, 'timecreated DESC', '*', 0, $n);
if (empty($runs)) {
    cli_error("No runs found for set '{$set}'" . ($labelprefix !== '' ? " with label '{$labelprefix}*'" : '') . '.');
}

// Oldest -> newest so the matrix columns read left (older) to right (newer).
$runs = array_reverse($runs, true);
$runids = array_keys($runs);

cli_writeln('=== runs (left = oldest, right = newest) ===');
$rates = [];
foreach ($runs as $r) {
    $rates[] = (float)$r->success_rate;
    cli_writeln(sprintf(
        '  id=%-4s %5.1f%%  %2d/%-2d  %s  model=%-22s label=%s',
        $r->id,
        (float)$r->success_rate,
        (int)$r->passed,
        (int)$r->total_scenarios,
        userdate((int)$r->timecreated, '%m-%d %H:%M'),
        (string)$r->model_id,
        (string)($r->label ?? '')
    ));
}
cli_writeln(sprintf(
    '  mean=%.1f%%  min=%.1f%%  max=%.1f%%  spread=%.1f pp',
    array_sum($rates) / count($rates),
    min($rates),
    max($rates),
    max($rates) - min($rates)
));
cli_writeln(str_repeat('-', 78));

[$insql, $inparams] = $DB->get_in_or_equal($runids, SQL_PARAMS_NAMED);
$rows = $DB->get_records_select('bx_agent_bm_scenarios', "run_id {$insql}", $inparams);

$matrix = [];
$selbyscen = [];
foreach ($rows as $r) {
    $matrix[(string)$r->scenario_key][(int)$r->run_id] = !empty($r->passed) ? 1 : 0;
    $sel = trim((string)($r->skill_selected ?? ''));
    if ($sel !== '') {
        $selbyscen[(string)$r->scenario_key][$sel] = ($selbyscen[(string)$r->scenario_key][$sel] ?? 0) + 1;
    }
}
ksort($matrix);

cli_writeln('=== scenario x run pass matrix  (P=pass F=fail .=missing) ===');
$stablefails = 0;
$flips = 0;
foreach ($matrix as $key => $byrun) {
    $cells = '';
    $pass = 0;
    foreach ($runids as $rid) {
        $v = $byrun[$rid] ?? null;
        $cells .= ($v === null) ? ' .' : ($v ? ' P' : ' F');
        $pass += (int)($v ?? 0);
    }
    $topsel = '';
    if (!empty($selbyscen[$key])) {
        arsort($selbyscen[$key]);
        $skill = array_key_first($selbyscen[$key]);
        $topsel = $skill . ' (' . $selbyscen[$key][$skill] . 'x)';
    }
    $flag = '';
    if ($pass === 0) {
        $flag = ' <<STABLE FAIL';
        $stablefails++;
    } else if ($pass < count($runids)) {
        $flag = ' ~flip';
        $flips++;
    }
    cli_writeln(sprintf(
        '  %-44s%s  %2d/%d  sel=%-32s%s',
        $key,
        $cells,
        $pass,
        count($runids),
        $topsel,
        $flag
    ));
}
cli_writeln(str_repeat('-', 78));
cli_writeln(sprintf(
    '%d stable fails (0/%d = real targets) · %d flipping (noise) · %d total scenarios',
    $stablefails,
    count($runids),
    $flips,
    count($matrix)
));
cli_writeln('Judge a change by the stable-fail set, not by a single run\'s percentage.');
