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
 * Rule edit form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
global $DB;

use mod_booking\local\performance\actions\action_registry;
use mod_booking\local\performance\performance_renderer;

// No guest autologin.
require_login(0, false);

$context = context_system::instance();
require_capability('mod/booking:viewperformance', $context);

$PAGE->set_context($context);

$url = new moodle_url('/mod/booking/performance.php', []);
$PAGE->set_url($url);

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . 'Performance'
);

/** @var \mod_booking\output\renderer $output */
$output = $PAGE->get_renderer('mod_booking');

echo $output->header();

$performancerendere = new performance_renderer();
$sidebarconstruct = $performancerendere->get_sidebar();

$hash = $performancerendere->get_default_hash();
$chartconstruct = $performancerendere->get_chart($hash);

$templatecontext = [
    'title' => 'Performance!',
    'message' => 'This is content rendered using a Mustache template.',
    'sidebar' => $sidebarconstruct['sidebar'] ?? [],
    'autocompleteitems' => $sidebarconstruct['autocompleteitems'] ?? [],
    'actions' => action_registry::export_all_for_template($output),
];

$templatecontext['chart'] = [
    'labelsjson' => $chartconstruct['labelsjson'],
    'datasetsjson' => $chartconstruct['datasetsjson'],
];

echo $output->render_from_template('mod_booking/performance/performance', $templatecontext);
echo $output->footer();
