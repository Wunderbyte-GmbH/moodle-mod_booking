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
 * CLI: simulate skill selection for one input.
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
    'input' => '',
    'cmid' => 0,
    'userid' => 0,
    'topk' => 10,
    'includeunavailable' => 1,
], ['h' => 'help']);

if (!empty($options['help']) || !empty($unrecognized)) {
    $help = "Skill selection debug (single input)\n\n"
        . "Options:\n"
        . "--input=TEXT               Input text to classify (required)\n"
        . "--cmid=ID                  Course module id (recommended)\n"
        . "--userid=ID                User id for capability-context filtering\n"
        . "--topk=N                   Max candidates (default 10)\n"
        . "--includeunavailable=0|1   Include unavailable skills in contract base\n"
        . "-h, --help                 Show this help\n";
    echo $help;
    exit(0);
}

$input = trim((string)$options['input']);
if ($input === '') {
    cli_error('Missing required --input');
}

$userid = (int)$options['userid'];
if ($userid <= 0) {
    global $USER;
    $userid = (int)($USER->id ?? 0);
}

$service = new skill_selection_debug_service();
$result = $service->simulate_selection(
    $input,
    $userid,
    (int)$options['cmid'],
    (int)$options['topk'],
    !empty($options['includeunavailable'])
);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
