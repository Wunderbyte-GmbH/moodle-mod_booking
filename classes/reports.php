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
namespace mod_booking;

require_once("../../config.php");
require_once("$CFG->libdir/excellib.class.php");

defined('MOODLE_INTERNAL') || die();

class reports {

    private $type;
    private $from;
    private $to;
    private $course;
    private $cm;
    private $booking;

    public function __construct($type, $from = null, $to = null, $course, $cm, $booking) {
        $this->type = $type;
        $this->from = $from;
        $this->to = $to;
        $this->course = $course;
        $this->cm = $cm;
        $this->booking = $booking;
    }

    public function getreport() {
        switch ($this->type) {
            case 1:
                // Teachers report.
                $this->teachersreport();
                break;

            case 2:
                // Presence report.
                $this->presencereport();
                break;
        }
    }

    private function presencereport() {
        global $DB;

        $r = $DB->get_records_sql("
        select
        t.userid id,
        t.firstname,
        t.lastname,
        CONCAT(t.registered, '/', t.allbookings) registered,
        100 * t.registered / t.allbookings registeredp,
        CONCAT(t.confirmed, '/', t.registered) confirmed,
        100 * t.confirmed / t.registered confirmedp,
        CONCAT(t.confirmed, '/', t.allbookings) confirmedall,
        100 * t.confirmed / t.allbookings confirmedallp
    from
    (select
        distinct mba.userid,
        mu.firstname,
        mu.lastname,
        (select count(*) from {booking_options} mbo where mbo.bookingid = mba.bookingid) allbookings,
        (select count(*) from {booking_answers} mba2 where mba2.bookingid = mba.bookingid AND mba2.userid = mu.id) registered,
        (select count(*) from {booking_answers} mba2 where mba2.bookingid = mba.bookingid AND mba2.userid = mu.id and mba2.completed = 1) confirmed
    from {booking_answers} mba
    left join {user}
        mu on mu.id = mba.userid
    where bookingid = :bookingid) t
            ", ['bookingid' => $this->cm->instance]);

        // Calculate file name.
        $downloadfilename = clean_filename("{$this->course->shortname} {$this->booking->settings->name} " . get_string('teachersreport', 'booking') . ".xls");
        // Creating a workbook.
        $workbook = new \MoodleExcelWorkbook("-");

        // Sending HTTP headers.
        $workbook->send($downloadfilename);

        $wsname = substr(get_string('teachersreport', 'booking'), 0, 31);
        $myxls = $workbook->add_worksheet($wsname);

        $checklistexportusercolumns = [
            'firstname' => get_string("firstname"),
            'lastname' => get_string("lastname"),
            'registered' => get_string('registered', 'booking'),
            'registeredp' => get_string("registeredp", "booking"),
            'confirmed' => get_string("confirmed", "booking"),
            'confirmedp' => get_string("confirmedp", "booking"),
            'confirmedall' => get_string('confirmedall', 'booking'),
            'confirmedallp' => get_string("confirmedallp", 'booking')
        ];

        // Print names of all the fields.
        $col = 0;
        $row = 0;
        foreach ($checklistexportusercolumns as $field => $headerstr) {
            $myxls->write_string($row, $col++, $headerstr);
        }

        $row++;

        foreach ($r as $key => $value) {
            $col = 0;
            foreach ($value as $ckey => $cvalue) {
                $out = '';

                if ($ckey != 'id') {
                    switch ($ckey) {
                        case 'registeredp':
                        case 'confirmedp':
                        case 'confirmedallp':
                            $myxls->write_number($row, $col, $cvalue);
                            break;

                        default:
                            $myxls->write_string($row, $col, $cvalue);
                            break;
                    }

                    $col++;
                }
            }

            $row++;
        }

        $workbook->close();
        exit;
    }

    private function teachersreport() {
        global $DB;

        $r = $DB->get_records_sql('SELECT bo.text, bo.coursestarttime, bo.courseendtime, bo.location, (select count(mba.id) from {booking_answers} mba where mba.optionid = bo.id)
            numberofusers, mu.firstname, mu.lastname, mu.address, mu.city, mu.country FROM {booking_options} bo left join {booking_teachers} mbt on mbt.optionid = bo.id
            left join {user} mu on mu.id = mbt.userid  WHERE bo.bookingid = :bookingid AND bo.coursestarttime >= :coursestarttime AND bo.courseendtime <= :courseendtime',
            ['bookingid' => $this->cm->instance, 'coursestarttime' => $this->from, 'courseendtime' => $this->to]);

        $rcount = $DB->get_records_sql('SELECT mu.firstname, mu.lastname, count(mu.id) numberofcourses FROM {booking_options} bo left join {booking_teachers} mbt on mbt.optionid =
            bo.id	left join {user} mu on mu.id = mbt.userid  WHERE bo.bookingid = :bookingid AND bo.coursestarttime >= :coursestarttime AND bo.courseendtime <= :courseendtime
            GROUP BY mu.id',
            ['bookingid' => $this->cm->instance, 'coursestarttime' => $this->from, 'courseendtime' => $this->to]);

        // Calculate file name.
        $downloadfilename = clean_filename("{$this->course->shortname} {$this->booking->settings->name} " . get_string('teachersreport', 'booking') . ".xls");
        // Creating a workbook.
        $workbook = new \MoodleExcelWorkbook("-");

        // Sending HTTP headers.
        $workbook->send($downloadfilename);

        $wsname = substr(get_string('teachersreport', 'booking'), 0, 31);
        $myxls = $workbook->add_worksheet($wsname);

        $checklistexportusercolumns = [
        'text' => get_string('bookingoptionname', 'booking'),
        'coursestarttime' => get_string("coursestarttime", "booking"),
        'courseendtime' => get_string("courseendtime", "booking"),
        'location' => get_string("location", "booking"),
        'numberofusers' => get_string('responses', 'booking'),
        'firstname' => get_string("firstname"),
        'lastname' => get_string("lastname"),
        'address' => get_string("address"),
        'city' => get_string("city"),
        'country' => get_string("country")
        ];

        $statiscitcheader = [
        'firstname' => get_string("firstname"),
        'lastname' => get_string("lastname"),
        'numberofcourses' => get_string('numberofcourses', 'booking')
        ];

        // Print names of all the fields.
        $col = 0;
        $row = 0;
        foreach ($checklistexportusercolumns as $field => $headerstr) {
            $myxls->write_string($row, $col++, $headerstr);
        }

        $row++;

        foreach ($r as $key => $value) {
            $col = 0;
            foreach ($value as $ckey => $cvalue) {
                $out = '';

                switch ($ckey) {
                    case 'coursestarttime':
                    case 'courseendtime':
                        $myxls->write_string($row, $col, userdate($cvalue, get_string('strftimedatetime', 'core_langconfig')));
                        break;

                    case 'numberofusers':
                        $myxls->write_number($row, $col, $cvalue);
                        break;

                    default:
                        $myxls->write_string($row, $col, $cvalue);
                        break;
                }

                $col++;
            }

            $row++;
        }

        $workbook->close();

        $wsname = substr(get_string('statistics', 'booking'), 0, 31);
        $myxls = $workbook->add_worksheet($wsname);

        $col = 0;
        $row = 0;
        foreach ($statiscitcheader as $field => $headerstr) {
            $myxls->write_string($row, $col++, $headerstr);
        }

        $row++;

        foreach ($rcount as $key => $value) {
            $col = 0;
            foreach ($value as $ckey => $cvalue) {
                $out = '';

                switch ($ckey) {
                    case 'numberofcourses':
                        $myxls->write_number($row, $col, $cvalue);
                        break;

                    default:
                        $myxls->write_string($row, $col, $cvalue);
                        break;
                }

                $col++;
            }

            $row++;
        }

        $workbook->close();

        exit;
    }

}