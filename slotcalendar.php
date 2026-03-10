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
 * Slot calendar page.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\local\slotbooking\slot_availability;
use mod_booking\local\slotbooking\slot_answer;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/user/lib.php');

$id = required_param('id', PARAM_INT);
$optionid = required_param('optionid', PARAM_INT);
$date = optional_param('date', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/booking:view', $context);

if (empty($date)) {
    $date = time();
}

$viewstart = strtotime('monday this week', $date);
$rangefrom = strtotime('-6 weeks', $viewstart);
$rangeuntil = strtotime('+18 weeks', $viewstart);

$slots = slot_availability::get_slots_with_status_for_range($optionid, $rangefrom, $rangeuntil);

$allowedwaitinglist = [
    MOD_BOOKING_STATUSPARAM_BOOKED,
    MOD_BOOKING_STATUSPARAM_WAITINGLIST,
    MOD_BOOKING_STATUSPARAM_RESERVED,
    MOD_BOOKING_STATUSPARAM_NOTIFYMELIST,
    MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED,
];
[$waitingsql, $waitingparams] = $DB->get_in_or_equal($allowedwaitinglist, SQL_PARAMS_NAMED, 'wl');

$bookinganswers = $DB->get_records_select(
    'booking_answers',
    'optionid = :optionid
        AND startdate < :rangeuntil
        AND enddate > :rangefrom
        AND waitinglist ' . $waitingsql,
    array_merge([
        'optionid' => $optionid,
        'rangefrom' => $rangefrom,
        'rangeuntil' => $rangeuntil,
    ], $waitingparams),
    '',
    'id, userid, startdate, enddate, waitinglist, json'
);

$statuslabels = [
    MOD_BOOKING_STATUSPARAM_BOOKED => get_string('bookingstatusbooked', 'mod_booking'),
    MOD_BOOKING_STATUSPARAM_WAITINGLIST => get_string('bookingstatusonwaitinglist', 'mod_booking'),
    MOD_BOOKING_STATUSPARAM_RESERVED => get_string('bookingstatusreserved', 'mod_booking'),
    MOD_BOOKING_STATUSPARAM_NOTIFYMELIST => get_string('bookingstatusonnotificationlist', 'mod_booking'),
    MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED => get_string('bookingstatuspreviouslybooked', 'mod_booking'),
];

$studentids = [];
$bookingranges = [];
$teacheridsbyslot = [];
foreach ($bookinganswers as $bookinganswer) {
    $studentid = (int)$bookinganswer->userid;
    $studentids[$studentid] = true;
    $bookingranges[] = [
        'baid' => (int)$bookinganswer->id,
        'userid' => $studentid,
        'start' => (int)$bookinganswer->startdate,
        'end' => (int)$bookinganswer->enddate,
        'waitinglist' => (int)$bookinganswer->waitinglist,
    ];

    $slotdata = slot_answer::get_slot_data($bookinganswer);
    if (empty($slotdata) || !is_array($slotdata)) {
        continue;
    }

    $teachersperslot = [];
    if (!empty($slotdata['teachers_per_slot']) && is_array($slotdata['teachers_per_slot'])) {
        $teachersperslot = $slotdata['teachers_per_slot'];
    } else if (
        !empty($slotdata['slots'])
        && is_array($slotdata['slots'])
        && !empty($slotdata['teachers'])
        && is_array($slotdata['teachers'])
    ) {
        $fallbackteacherids = array_values(array_unique(array_filter(
            array_map('intval', $slotdata['teachers']),
            static function (int $id): bool {
                return $id > 0;
            }
        )));

        foreach ($slotdata['slots'] as $selectedslot) {
            if (!is_array($selectedslot)) {
                continue;
            }
            $teachersperslot[] = [
                'start' => (int)($selectedslot['start'] ?? 0),
                'end' => (int)($selectedslot['end'] ?? 0),
                'teachers' => $fallbackteacherids,
            ];
        }
    }

    foreach ($teachersperslot as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $start = (int)($entry['start'] ?? 0);
        $end = (int)($entry['end'] ?? 0);
        if ($start <= 0 || $end <= $start) {
            continue;
        }

        $teacherids = array_values(array_unique(array_filter(
            array_map('intval', (array)($entry['teachers'] ?? [])),
            static function (int $id): bool {
                return $id > 0;
            }
        )));
        if (empty($teacherids)) {
            continue;
        }

        $slotkey = $start . ':' . $end;
        if (!isset($teacheridsbyslot[$slotkey])) {
            $teacheridsbyslot[$slotkey] = [];
        }

        foreach ($teacherids as $teacherid) {
            $teacheridsbyslot[$slotkey][$teacherid] = true;
        }
    }
}

$students = !empty($studentids) ? user_get_users_by_id(array_keys($studentids)) : [];

$allteacherids = [];
foreach ($teacheridsbyslot as $slotteacherids) {
    $allteacherids = array_merge($allteacherids, array_keys($slotteacherids));
}
$allteacherids = array_values(array_unique(array_map('intval', $allteacherids)));
$teachers = !empty($allteacherids) ? user_get_users_by_id($allteacherids) : [];

$events = [];
foreach ($slots as $slot) {
    $capacity = max(1, (int)$slot['capacity']);
    $slotstudents = [];
    $slotbookinganswers = [];
    $slotteachers = [];
    $slotbookinganswerids = [];

    foreach ($bookingranges as $bookingrange) {
        if ($bookingrange['start'] < (int)$slot['end'] && $bookingrange['end'] > (int)$slot['start']) {
            $studentid = (int)$bookingrange['userid'];
            if (!empty($students[$studentid])) {
                $studentname = fullname($students[$studentid]);
                $slotstudents[$studentid] = $studentname;

                $statusid = (int)($bookingrange['waitinglist'] ?? MOD_BOOKING_STATUSPARAM_BOOKED);
                $slotbookinganswers[(int)$bookingrange['baid']] = [
                    'name' => $studentname,
                    'status' => $statuslabels[$statusid] ?? (string)$statusid,
                ];
            }
            if (!empty($bookingrange['baid'])) {
                $slotbookinganswerids[(int)$bookingrange['baid']] = true;
            }
        }
    }

    asort($slotstudents);
    uasort($slotbookinganswers, static function (array $a, array $b): int {
        return strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $slotkey = (int)$slot['start'] . ':' . (int)$slot['end'];
    if (!empty($teacheridsbyslot[$slotkey])) {
        foreach (array_keys($teacheridsbyslot[$slotkey]) as $teacherid) {
            $teacherid = (int)$teacherid;
            if (!empty($teachers[$teacherid])) {
                $slotteachers[$teacherid] = fullname($teachers[$teacherid]);
            }
        }
        asort($slotteachers);
    }

    $events[] = [
        'startts' => (int)$slot['start'],
        'endts' => (int)$slot['end'],
        'capacity' => $capacity,
        'students' => array_values($slotstudents),
        'bookinganswers' => array_values($slotbookinganswers),
        'teachers' => array_values($slotteachers),
        'bookedcount' => count($slotstudents),
        'bookinganswerids' => array_values(array_map('intval', array_keys($slotbookinganswerids))),
    ];
}

$baseurl = new moodle_url('/mod/booking/slotcalendar.php', [
    'id' => $id,
    'optionid' => $optionid,
]);
$reporturl = new moodle_url('/mod/booking/report.php', ['id' => $id, 'optionid' => $optionid]);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('slot_calendar_title', 'mod_booking'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$containerid = 'booking-slot-calendar-report';
$PAGE->requires->js_call_amd('mod_booking/slotCalendarReport', 'init', [$containerid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('slot_calendar_title', 'mod_booking'), 3);

echo html_writer::div(
    html_writer::link($reporturl, get_string('back')),
    'mb-3'
);

if (empty($events)) {
    echo html_writer::div(get_string('none'), 'alert alert-info');
} else {
    $calendarslots = [];
    foreach ($events as $event) {
        $slotkey = (string)$event['startts'] . ':' . (string)$event['endts'];
        $calendarslots[] = [
            'key' => $slotkey,
            'start' => (int)$event['startts'],
            'end' => (int)$event['endts'],
            'daylabel' => userdate((int)$event['startts'], get_string('strftimedaydate', 'langconfig')),
            'timelabel' => userdate((int)$event['startts'], get_string('strftimetime', 'langconfig'))
                . ' - ' . userdate((int)$event['endts'], get_string('strftimetime', 'langconfig'))
                . ' (' . (int)$event['bookedcount'] . '/' . (int)$event['capacity'] . ')',
            'bookings' => (int)$event['bookedcount'],
            'capacity' => (int)$event['capacity'],
        ];
    }

    $slotdetails = [];
    foreach ($events as $event) {
        $slotkey = (string)$event['startts'] . ':' . (string)$event['endts'];
        $slotdetails[$slotkey] = [
            'startts' => (int)$event['startts'],
            'endts' => (int)$event['endts'],
            'bookedcount' => (int)$event['bookedcount'],
            'capacity' => (int)$event['capacity'],
            'students' => array_values($event['students']),
            'bookinganswers' => array_values($event['bookinganswers'] ?? []),
            'teachers' => array_values($event['teachers'] ?? []),
        ];

        if (!empty($event['bookinganswerids']) && count($event['bookinganswerids']) === 1) {
            $slotdetails[$slotkey]['moveurl'] = (new moodle_url('/mod/booking/moveslot.php', [
                'id' => $id,
                'optionid' => $optionid,
                'baid' => (int)$event['bookinganswerids'][0],
            ]))->out(false);
        }
    }

    echo html_writer::start_tag('div', [
        'id' => $containerid,
        'class' => 'booking-slotcalendar-report',
        'data-students-label' => get_string('bookedusers', 'mod_booking'),
        'data-teachers-label' => get_string('slot_calendar_teachers', 'mod_booking'),
        'data-occupancy-label' => get_string('slot_calendar_occupancy', 'mod_booking'),
        'data-moveslot-label' => get_string('slot_move_action', 'mod_booking'),
        'data-selectslot-label' => get_string('slot_calendar_select_slot', 'mod_booking'),
        'data-nobookedslots-label' => get_string('slot_calendar_no_booked_slots', 'mod_booking'),
        'data-none-label' => get_string('none'),
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'calendar_slots_json',
        'value' => json_encode($calendarslots),
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden',
        'name' => 'slot_details_json',
        'value' => json_encode($slotdetails),
    ]);
    echo html_writer::tag('div', '', ['data-region' => 'slot-calendar-picker']);
    echo html_writer::tag('div', '', ['data-region' => 'slot-calendar-students', 'class' => 'mt-3']);
    echo html_writer::end_tag('div');
}

echo $OUTPUT->footer();
