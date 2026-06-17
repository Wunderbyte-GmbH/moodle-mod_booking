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
 * Page to manage the option hierarchy of a dynamicformat mod_booking custom field.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      David Ala
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_booking\form\create_dynamicfield_form;
use mod_booking\form\customfield_options_form;
use mod_booking\customfield\hierarchy_manager;

$fieldid = optional_param('fieldid', 0, PARAM_INT);

require_login();

$context = context_system::instance();
require_capability('mod/booking:managecustomfieldoptions', $context);

$baseurl = new moodle_url('/mod/booking/managecustomfieldoptions.php');
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url($baseurl, ['fieldid' => $fieldid]));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('managecustomfieldoptions', 'mod_booking'));
$PAGE->set_heading(get_string('managecustomfieldoptions', 'mod_booking'));

$fieldoptions = hierarchy_manager::get_manageable_fields();

// Validate the requested field id against the manageable set.
if ($fieldid && !isset($fieldoptions[$fieldid])) {
    $fieldid = 0;
}

$categories = hierarchy_manager::get_categories();

// Form to create a brand new dynamicformat field.
$createform = new create_dynamicfield_form(
    new moodle_url($baseurl, ['fieldid' => $fieldid]),
    ['categories' => $categories]
);
if ($createdata = $createform->get_data()) {
    $newid = hierarchy_manager::create_field(
        trim($createdata->newfieldname),
        trim($createdata->newfieldshortname),
        (int) ($createdata->newfieldcategory ?? 0)
    );
    redirect(
        new moodle_url($baseurl, ['fieldid' => $newid]),
        get_string('fieldcreated', 'mod_booking'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$rows = $fieldid ? hierarchy_manager::load_rows($fieldid) : [];
$nextid = $fieldid ? hierarchy_manager::get_nextid($fieldid) : 1;

$mform = new customfield_options_form(new moodle_url($baseurl, ['fieldid' => $fieldid]), [
    'fieldid' => $fieldid,
    'fieldoptions' => $fieldoptions,
    'rows' => $rows,
    'nextid' => $nextid,
]);

if ($mform->is_cancelled()) {
    redirect($baseurl);
} else if (optional_param('switchfield', '', PARAM_RAW) !== '') {
    // The "switch field" button reloads the page cleanly for the newly chosen field.
    // Other no-submit buttons (e.g. "Add option") fall through and just re-render the form.
    redirect(new moodle_url($baseurl, ['fieldid' => $fieldid]));
} else if ($data = $mform->get_data()) {
    $submittedrows = customfield_options_form::extract_rows($data);
    hierarchy_manager::save((int) $data->fieldid, $submittedrows);
    redirect(
        new moodle_url($baseurl, ['fieldid' => (int) $data->fieldid]),
        get_string('changessaved'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managecustomfieldoptions', 'mod_booking'));

if (empty($fieldoptions)) {
    echo $OUTPUT->notification(
        get_string('error_nomanageablefields', 'mod_booking'),
        \core\output\notification::NOTIFY_INFO
    );
}

$createform->display();
$mform->display();
echo $OUTPUT->footer();
