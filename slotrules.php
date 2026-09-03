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
 * Slot rule editor page.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\form\slotrules_page_form;
use mod_booking\local\slotbooking\slot_rule_manager;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/tablelib.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$ruleid = optional_param('ruleid', 0, PARAM_INT);
$deleteruleid = optional_param('deleteruleid', 0, PARAM_INT);
$confirmdelete = optional_param('confirmdelete', 0, PARAM_BOOL);
$deletepriceid = optional_param('deletepriceid', 0, PARAM_INT);
$confirmdeleteprice = optional_param('confirmdeleteprice', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
if (!has_capability('mod/booking:updatebooking', $context) && !has_capability('mod/booking:manageslotunavailability', $context)) {
    require_capability('mod/booking:updatebooking', $context);
}

$baseurl = new moodle_url('/mod/booking/slotrules.php', [
    'id' => $id,
    'optionid' => $optionid,
]);
$backurl = new moodle_url('/mod/booking/editoptions.php', [
    'id' => $id,
    'optionid' => $optionid,
]);

$dbman = $DB->get_manager();
$hastables = $dbman->table_exists(new xmldb_table('booking_slot_rule'))
    && $dbman->table_exists(new xmldb_table('booking_slot_rule_price'));

if ($hastables && $deleteruleid > 0 && confirm_sesskey()) {
    if (!empty($confirmdelete)) {
        slot_rule_manager::delete_rule($deleteruleid);
        redirect($baseurl, get_string('slot_rule_deleted', 'mod_booking'));
    }

    $PAGE->set_url($baseurl);
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('slot_rule_editor_title', 'mod_booking'));
    $PAGE->set_heading(format_string($course->fullname));

    $continueurl = new moodle_url($baseurl, [
        'deleteruleid' => $deleteruleid,
        'confirmdelete' => 1,
        'sesskey' => sesskey(),
    ]);

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('slot_rule_delete_confirm', 'mod_booking'),
        $continueurl,
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

if ($hastables && $deletepriceid > 0 && confirm_sesskey()) {
    if (!empty($confirmdeleteprice)) {
        slot_rule_manager::delete_rule_price($deletepriceid);
        redirect($baseurl, get_string('slot_rule_price_deleted', 'mod_booking'));
    }

    $PAGE->set_url($baseurl);
    $PAGE->set_context($context);
    $PAGE->set_title(get_string('slot_rule_editor_title', 'mod_booking'));
    $PAGE->set_heading(format_string($course->fullname));

    $continueurl = new moodle_url($baseurl, [
        'deletepriceid' => $deletepriceid,
        'confirmdeleteprice' => 1,
        'sesskey' => sesskey(),
    ]);

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('slot_rule_price_delete_confirm', 'mod_booking'),
        $continueurl,
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

$editrule = null;
$editruleprice = null;
if ($hastables && $ruleid > 0) {
    $editrule = $DB->get_record('booking_slot_rule', ['id' => $ruleid, 'optionid' => $optionid], '*', IGNORE_MISSING);
    if (!empty($editrule)) {
        $editruleprice = $DB->get_record('booking_slot_rule_price', ['ruleid' => (int)$editrule->id], '*', IGNORE_MISSING);
    }
}

$form = new slotrules_page_form(null, [
    'cmid' => $id,
    'optionid' => $optionid,
    'ruleid' => !empty($editrule->id) ? (int)$editrule->id : 0,
]);

if (!empty($editrule)) {
    $defaults = new stdClass();
    $defaults->id = $id;
    $defaults->optionid = $optionid;
    $defaults->ruleid = (int)$editrule->id;
    $defaults->ruletype = (string)$editrule->ruletype;
    $defaults->priority = (int)$editrule->priority;
    $defaults->useactiverange = (!empty($editrule->activefrom) || !empty($editrule->activeuntil)) ? 1 : 0;
    $defaults->activefrom = (int)$editrule->activefrom;
    $defaults->activeuntil = (int)$editrule->activeuntil;
    $defaults->timerangestart = (string)($editrule->timerangestart ?? '');
    $defaults->timerangeend = (string)($editrule->timerangeend ?? '');

    $weekdays = array_filter(array_map('intval', explode(',', (string)($editrule->weekdays ?? ''))));
    foreach (range(1, 7) as $day) {
        $fieldname = 'weekday_' . $day;
        $defaults->{$fieldname} = in_array($day, $weekdays, true) ? 1 : 0;
    }

    if (!empty($editruleprice)) {
        $defaults->pricecategoryidentifier = (string)$editruleprice->pricecategoryidentifier;
        $defaults->pricemode = (string)$editruleprice->mode;
        $defaults->pricevalue = (float)$editruleprice->value;
        $defaults->pricecurrency = (string)($editruleprice->currency ?? '');
    }

    $form->set_data($defaults);
}

if ($form->is_cancelled()) {
    redirect($backurl);
}

if ($hastables && ($data = $form->get_data())) {
    $weekdays = [];
    foreach (range(1, 7) as $day) {
        $fieldname = 'weekday_' . $day;
        if (!empty($data->{$fieldname})) {
            $weekdays[] = $day;
        }
    }

    $ruledata = (object)[
        'id' => (int)($data->ruleid ?? 0),
        'optionid' => (int)$optionid,
        'ruletype' => (string)($data->ruletype ?? slot_rule_manager::RULETYPE_CLOSED),
        'priority' => (int)($data->priority ?? 100),
        'activefrom' => !empty($data->useactiverange) ? (int)($data->activefrom ?? 0) : 0,
        'activeuntil' => !empty($data->useactiverange) ? (int)($data->activeuntil ?? 0) : 0,
        'weekdays' => implode(',', $weekdays),
        'timerangestart' => trim((string)($data->timerangestart ?? '')),
        'timerangeend' => trim((string)($data->timerangeend ?? '')),
    ];

    $savedruleid = slot_rule_manager::save_rule($ruledata);

    if ((string)$ruledata->ruletype === slot_rule_manager::RULETYPE_PRICE) {
        $existingprice = $DB->get_record('booking_slot_rule_price', [
            'ruleid' => $savedruleid,
            'pricecategoryidentifier' => trim((string)($data->pricecategoryidentifier ?? 'default')),
        ], '*', IGNORE_MISSING);

        $pricedata = (object)[
            'id' => !empty($existingprice->id) ? (int)$existingprice->id : 0,
            'ruleid' => $savedruleid,
            'pricecategoryidentifier' => trim((string)($data->pricecategoryidentifier ?? 'default')),
            'mode' => (string)($data->pricemode ?? slot_rule_manager::PRICEMODE_ABSOLUTE),
            'value' => (float)($data->pricevalue ?? 0),
            'currency' => trim((string)($data->pricecurrency ?? '')),
        ];

        slot_rule_manager::save_rule_price($pricedata);
    }

    redirect($baseurl, get_string('slot_rule_saved', 'mod_booking'));
}

$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_title(get_string('slot_rule_editor_title', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('slot_rule_editor_title', 'mod_booking'), 3);

echo html_writer::div(
    html_writer::link($backurl, get_string('back')),
    'mb-3'
);

if (!$hastables) {
    echo $OUTPUT->notification(get_string('slot_rule_tables_missing', 'mod_booking'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

$form->display();

$rules = $DB->get_records('booking_slot_rule', ['optionid' => $optionid], 'priority DESC, id ASC');

echo html_writer::tag('h4', get_string('slot_rule_existing', 'mod_booking'));

if (empty($rules)) {
    echo $OUTPUT->notification(get_string('slot_rule_none', 'mod_booking'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('idnumber'),
    get_string('slot_rule_type', 'mod_booking'),
    get_string('slot_rule_priority', 'mod_booking'),
    get_string('slot_rule_active_range', 'mod_booking'),
    get_string('slot_rule_timewindow', 'mod_booking'),
    get_string('slot_rule_weekdays', 'mod_booking'),
    get_string('slot_rule_price_summary', 'mod_booking'),
    get_string('actions'),
];

foreach ($rules as $rule) {
    $timerange = trim((string)($rule->timerangestart ?? '')) . ' - ' . trim((string)($rule->timerangeend ?? ''));
    if ($timerange === ' - ') {
        $timerange = '-';
    }

    $weekdays = (string)($rule->weekdays ?? '');
    if ($weekdays !== '') {
        $weekdaylabels = [];
        $weekdayids = array_filter(array_map('intval', explode(',', $weekdays)));
        $weekdaymap = [
            1 => get_string('slot_day_mon', 'mod_booking'),
            2 => get_string('slot_day_tue', 'mod_booking'),
            3 => get_string('slot_day_wed', 'mod_booking'),
            4 => get_string('slot_day_thu', 'mod_booking'),
            5 => get_string('slot_day_fri', 'mod_booking'),
            6 => get_string('slot_day_sat', 'mod_booking'),
            7 => get_string('slot_day_sun', 'mod_booking'),
        ];
        foreach ($weekdayids as $weekdayid) {
            if (!empty($weekdaymap[$weekdayid])) {
                $weekdaylabels[] = $weekdaymap[$weekdayid];
            }
        }
        $weekdays = !empty($weekdaylabels) ? implode(', ', $weekdaylabels) : '-';
    } else {
        $weekdays = '-';
    }

    $activerange = '-';
    if (!empty($rule->activefrom) || !empty($rule->activeuntil)) {
        $from = !empty($rule->activefrom) ? userdate((int)$rule->activefrom) : '-';
        $until = !empty($rule->activeuntil) ? userdate((int)$rule->activeuntil) : '-';
        $activerange = $from . ' - ' . $until;
    }

    $pricesummary = '-';
    if ((string)$rule->ruletype === slot_rule_manager::RULETYPE_PRICE) {
        $pricerecords = $DB->get_records('booking_slot_rule_price', ['ruleid' => (int)$rule->id], 'id ASC');
        if (!empty($pricerecords)) {
            $parts = [];
            foreach ($pricerecords as $record) {
                $deletepriceurl = new moodle_url($baseurl, [
                    'deletepriceid' => (int)$record->id,
                    'confirmdeleteprice' => 0,
                    'sesskey' => sesskey(),
                ]);
                $description = s($record->pricecategoryidentifier)
                    . ': '
                    . s($record->mode)
                    . ' '
                    . format_float((float)$record->value, 2);
                $deletepricelink = html_writer::link($deletepriceurl, get_string('delete'));
                $parts[] = $description . ' (' . $deletepricelink . ')';
            }
            $pricesummary = implode('<br>', $parts);
        }
    }

    $editurl = new moodle_url($baseurl, ['ruleid' => (int)$rule->id]);
    $deleteurl = new moodle_url($baseurl, [
        'deleteruleid' => (int)$rule->id,
        'confirmdelete' => 0,
        'sesskey' => sesskey(),
    ]);

    $actions = html_writer::link($editurl, get_string('edit'));
    $actions .= ' | ';
    $actions .= html_writer::link($deleteurl, get_string('delete'));

    $table->data[] = [
        (int)$rule->id,
        get_string('slot_rule_type_' . $rule->ruletype, 'mod_booking'),
        (int)$rule->priority,
        $activerange,
        $timerange,
        $weekdays,
        $pricesummary,
        $actions,
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
