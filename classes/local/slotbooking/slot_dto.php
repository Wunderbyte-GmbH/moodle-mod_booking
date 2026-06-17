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
 * Canonical slot data-transfer-object builder.
 *
 * Single source of truth for the slot data structures consumed by the booking picker,
 * the slot calendar report and the move-slot flow. Keeps formatting (labels, prices) in
 * one place so all frontends render identical slot information.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\slotbooking;

use core_date;
use mod_booking\singleton_service;
use moodle_url;

/**
 * Builds the canonical slot DTOs used across the slot booking frontends.
 */
class slot_dto {
    /** @var string[] Slot statuses that are offered for selection in the picker. */
    private const SELECTABLE_STATUSES = ['open', 'warning', 'booked'];

    /**
     * Localised day label for a timestamp (e.g. "Monday, 7 January 2050").
     *
     * @param int $timestamp unix timestamp
     * @return string
     */
    public static function day_label(int $timestamp): string {
        return userdate($timestamp, get_string('strftimedaydate', 'langconfig'));
    }

    /**
     * Localised "HH:MM - HH:MM" time range label for a slot.
     *
     * @param int $start slot start timestamp
     * @param int $end slot end timestamp
     * @return string
     */
    public static function time_range_label(int $start, int $end): string {
        return userdate($start, get_string('strftimetime', 'langconfig'))
            . ' - '
            . userdate($end, get_string('strftimetime', 'langconfig'));
    }

    /**
     * Resolve and format the price for a single slot.
     *
     * @param int $optionid booking option id
     * @param int $slotstart slot start timestamp
     * @param int $slotend slot end timestamp
     * @param int $userid user id for price category resolution
     * @return array{price: float, currency: string, priceformatted: string, pricecategoryidentifier: string}
     */
    public static function price_data(int $optionid, int $slotstart, int $slotend, int $userid = 0): array {
        $data = slot_price::calculate_slot_price_data($optionid, $slotstart, $slotend, $userid);

        $currency = trim((string)($data['currency'] ?? ''));
        $priceformatted = format_float((float)$data['price'], 2);
        if ($currency !== '') {
            $priceformatted .= ' ' . $currency;
        }

        return [
            'price' => (float)$data['price'],
            'currency' => $currency,
            'priceformatted' => $priceformatted,
            'pricecategoryidentifier' => (string)($data['pricecategoryidentifier'] ?? ''),
        ];
    }

    /**
     * Build the canonical picker slot DTOs for an option/user.
     *
     * Returns every selectable slot (open/warning/booked) enriched with labels, status,
     * teachers and price. This is the single structure all picker frontends should consume.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return array<int, array<string, mixed>>
     */
    public static function build_picker_slots(int $optionid, int $userid): array {
        $slots = slot_availability::get_slots_with_status($optionid, $userid);
        $result = [];

        foreach ($slots as $slot) {
            $status = (string)($slot['status'] ?? 'unavailable');
            if (!in_array($status, self::SELECTABLE_STATUSES, true)) {
                continue;
            }

            $start = (int)$slot['start'];
            $end = (int)$slot['end'];
            $pricedata = self::price_data($optionid, $start, $end, $userid);

            $statuslabel = '';
            if ($status === 'warning') {
                $statuslabel = ' (!)';
            } else if ($status === 'booked') {
                $statuslabel = ' (' . get_string('bookingstatusbooked', 'mod_booking') . ')';
            }

            $result[] = [
                'key' => $start . ':' . $end,
                'start' => $start,
                'end' => $end,
                'daykey' => userdate($start, '%Y-%m-%d'),
                'daylabel' => self::day_label($start),
                'timelabel' => self::time_range_label($start, $end),
                'statuslabel' => $statuslabel,
                'status' => $status,
                'selectable' => $status !== 'booked',
                'bookable' => in_array($status, ['open', 'warning'], true),
                'bookings' => (int)($slot['bookings'] ?? 0),
                'capacity' => (int)($slot['capacity'] ?? 0),
                'warningmessage' => (string)($slot['warningmessage'] ?? ''),
                'teachers' => slot_availability::get_available_teachers_for_slot($optionid, $start, $end),
                'price' => $pricedata['price'],
                'currency' => $pricedata['currency'],
                'priceformatted' => $pricedata['priceformatted'],
            ];
        }

        return $result;
    }

    /**
     * Build the picker configuration meta-DTO for an option/user.
     *
     * Consolidates the slot configuration values the frontend needs to drive the picker
     * (max selection, required teachers, view mode, price display, timezone). Mirrors the
     * semantics currently spread across the hidden form inputs in slotbooking_form.
     *
     * @param int $optionid booking option id
     * @param int $userid user id
     * @return array<string, mixed>
     */
    public static function build_meta(int $optionid, int $userid): array {
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $config = $settings->slotconfig ?? null;

        $slottype = (string)($config->slot_type ?? 'fixed');
        $maxselection = max(1, (int)($config->max_slots_per_user ?? 1));
        $teachersrequired = max(0, (int)($config->teachers_required ?? 0));
        if ($slottype === 'userdefined') {
            $teachersrequired = 0;
        }
        $viewmode = in_array((string)($config->booking_interface ?? 'list'), ['list', 'calendar'], true)
            ? (string)$config->booking_interface
            : 'list';

        return [
            'optionid' => $optionid,
            'userid' => $userid,
            'slottype' => $slottype,
            'viewmode' => $viewmode,
            'maxselection' => $maxselection,
            'teachersrequired' => $teachersrequired,
            'useprices' => !empty($settings->useprice) ? 1 : 0,
            'startintervalminutes' => max(1, (int)($config->slot_start_interval_minutes ?? 30)),
            'validfrom' => (int)($config->valid_from ?? 0),
            'validuntil' => (int)($config->valid_until ?? 0),
            'timezone' => (string)core_date::get_user_timezone(),
        ];
    }

    /**
     * Build the booked-slot report data for an option: every slot that has bookings,
     * with the booked students, assigned teachers, occupancy and price, plus a per-slot
     * details map (keyed by "start:end") for the report detail panel.
     *
     * @param int $optionid booking option id
     * @param int $cmid course module id (for the move-slot link)
     * @return array{slots: array<int, array<string, mixed>>, details: array<string, array<string, mixed>>}
     */
    public static function build_report_slots(int $optionid, int $cmid): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/mod/booking/lib.php');

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $config = $settings->slotconfig ?? null;

        $rangefrom = strtotime('-2 years', time());
        $rangeuntil = strtotime('+2 years', time());
        if (!empty($config)) {
            $rangefrom = !empty($config->valid_from) ? (int)$config->valid_from : $rangefrom;
            $rangeuntil = !empty($config->valid_until) ? ((int)$config->valid_until + DAYSECS) : $rangeuntil;
        }

        $slots = slot_availability::get_slots_with_status_for_range($optionid, $rangefrom, $rangeuntil);

        $statuslabels = [
            MOD_BOOKING_STATUSPARAM_BOOKED => get_string('bookingstatusbooked', 'mod_booking'),
            MOD_BOOKING_STATUSPARAM_WAITINGLIST => get_string('bookingstatusonwaitinglist', 'mod_booking'),
            MOD_BOOKING_STATUSPARAM_RESERVED => get_string('bookingstatusreserved', 'mod_booking'),
            MOD_BOOKING_STATUSPARAM_NOTIFYMELIST => get_string('bookingstatusonnotificationlist', 'mod_booking'),
            MOD_BOOKING_STATUSPARAM_PREVIOUSLYBOOKED => get_string('bookingstatuspreviouslybooked', 'mod_booking'),
        ];
        [$waitingsql, $waitingparams] = $DB->get_in_or_equal(array_keys($statuslabels), SQL_PARAMS_NAMED, 'wl');

        $bookinganswers = $DB->get_records_select(
            'booking_answers',
            'optionid = :optionid AND startdate < :rangeuntil AND enddate > :rangefrom AND waitinglist ' . $waitingsql,
            array_merge([
                'optionid' => $optionid,
                'rangefrom' => $rangefrom,
                'rangeuntil' => $rangeuntil,
            ], $waitingparams),
            '',
            'id, userid, startdate, enddate, waitinglist, json'
        );

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

            $teachersperslot = self::resolve_teachers_per_slot($slotdata);
            foreach ($teachersperslot as $entry) {
                $start = (int)($entry['start'] ?? 0);
                $end = (int)($entry['end'] ?? 0);
                if ($start <= 0 || $end <= $start || empty($entry['teachers'])) {
                    continue;
                }
                $slotkey = $start . ':' . $end;
                foreach ($entry['teachers'] as $teacherid) {
                    $teacheridsbyslot[$slotkey][(int)$teacherid] = true;
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

        $calendarslots = [];
        $details = [];
        foreach ($slots as $slot) {
            $start = (int)$slot['start'];
            $end = (int)$slot['end'];
            $capacity = max(1, (int)$slot['capacity']);
            $pricedata = self::price_data($optionid, $start, $end);

            $slotstudents = [];
            $slotbookinganswers = [];
            $slotbookinganswerids = [];
            foreach ($bookingranges as $bookingrange) {
                if ($bookingrange['start'] < $end && $bookingrange['end'] > $start) {
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

            $slotkey = $start . ':' . $end;
            $slotteachers = [];
            if (!empty($teacheridsbyslot[$slotkey])) {
                foreach (array_keys($teacheridsbyslot[$slotkey]) as $teacherid) {
                    if (!empty($teachers[(int)$teacherid])) {
                        $slotteachers[(int)$teacherid] = fullname($teachers[(int)$teacherid]);
                    }
                }
                asort($slotteachers);
            }

            $bookedcount = count($slotstudents);
            $calendarslots[] = [
                'key' => $slotkey,
                'start' => $start,
                'end' => $end,
                'daylabel' => self::day_label($start),
                'timelabel' => self::time_range_label($start, $end) . ' (' . $bookedcount . '/' . $capacity . ')',
                'bookings' => $bookedcount,
                'capacity' => $capacity,
                'price' => $pricedata['price'],
                'currency' => $pricedata['currency'],
                'priceformatted' => $pricedata['priceformatted'],
            ];

            $details[$slotkey] = [
                'startts' => $start,
                'endts' => $end,
                'bookedcount' => $bookedcount,
                'capacity' => $capacity,
                'price' => $pricedata['price'],
                'currency' => $pricedata['currency'],
                'priceformatted' => $pricedata['priceformatted'],
                'students' => array_values($slotstudents),
                'bookinganswers' => array_values($slotbookinganswers),
                'teachers' => array_values($slotteachers),
            ];

            $answerids = array_map('intval', array_keys($slotbookinganswerids));
            if (count($answerids) === 1) {
                $details[$slotkey]['moveurl'] = (new moodle_url('/mod/booking/moveslot.php', [
                    'id' => $cmid,
                    'optionid' => $optionid,
                    'baid' => $answerids[0],
                ]))->out(false);
            }
        }

        return ['slots' => $calendarslots, 'details' => $details];
    }

    /**
     * Resolve the per-slot teacher entries from a booking answer's slot data,
     * falling back to the flat teacher list spread across the booked slots.
     *
     * @param array $slotdata decoded slot payload from a booking answer
     * @return array<int, array{start: int, end: int, teachers: int[]}>
     */
    private static function resolve_teachers_per_slot(array $slotdata): array {
        if (!empty($slotdata['teachers_per_slot']) && is_array($slotdata['teachers_per_slot'])) {
            $result = [];
            foreach ($slotdata['teachers_per_slot'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $result[] = [
                    'start' => (int)($entry['start'] ?? 0),
                    'end' => (int)($entry['end'] ?? 0),
                    'teachers' => self::clean_ids((array)($entry['teachers'] ?? [])),
                ];
            }
            return $result;
        }

        if (
            !empty($slotdata['slots']) && is_array($slotdata['slots'])
            && !empty($slotdata['teachers']) && is_array($slotdata['teachers'])
        ) {
            $fallbackteacherids = self::clean_ids($slotdata['teachers']);
            $result = [];
            foreach ($slotdata['slots'] as $selectedslot) {
                if (!is_array($selectedslot)) {
                    continue;
                }
                $result[] = [
                    'start' => (int)($selectedslot['start'] ?? 0),
                    'end' => (int)($selectedslot['end'] ?? 0),
                    'teachers' => $fallbackteacherids,
                ];
            }
            return $result;
        }

        return [];
    }

    /**
     * Normalise an array of ids to unique positive integers.
     *
     * @param array $ids raw ids
     * @return int[]
     */
    private static function clean_ids(array $ids): array {
        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
    }
}
