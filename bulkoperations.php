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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Bulk operations dashboard.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\shortcodes;
use mod_booking\utils\wb_payment;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// No guest autologin.
require_login(0, false);

$context = \context_system::instance();
$hascapability = has_capability('mod/booking:executebulkoperations', $context);

$pageurl = new \moodle_url('/mod/booking/bulkoperations.php');

if ($hascapability) {
    admin_externalpage_setup(
        'modbookingbulkoperations',
        '',
        null,
        '',
        ['pagelayout' => 'report']
    );
} else {
    // Users without the capability get a warning instead of the table,
    // so skip admin_externalpage_setup() which would deny access outright.
    $PAGE->set_pagelayout('standard');
}

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($SITE->shortname) . ': ' . get_string('bulkoperationspro', 'mod_booking'));
$PAGE->navbar->add(get_string('bulkoperationspro', 'mod_booking'), $pageurl);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bulkoperationspro', 'mod_booking'));

if (!$hascapability) {
    echo html_writer::div(get_string('nocapabilitytoaccesspage', 'mod_booking'), 'alert alert-warning');
} else if (wb_payment::pro_version_is_activated()) {
    // Render the same table as the [bulkoperations] shortcode.
    $env = new stdClass();
    $next = function () {
    };
    echo shortcodes::bulkoperations('bulkoperations', [], null, $env, $next);
} else {
    echo html_writer::div(get_string('infotext:prolicensenecessarytextandlink', 'mod_booking'), 'alert alert-warning');
}

echo $OUTPUT->footer();
