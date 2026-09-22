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
 * Sends or gives up on rule mails that the bulk send checker parked.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\local\bulk_check\bulk_check;
use mod_booking\output\bulkcheck;

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('mod/booking:managebulkcheck', $context);

$ruleid = optional_param('ruleid', 0, PARAM_INT);

$PAGE->set_context($context);
$PAGE->set_url('/mod/booking/bulkcheck.php', empty($ruleid) ? [] : ['ruleid' => $ruleid]);
$PAGE->set_pagelayout('admin');
$PAGE->set_heading(get_string('bulkcheckparked', 'mod_booking'));
$PAGE->set_title(get_string('bulkcheckparked', 'mod_booking'));
$PAGE->add_body_class('limitedwidth');

// Deliberately no check on bulkcheckenabled: switching the feature off must never hide
// mails that are already parked, or they would be abandoned with no way to reach them.

/** @var \mod_booking\output\renderer $output */
$output = $PAGE->get_renderer('mod_booking');

echo $output->header();
echo $output->heading(get_string('bulkcheckparked', 'mod_booking'));

if (!empty($ruleid)) {
    // Scoping the list also scopes the two buttons that act on everything, so it has to be
    // said plainly which rule they are about.
    echo html_writer::div(
        get_string('bulkcheckscopedintro', 'mod_booking', bulk_check::get_rule_name($ruleid)) . ' ' .
        html_writer::link(
            new moodle_url('/mod/booking/bulkcheck.php'),
            get_string('bulkcheckshowallrules', 'mod_booking')
        ),
        'alert alert-secondary'
    );
}

echo $output->render_bulkcheck(new bulkcheck($ruleid));
echo $output->footer();
