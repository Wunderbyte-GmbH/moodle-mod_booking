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
 * Move slot action for booking answers.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\event\bookinganswer_slotmoved;
use mod_booking\local\slotbooking\slot_answer;
use mod_booking\local\slotbooking\slot_availability;
use mod_booking\booking_option;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$baid = required_param('baid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
$canmoveslots = has_capability('mod/booking:moveslots', $context)
    || has_capability('mod/booking:updatebooking', $context);
if (!$canmoveslots) {
    require_capability('mod/booking:moveslots', $context);
}

$answer = $DB->get_record('booking_answers', ['id' => $baid, 'optionid' => $optionid], '*', MUST_EXIST);
$user = $DB->get_record('user', ['id' => $answer->userid], '*', MUST_EXIST);

$slotdata = slot_answer::get_slot_data($answer);
if (empty($slotdata)) {
    throw new moodle_exception('invaliddata');
}

$currentslots = [];
if (!empty($slotdata['slots']) && is_array($slotdata['slots'])) {
    foreach ($slotdata['slots'] as $slot) {
        if (!is_array($slot) || !isset($slot['start']) || !isset($slot['end'])) {
            continue;
        }

        $start = (int)$slot['start'];
        $end = (int)$slot['end'];
        if ($end <= $start) {
            continue;
        }

        $currentslots[] = [
            'start' => $start,
            'end' => $end,
        ];
    }
}

if (empty($currentslots)) {
    $oldstart = (int)($answer->startdate ?? 0);
    $oldend = (int)($answer->enddate ?? 0);
    if ($oldend > $oldstart) {
        $currentslots[] = [
            'start' => $oldstart,
            'end' => $oldend,
        ];
    }
}

if (empty($currentslots)) {
    throw new moodle_exception('invaliddata');
}

usort($currentslots, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

$requiredslotcount = max(1, count($currentslots));
$currentslotkeys = [];
foreach ($currentslots as $slot) {
    $currentslotkeys[] = $slot['start'] . ':' . $slot['end'];
}
$currentslotkeyset = array_fill_keys($currentslotkeys, true);

$rangefrom = time();
$rangeuntil = strtotime('+30 days', $rangefrom);
$slots = slot_availability::get_slots_with_status_for_range($optionid, $rangefrom, $rangeuntil, (int)$answer->userid);

$calendarslots = [];
$calendarslotkeyset = [];
foreach ($slots as $slot) {
    if (($slot['status'] ?? '') !== 'open') {
        continue;
    }

    $start = (int)$slot['start'];
    $end = (int)$slot['end'];
    $key = $start . ':' . $end;

    $calendarslotkeyset[$key] = true;
    $calendarslots[] = [
        'key' => $key,
        'start' => $start,
        'end' => $end,
        'daylabel' => userdate($start, get_string('strftimedate', 'langconfig')),
        'timelabel' => userdate($start, get_string('strftimetime', 'langconfig'))
            . ' - ' . userdate($end, get_string('strftimetime', 'langconfig')),
    ];
}

foreach ($currentslots as $slot) {
    $key = $slot['start'] . ':' . $slot['end'];
    if (!empty($calendarslotkeyset[$key])) {
        continue;
    }

    $start = (int)$slot['start'];
    $end = (int)$slot['end'];
    $calendarslots[] = [
        'key' => $key,
        'start' => $start,
        'end' => $end,
        'daylabel' => userdate($start, get_string('strftimedate', 'langconfig')),
        'timelabel' => userdate($start, get_string('strftimetime', 'langconfig'))
            . ' - ' . userdate($end, get_string('strftimetime', 'langconfig')),
    ];
}

usort($calendarslots, static fn(array $a, array $b): int => $a['start'] === $b['start']
    ? ($a['end'] <=> $b['end'])
    : ($a['start'] <=> $b['start']));

$baseurl = new moodle_url('/mod/booking/moveslot.php', [
    'id' => $id,
    'optionid' => $optionid,
    'baid' => $baid,
]);

if (optional_param('savemoveslot', 0, PARAM_INT) && confirm_sesskey()) {
    $selectedslotsraw = required_param('selectedslots', PARAM_RAW_TRIMMED);
    $movereason = optional_param('movereason', '', PARAM_TEXT);

    $selectedslotkeys = array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', $selectedslotsraw)
    ))));

    if (count($selectedslotkeys) !== $requiredslotcount) {
        redirect($baseurl, get_string('slot_move_select', 'mod_booking'), null, \core\output\notification::NOTIFY_ERROR);
    }

    $newslots = [];
    foreach ($selectedslotkeys as $key) {
        $parts = explode(':', $key);
        if (count($parts) !== 2) {
            continue;
        }

        $newstart = (int)$parts[0];
        $newend = (int)$parts[1];
        if ($newend <= $newstart) {
            continue;
        }

        $issameascurrent = !empty($currentslotkeyset[$key]);
        if (
            !$issameascurrent && !slot_availability::is_slot_available(
                $optionid,
                $newstart,
                $newend,
                (int)$answer->userid,
                [],
                $baid
            )
        ) {
            continue;
        }

        $newslots[] = [
            'start' => $newstart,
            'end' => $newend,
        ];
    }

    if (count($newslots) !== $requiredslotcount) {
        redirect($baseurl, get_string('slot_move_select', 'mod_booking'), null, \core\output\notification::NOTIFY_ERROR);
    }

    usort($newslots, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

    $oldstart = (int)($answer->startdate ?? 0);
    $oldend = (int)($answer->enddate ?? 0);

    $newstart = (int)$newslots[0]['start'];
    $newend = (int)$newslots[count($newslots) - 1]['end'];

    $answer->startdate = $newstart;
    $answer->enddate = $newend;

    $oldslots = $slotdata['slots'] ?? [];
    if (!empty($oldslots) && is_array($oldslots)) {
        $first = reset($oldslots);
        $last = end($oldslots);
        if (is_array($first) && is_array($last) && isset($first['start']) && isset($last['end'])) {
            $slotdata['moved_from'] = [
                'start' => (int)$first['start'],
                'end' => (int)$last['end'],
            ];
        }
    }

    $slotdata['slots'] = $newslots;
    $slotdata['move_reason'] = $movereason;

    slot_answer::set_slot_data($answer, $slotdata);
    $DB->update_record('booking_answers', $answer);

    // Ensure all booking answer caches reflect the moved slot immediately.
    booking_option::purge_cache_for_answers($optionid);
    singleton_service::destroy_answers_for_user((int)$answer->userid, (int)$cm->instance);

    $event = bookinganswer_slotmoved::create([
        'objectid' => $baid,
        'context' => $context,
        'relateduserid' => $answer->userid,
        'other' => [
            'optionid' => $optionid,
            'baid' => $baid,
            'oldstart' => $oldstart,
            'oldend' => $oldend,
            'newstart' => $newstart,
            'newend' => $newend,
            'oldslots' => $currentslots,
            'newslots' => $newslots,
            'bookedslots' => $newslots,
            'slotcount' => count($newslots),
            'reason' => $movereason,
        ],
    ]);
    $event->trigger();

    $subject = get_string('slot_move_notification_subject', 'mod_booking');
    $body = get_string('slot_move_notification_body', 'mod_booking', (object)[
        'start' => userdate($newstart, get_string('strftimedatetime', 'langconfig')),
        'end' => userdate($newend, get_string('strftimedatetime', 'langconfig')),
        'reason' => $movereason,
    ]);

    email_to_user($user, core_user::get_noreply_user(), $subject, $body);

    $teacherids = [];
    if (!empty($slotdata['teachers']) && is_array($slotdata['teachers'])) {
        $teacherids = array_values(array_unique(array_filter(array_map('intval', $slotdata['teachers']))));
    }

    if (!empty($teacherids)) {
        $teachers = $DB->get_records_list('user', 'id', $teacherids);
        foreach ($teachers as $teacher) {
            email_to_user($teacher, core_user::get_noreply_user(), $subject, $body);
        }
    }

    redirect(
        new moodle_url('/mod/booking/report.php', ['id' => $id, 'optionid' => $optionid]),
        get_string('slot_move_success', 'mod_booking')
    );
}

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('slot_move_action', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$containerid = 'booking-move-slot-form';
$PAGE->requires->js_call_amd('mod_booking/moveSlot', 'init', [$containerid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('slot_move_action', 'mod_booking'), 3);

echo html_writer::start_tag('div', ['id' => $containerid, 'class' => 'booking-move-slot']);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => $baseurl->out(false), 'class' => 'w-100']);

echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savemoveslot', 'value' => 1]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'selectedslots',
    'id' => 'selectedslots',
    'value' => implode(',', $currentslotkeys),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'calendar_slots_json',
    'id' => 'calendar_slots_json',
    'value' => json_encode($calendarslots),
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'required_slot_count',
    'id' => 'required_slot_count',
    'value' => $requiredslotcount,
]);

echo html_writer::tag('div', get_string('slot_move_select', 'mod_booking'), ['class' => 'font-weight-bold mb-1']);

echo html_writer::start_tag('ul', ['class' => 'small text-muted pl-3 mb-2']);
foreach ($currentslots as $slot) {
    $label = userdate((int)$slot['start'], get_string('strftimedatetime', 'langconfig'))
        . ' - ' . userdate((int)$slot['end'], get_string('strftimedatetime', 'langconfig'));
    echo html_writer::tag('li', $label);
}
echo html_writer::end_tag('ul');

echo html_writer::tag('div', '', ['data-region' => 'slot-calendar-picker']);

echo html_writer::empty_tag('br');
echo html_writer::tag('label', get_string('slot_move_reason', 'mod_booking'), ['for' => 'movereason']);
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'name' => 'movereason',
    'id' => 'movereason',
    'size' => 60,
    'class' => 'w-100',
]);

echo html_writer::empty_tag('br');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary btn-sm mt-2',
    'value' => get_string('savechanges'),
]);

echo html_writer::end_tag('form');
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
