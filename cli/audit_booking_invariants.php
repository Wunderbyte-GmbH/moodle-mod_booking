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
 * Read-only DB invariant audit for mod_booking booking answers.
 *
 * Purpose: after a concurrency / load test (e.g. the JMeter shopping-cart plan) this script
 * verifies that the booking_answers table is in a consistent state across all booking states -
 * i.e. that no race condition produced an overbooking, an over-full waiting list, or a duplicate
 * active answer. It runs only SELECTs and never writes, so it is safe to run against a live site.
 *
 * State model (mirrors \mod_booking\booking_answers): a place is consumed by an answer whose
 * waitinglist is BOOKED (0) or RESERVED (2) - a cart reservation already holds a seat. The
 * waiting list is waitinglist = WAITINGLIST (1). All other states (notify list, deleted, previously
 * booked, the *_DELETED / event-marker states >= 5) do not consume capacity.
 *
 * Exit codes: 0 = all invariants hold, 2 = at least one violation found, 1 = usage error.
 *
 * Usage (run from the Moodle web root):
 *   php mod/booking/cli/audit_booking_invariants.php
 *   php mod/booking/cli/audit_booking_invariants.php --courseid=123
 *   php mod/booking/cli/audit_booking_invariants.php --optionids=10,11,12 --summary
 *   php mod/booking/cli/audit_booking_invariants.php --reserved-ttl=3600 --check-enrolment
 *   php mod/booking/cli/audit_booking_invariants.php --format=json
 *
 * Typical JMeter integration: add a final shell step after the run that calls this script and
 * fails the build on a non-zero exit code.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
// Loads the MOD_BOOKING_STATUSPARAM_* constants used in the invariant queries.
require_once($CFG->dirroot . '/mod/booking/lib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help'            => false,
        'optionids'       => '',
        'bookingid'       => '',
        'courseid'        => '',
        'reserved-ttl'    => 0,
        'check-enrolment' => false,
        'summary'         => false,
        'format'          => 'text',
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'core_admin', $unrecognised));
}

if ($options['help']) {
    echo "Read-only DB invariant audit for mod_booking booking answers.

Verifies there are no overbookings, no over-full waiting lists and no duplicate active answers -
useful as the assertion layer after a JMeter / concurrency load test.

Options:
  -h, --help          Print this help.
  --optionids=a,b,c   Restrict the audit to these booking option ids (comma separated).
  --bookingid=N       Restrict to options of this booking instance (booking_options.bookingid).
  --courseid=N        Restrict to options whose booking instance lives in this course.
  --reserved-ttl=SEC  Also flag cart reservations (waitinglist=2) older than SEC seconds as
                      probable orphans. 0 (default) disables this soft check.
  --check-enrolment   Also flag BOOKED users who are not enrolled in the option's target course.
                      Best-effort: only checked for options with a target courseid > 0.
  --summary           Print per-option occupancy even when no violation is found.
  --format=text|json  Output format (default text).

Scope filters combine: if several are given the result is their intersection. With no filter the
whole site is audited (only options with limitanswers = 1 are capacity-checked).

Exit codes: 0 = clean, 2 = violations found, 1 = usage error.

Examples:
  php mod/booking/cli/audit_booking_invariants.php --courseid=123 --summary
  php mod/booking/cli/audit_booking_invariants.php --optionids=10,11 --reserved-ttl=1800
";
    exit(0);
}

$asjson = ($options['format'] === 'json');

// Build the (optional) option-scope filter on alias bo.
$scopewheres = [];
$scopeparams = [];

if (trim((string)$options['optionids']) !== '') {
    $ids = array_filter(array_map('intval', explode(',', $options['optionids'])));
    if ($ids) {
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'oid');
        $scopewheres[] = "bo.id $insql";
        $scopeparams += $inparams;
    }
}
if (trim((string)$options['bookingid']) !== '') {
    $scopewheres[] = 'bo.bookingid = :bookingid';
    $scopeparams['bookingid'] = (int)$options['bookingid'];
}
if (trim((string)$options['courseid']) !== '') {
    // Maps booking_options.bookingid -> booking.id; booking.course = courseid.
    $scopewheres[] = 'bo.bookingid IN (SELECT b.id FROM {booking} b WHERE b.course = :courseid)';
    $scopeparams['courseid'] = (int)$options['courseid'];
}
$scopesql = $scopewheres ? (' AND ' . implode(' AND ', $scopewheres)) : '';

// Capacity-consuming and waiting-list states.
$placestates = MOD_BOOKING_STATUSPARAM_BOOKED . ',' . MOD_BOOKING_STATUSPARAM_RESERVED; // 0,2.
$waitstate   = MOD_BOOKING_STATUSPARAM_WAITINGLIST; // 1.
// Active states a user may hold at most one of, per option (booked / reserved / waiting list).
$activestates = MOD_BOOKING_STATUSPARAM_BOOKED . ',' . MOD_BOOKING_STATUSPARAM_WAITINGLIST . ','
    . MOD_BOOKING_STATUSPARAM_RESERVED; // Status params 0, 1, 2.

$violations = [];
$summaryrows = [];

// INV1: no overbooking (booked + reserved places must not exceed maxanswers).
$sql = "SELECT bo.id AS optionid, bo.text, bo.maxanswers,
               COALESCE(SUM(COALESCE(ba.places, 1)), 0) AS occupied
          FROM {booking_options} bo
          JOIN {booking_answers} ba ON ba.optionid = bo.id AND ba.waitinglist IN ($placestates)
         WHERE bo.limitanswers = 1 AND bo.maxanswers > 0 $scopesql
      GROUP BY bo.id, bo.text, bo.maxanswers
        HAVING COALESCE(SUM(COALESCE(ba.places, 1)), 0) > bo.maxanswers";
foreach ($DB->get_records_sql($sql, $scopeparams) as $row) {
    $violations[] = [
        'invariant' => 'INV1_overbooking',
        'optionid'  => (int)$row->optionid,
        'option'    => $row->text,
        'detail'    => "occupied={$row->occupied} > maxanswers={$row->maxanswers}",
    ];
}

// INV2: waiting list must not exceed maxoverbooking (>=0 caps; -1 = unlimited).
$sql = "SELECT bo.id AS optionid, bo.text, bo.maxoverbooking,
               COALESCE(SUM(COALESCE(ba.places, 1)), 0) AS waiting
          FROM {booking_options} bo
          JOIN {booking_answers} ba ON ba.optionid = bo.id AND ba.waitinglist = $waitstate
         WHERE bo.limitanswers = 1 AND bo.maxoverbooking >= 0 $scopesql
      GROUP BY bo.id, bo.text, bo.maxoverbooking
        HAVING COALESCE(SUM(COALESCE(ba.places, 1)), 0) > bo.maxoverbooking";
foreach ($DB->get_records_sql($sql, $scopeparams) as $row) {
    $violations[] = [
        'invariant' => 'INV2_waitinglist_overflow',
        'optionid'  => (int)$row->optionid,
        'option'    => $row->text,
        'detail'    => "waiting={$row->waiting} > maxoverbooking={$row->maxoverbooking}",
    ];
}

// INV3: a user may hold at most one active answer (booked/reserved/waiting) per option.
// Catches the double-record race (e.g. reserved AND booked at once) under concurrency.
$sql = "SELECT " . $DB->sql_concat('ba.optionid', "'-'", 'ba.userid') . " AS uniqkey,
               ba.optionid, ba.userid, COUNT(*) AS cnt
          FROM {booking_answers} ba
          JOIN {booking_options} bo ON bo.id = ba.optionid
         WHERE ba.waitinglist IN ($activestates) $scopesql
      GROUP BY ba.optionid, ba.userid
        HAVING COUNT(*) > 1";
foreach ($DB->get_records_sql($sql, $scopeparams) as $row) {
    $violations[] = [
        'invariant' => 'INV3_duplicate_active_answer',
        'optionid'  => (int)$row->optionid,
        'option'    => '',
        'detail'    => "userid={$row->userid} has {$row->cnt} active answers (expected 1)",
    ];
}

// INV4 (opt-in): cart reservations older than the TTL are probable orphans.
$ttl = (int)$options['reserved-ttl'];
if ($ttl > 0) {
    $cutoff = time() - $ttl;
    $params = $scopeparams + ['cutoff' => $cutoff, 'reserved' => MOD_BOOKING_STATUSPARAM_RESERVED];
    $sql = "SELECT ba.id, ba.optionid, ba.userid, ba.timecreated
              FROM {booking_answers} ba
              JOIN {booking_options} bo ON bo.id = ba.optionid
             WHERE ba.waitinglist = :reserved AND ba.timecreated < :cutoff $scopesql
          ORDER BY ba.timecreated ASC";
    foreach ($DB->get_records_sql($sql, $params) as $row) {
        $age = time() - (int)$row->timecreated;
        $violations[] = [
            'invariant' => 'INV4_orphaned_reservation',
            'optionid'  => (int)$row->optionid,
            'option'    => '',
            'detail'    => "answerid={$row->id} userid={$row->userid} reserved {$age}s ago (> ttl={$ttl}s)",
        ];
    }
}

// INV5 (opt-in, best-effort): every BOOKED user is enrolled in the option target course.
if ($options['check-enrolment']) {
    $params = $scopeparams + ['booked' => MOD_BOOKING_STATUSPARAM_BOOKED];
    $sql = "SELECT ba.id, ba.optionid, ba.userid, bo.courseid
              FROM {booking_answers} ba
              JOIN {booking_options} bo ON bo.id = ba.optionid
             WHERE ba.waitinglist = :booked AND bo.courseid > 0 $scopesql
               AND NOT EXISTS (
                   SELECT 1
                     FROM {user_enrolments} ue
                     JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = bo.courseid
                    WHERE ue.userid = ba.userid
               )";
    foreach ($DB->get_records_sql($sql, $params) as $row) {
        $violations[] = [
            'invariant' => 'INV5_booked_not_enrolled',
            'optionid'  => (int)$row->optionid,
            'option'    => '',
            'detail'    => "userid={$row->userid} booked but not enrolled in courseid={$row->courseid}",
        ];
    }
}

// Optional per-option occupancy summary.
if ($options['summary']) {
    $sql = "SELECT bo.id AS optionid, bo.text, bo.maxanswers, bo.maxoverbooking,
                   COALESCE(SUM(CASE WHEN ba.waitinglist IN ($placestates) THEN COALESCE(ba.places, 1) ELSE 0 END), 0) AS occupied,
                   COALESCE(SUM(CASE WHEN ba.waitinglist = $waitstate THEN COALESCE(ba.places, 1) ELSE 0 END), 0) AS waiting
              FROM {booking_options} bo
         LEFT JOIN {booking_answers} ba ON ba.optionid = bo.id
             WHERE bo.limitanswers = 1 AND bo.maxanswers > 0 $scopesql
          GROUP BY bo.id, bo.text, bo.maxanswers, bo.maxoverbooking
          ORDER BY bo.id ASC";
    foreach ($DB->get_records_sql($sql, $scopeparams) as $row) {
        $summaryrows[] = [
            'optionid'      => (int)$row->optionid,
            'option'        => $row->text,
            'maxanswers'    => (int)$row->maxanswers,
            'occupied'      => (int)$row->occupied,
            'maxoverbooking' => (int)$row->maxoverbooking,
            'waiting'       => (int)$row->waiting,
        ];
    }
}

// Output.
if ($asjson) {
    echo json_encode([
        'ok'         => empty($violations),
        'violations' => array_values($violations),
        'summary'    => $summaryrows,
        'checkedat'  => time(),
    ], JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    if ($summaryrows) {
        echo "Per-option occupancy (limited options in scope):" . PHP_EOL;
        foreach ($summaryrows as $s) {
            echo sprintf(
                "  option %d  booked+reserved %d/%d  waiting %d/%s  [%s]" . PHP_EOL,
                $s['optionid'],
                $s['occupied'],
                $s['maxanswers'],
                $s['waiting'],
                $s['maxoverbooking'] < 0 ? '∞' : (string)$s['maxoverbooking'],
                $s['option']
            );
        }
        echo PHP_EOL;
    }
    if (empty($violations)) {
        echo "OK - no booking invariant violations found." . PHP_EOL;
    } else {
        echo count($violations) . " booking invariant violation(s) found:" . PHP_EOL;
        foreach ($violations as $v) {
            echo sprintf(
                "  [%s] option %d %s%s" . PHP_EOL,
                $v['invariant'],
                $v['optionid'],
                $v['option'] !== '' ? "({$v['option']}) " : '',
                $v['detail']
            );
        }
    }
}

exit(empty($violations) ? 0 : 2);
