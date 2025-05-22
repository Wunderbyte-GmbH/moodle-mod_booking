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
 * Semesters settings
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\form\dynamicchangesemesterform;
use mod_booking\form\dynamicholidaysform;
use mod_booking\form\dynamicsemestersform;
use mod_booking\output\semesters_holidays;

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $OUTPUT;

// No guest autologin.
require_login(0, false);

$cmid = optional_param('id', 0, PARAM_INT);

admin_externalpage_setup(
    'modbookingsemesters',
    '',
    [],
    new moodle_url('/mod/booking/semesters.php')
);

$pageurl = new moodle_url('/mod/booking/semesters.php');
$PAGE->set_url($pageurl);

$PAGE->set_title(
    format_string($SITE->shortname) . ': ' . get_string('semesters', 'booking')
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('semesters', 'mod_booking'));

$semestersform = new dynamicsemestersform();
$semestersform->set_data_for_dynamic_submission();
$renderedsemestersform = $semestersform->render();

$holidaysform = new dynamicholidaysform();
$holidaysform->set_data_for_dynamic_submission();
$renderedholidaysform = $holidaysform->render();

if ($cmid) {
    $changesemesterform = new dynamicchangesemesterform();

    $changesemesterform->set_data_for_dynamic_submission();
    $renderedchangesemesterform = $changesemesterform->render();
} else {
    $renderedchangesemesterform = '';
}

$output = $PAGE->get_renderer('mod_booking');
$data = new semesters_holidays($renderedsemestersform, $renderedholidaysform, $renderedchangesemesterform);
echo $output->render_semesters_holidays($data);

$PAGE->requires->js_call_amd(
    'mod_booking/dynamicsemestersform',
    'init',
    ['[data-region=semestersformcontainer]', dynamicsemestersform::class]
);

$PAGE->requires->js_call_amd(
    'mod_booking/dynamicholidaysform',
    'init',
    ['[data-region=holidaysformcontainer]', dynamicholidaysform::class]
);

if ($cmid) {
    $data = new stdClass();
    $data->cmid = $cmid;
    $existingsemester = $data;
    $PAGE->requires->js_call_amd(
        'mod_booking/dynamicchangesemesterform',
        'init',
        ['[data-region=changesemestercontainer]', dynamicchangesemesterform::class, $existingsemester]
    );
}

echo $OUTPUT->footer();
