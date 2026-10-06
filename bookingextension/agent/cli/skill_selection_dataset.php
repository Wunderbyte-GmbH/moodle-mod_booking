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
 * CLI: run skill-selection regression dataset.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use bookingextension_agent\local\wizard\services\debug\skill_selection_debug_service;

[$options, $unrecognized] = cli_get_params([
    'help' => false,
    'file' => '',
    'cmid' => 0,
    'userid' => 0,
    'topk' => 10,
    'includeunavailable' => 1,
    'out' => '',
], ['h' => 'help']);

if (!empty($options['help']) || !empty($unrecognized)) {
    $help = "Skill selection dataset runner\n\n"
        . "Options:\n"
        . "--file=PATH                JSON file with test cases (required)\n"
        . "--cmid=ID                  Course module id\n"
        . "--userid=ID                User id\n"
        . "--topk=N                   Candidate list size (default 10)\n"
        . "--includeunavailable=0|1   Include unavailable skills\n"
        . "--out=PATH                 Optional output report file\n"
        . "-h, --help                 Show this help\n";
    echo $help;
    exit(0);
}

$filepath = trim((string)$options['file']);
if ($filepath === '' || !is_readable($filepath)) {
    cli_error('Missing or unreadable --file');
}

$raw = file_get_contents($filepath);
$cases = json_decode((string)$raw, true);
if (!is_array($cases)) {
    cli_error('Dataset file must be a JSON array');
}

$userid = (int)$options['userid'];
if ($userid <= 0) {
    global $USER;
    $userid = (int)($USER->id ?? 0);
}

$service = new skill_selection_debug_service();
$results = [];
$correct = 0;

foreach ($cases as $case) {
    if (!is_array($case)) {
        continue;
    }

    $input = trim((string)($case['input'] ?? ''));
    $expected = trim((string)($case['expected'] ?? ''));
    if ($input === '' || $expected === '') {
        continue;
    }

    $simulation = $service->simulate_selection(
        $input,
        $userid,
        (int)$options['cmid'],
        (int)$options['topk'],
        !empty($options['includeunavailable'])
    );

    $selected = trim((string)($simulation['selected_skill'] ?? ''));
    $alternatives = array_values(array_filter(array_map(
        static fn($v): string => trim((string)$v),
        (array)($case['alternatives'] ?? [])
    )));

    $ok = ($selected === $expected) || in_array($selected, $alternatives, true);
    if ($ok) {
        $correct++;
    }

    $results[] = [
        'id' => (string)($case['id'] ?? ''),
        'input' => $input,
        'expected' => $expected,
        'alternatives' => $alternatives,
        'selected' => $selected,
        'ok' => $ok,
        'candidates' => (array)($simulation['candidates'] ?? []),
    ];
}

$total = count($results);
$accuracy = $total > 0 ? ($correct / $total) : 0.0;
$report = [
    'total' => $total,
    'correct' => $correct,
    'accuracy_top1' => $accuracy,
    'results' => $results,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    cli_error('Failed to encode report');
}

$out = trim((string)$options['out']);
if ($out !== '') {
    file_put_contents($out, $json);
}

echo $json . PHP_EOL;
