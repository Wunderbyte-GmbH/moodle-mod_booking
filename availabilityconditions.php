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
 * Availability conditions dashboard.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\condition_state_helper;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login(0, false);

$context = \context_system::instance();
$pageurl = new \moodle_url('/mod/booking/availabilityconditions.php');

admin_externalpage_setup(
    'modbookingavailabilityconditions',
    '',
    null,
    '',
    ['pagelayout' => 'report']
);

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($SITE->shortname) . ': ' . get_string('availabilityconditionsdashboard', 'mod_booking'));
$PAGE->navbar->add(get_string('availabilityconditionsdashboard', 'mod_booking'), $pageurl);

$statehelper = new condition_state_helper();

if (optional_param('save', 0, PARAM_BOOL) && confirm_sesskey()) {
    $submittedstates = optional_param_array('state', [], PARAM_INT);
    $savedstates = [];

    foreach ($submittedstates as $conditionid => $state) {
        $state = (int)$state;
        if (in_array($state, [condition_state_helper::STATE_FREEZE, condition_state_helper::STATE_SKIP_AND_FREEZE], true)) {
            $savedstates[(int)$conditionid] = [
                'skipstate' => $state,
            ];
        }
    }

    set_config('availabilityconditionsettings', json_encode($savedstates), 'booking');
    redirect($pageurl, get_string('changessaved'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$conditions = bo_info::get_skippable_conditions();
ksort($conditions, SORT_NUMERIC);

// Map condition IDs to their first anchor on the plugin settings page.
// The Moodle admin settings page renders each setting with id="admin-<key>".
// Add an entry here whenever a condition has dedicated settings on that page.
$conditionsettingsanchors = [
    MOD_BOOKING_BO_COND_BOOKING_TIME     => 'bookingtimerelativeenabled',
    MOD_BOOKING_BO_COND_JSON_NOOVERLAPPING => 'defaultnooverlappingoncreate',
];

$adminsettingsbaseurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingbooking']);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('availabilityconditionsdashboard', 'mod_booking'));
echo $OUTPUT->box(get_string('availabilityconditionsdashboard_desc', 'mod_booking'));

if (
    get_config('booking', 'availabilityconditionsettings') === false
    && get_config('booking', 'availabilityconditionstates') === false
    && (
        !empty(get_config('booking', 'skipableconditions'))
        || !empty(get_config('booking', 'enrollinkskipconditions'))
    )
) {
    echo $OUTPUT->notification(get_string('availabilityconditionslegacynotice', 'mod_booking'), 'info');
}

echo \html_writer::start_tag('form', ['method' => 'post', 'action' => $pageurl->out(false)]);
echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'save', 'value' => 1]);

echo \html_writer::start_tag('table', ['class' => 'generaltable fullwidth']);
echo \html_writer::start_tag('thead');
echo \html_writer::tag(
    'tr',
    \html_writer::tag('th', get_string('availabilityconditions', 'mod_booking')) .
    \html_writer::tag(
        'th',
        get_string('availabilityconditionsstatecolumn', 'mod_booking') .
        $OUTPUT->help_icon('availabilityconditionsstatecolumn', 'mod_booking')
    ) .
    \html_writer::tag(
        'th',
        get_string('availabilityconditionssettingscolumn', 'mod_booking') .
        $OUTPUT->help_icon('availabilityconditionssettingscolumn', 'mod_booking')
    )
);
echo \html_writer::end_tag('thead');
echo \html_writer::start_tag('tbody');

$stateoptions = [
    condition_state_helper::STATE_INACTIVE => get_string('availabilityconditionstatedefault', 'mod_booking'),
    condition_state_helper::STATE_FREEZE => get_string('availabilityconditionstatefreeze', 'mod_booking'),
    condition_state_helper::STATE_SKIP_AND_FREEZE => get_string('availabilityconditionstateskipandfreeze', 'mod_booking'),
];

foreach ($conditions as $conditionid => $conditionname) {
    $conditionid = (int)$conditionid;
    $currentstate = $statehelper->get_condition_state($conditionid);
    $select = \html_writer::select(
        $stateoptions,
        'state[' . $conditionid . ']',
        $currentstate,
        false,
        ['class' => 'custom-select']
    );

    if (isset($conditionsettingsanchors[$conditionid])) {
        $settingsurl = clone $adminsettingsbaseurl;
        $settingsurl->set_anchor('admin-' . $conditionsettingsanchors[$conditionid]);
        $settingscell = \html_writer::link(
            $settingsurl,
            get_string('availabilityconditionssettingslink', 'mod_booking'),
            ['class' => 'btn btn-sm btn-secondary']
        );
    } else {
        $settingscell = \html_writer::tag('span', '-', ['class' => 'text-muted']);
    }

    echo \html_writer::tag(
        'tr',
        \html_writer::tag('td', s($conditionname)) .
        \html_writer::tag('td', $select) .
        \html_writer::tag('td', $settingscell)
    );
}

echo \html_writer::end_tag('tbody');
echo \html_writer::end_tag('table');

echo \html_writer::div(
    \html_writer::empty_tag('input', [
        'type' => 'submit',
        'value' => get_string('savechanges'),
        'class' => 'btn btn-primary',
    ]),
    'mt-3'
);

echo \html_writer::end_tag('form');
echo $OUTPUT->footer();
