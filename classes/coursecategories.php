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

use dml_exception;
use Exception;

/**
 * Manage coursecategories in berta.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class coursecategories {

    /**
     * Returns coursecategories.
     * When 0, it returns all coursecateogries, else only the specific one.
     * @param int $categoryid
     * @param bool $onlyparents
     * @return array
     * @throws dml_exception
     */
    public static function return_course_categories(int $categoryid = 0, $onlyparents = true) {
        global $DB;

        $wherearray = [];

        if (!empty($categoryid)) {
            $wherearray[] = 'coca.id = ' . $categoryid;
        }

        if ($onlyparents) {
            $wherearray[] = 'coca.parent = 0';
        }
        if (!empty($wherearray)) {
            $where = 'WHERE ' . implode(' AND ', $wherearray);
        }

        $sql = "SELECT coca.id,
                       coca.name,
                       coca.description,
                       coca.path,
                       coca.coursecount,
                       c.id as contextid
                FROM {course_categories} coca
                JOIN {context} c ON c.instanceid=coca.id AND c.contextlevel = 40
                $where";

        return $DB->get_records_sql($sql);
    }

    /**
     * Returns specific booking information for any course category.
     * @param int $contextid
     * @param string $firstadditionalcount
     * @param string $secondadditionalcount
     * @return array
     * @throws dml_exception
     */
    public static function return_booking_information_for_coursecategory(
        int $contextid,
        $firstadditionalcount = '',
        $secondadditionalcount = '') {

        global $DB;

        $where = [
            "m.name = 'booking'",
            "c.contextlevel = " . CONTEXT_MODULE,
        ];

        if (!empty($contextid)) {
            $from = " JOIN {context} c ON c.instanceid = cm.id ";
            $where[] = "c.path LIKE :path";
            $params['path'] = "/1/$contextid/%";
        }

        if (!empty($firstadditionalcount)) {
            $additionalselect = ', SUM (realparticipants) realparticipants ';
            $intvalue = $DB->sql_cast_char2int('cfd.charvalue');
            $additionalfrom = "
                LEFT JOIN (SELECT cfd.instanceid as optionid, SUM( $intvalue ) as realparticipants
                FROM {customfield_field} cff
                JOIN {customfield_data} cfd ON cff.id = cfd.fieldid
                JOIN {customfield_category} cfc ON cff.categoryid = cfc.id
                WHERE cff.shortname =:firstadditionalcount AND cfc.component='mod_booking' AND cfd.charvalue <> ''
                GROUP BY optionid
                ) s4 ON s4.optionid = bo.id
            ";
            $params['firstadditionalcount'] = $firstadditionalcount;
        } else {
            $additionalfrom = '';
            $additionalselect = '';
        }

        if (!empty($secondadditionalcount)) {
            $additionalselect .= ', SUM (realcosts) realcosts ';
            $intvalue = $DB->sql_cast_char2real('cfd.charvalue');
            $additionalfrom .= "
                LEFT JOIN (SELECT cfd.instanceid as optionid, SUM( $intvalue ) as realcosts
                FROM {customfield_field} cff
                JOIN {customfield_data} cfd ON cff.id = cfd.fieldid
                JOIN {customfield_category} cfc ON cff.categoryid = cfc.id
                WHERE cff.shortname =:secondadditionalcount AND cfc.component='mod_booking' AND cfd.charvalue <> ''
                GROUP BY optionid
                ) s6 ON s6.optionid = bo.id
            ";
            $params['secondadditionalcount'] = $secondadditionalcount;
        }

        $sql = "SELECT cm.id,
                       b.name,
                       b.id as bookingid,
                       b.intro,
                       COUNT(bo.id) bookingoptions,
                       SUM(booked) booked,
                       SUM(waitinglist) waitinglist,
                       SUM(reserved) reserved,
                       SUM(participated) participated,
                       SUM(excused) excused,
                       SUM(noshows) noshows $additionalselect
        FROM {course_modules} cm
        JOIN {modules} m ON cm.module = m.id
        JOIN {booking} b on cm.instance = b.id
        LEFT JOIN {booking_options} bo ON b.id = bo.bookingid
        LEFT JOIN (SELECT ba.optionid, SUM(ba.places) as booked
              FROM {booking_answers} ba
              WHERE ba.waitinglist = 0
              GROUP BY ba.optionid
              ) s1 ON s1.optionid = bo.id
        $from
        LEFT JOIN (SELECT ba.optionid, SUM(ba.places) as waitinglist
              FROM {booking_answers} ba
              WHERE ba.waitinglist = 1
              GROUP BY ba.optionid
              ) s2 ON s2.optionid = bo.id
        LEFT JOIN (SELECT ba.optionid, SUM(ba.places) as reserved
              FROM {booking_answers} ba
              WHERE ba.waitinglist = 2
              GROUP BY ba.optionid
              ) s3 ON s3.optionid = bo.id
        LEFT JOIN (SELECT ba.optionid, SUM(ba.places) as participated
              FROM {booking_answers} ba
              WHERE ba.status = 6
              GROUP BY ba.optionid
              ) s5 ON s5.optionid = bo.id
        LEFT JOIN (SELECT ba.optionid, SUM(ba.places) as excused
              FROM {booking_answers} ba
              WHERE ba.status = 7
              GROUP BY ba.optionid
              ) s7 ON s7.optionid = bo.id
        LEFT JOIN (SELECT ba.optionid, SUM(ba.places) as noshows
              FROM {booking_answers} ba
              WHERE ba.status = 3
              GROUP BY ba.optionid
              ) s8 ON s8.optionid = bo.id
        $additionalfrom
        WHERE " . implode(' AND ', $where) .
        " GROUP BY cm.id, b.name, b.id, b.intro  ";

        try {
            $records = $DB->get_records_sql($sql, $params);
        } catch (Exception $e) {
            // If we run into an exception, might be that the field has a wrong value and we can't cast it to int.
            // Therefore, we just try again without the additional column.
            if (!empty($firstadditionalcount)) {
                $records = self::return_booking_information_for_coursecategory($contextid);
            }
        }
        return $records;
    }

    /**
     * Returns true if booking setup was adjusted.
     * @param int $bookingid
     * @return bool
     */
    public static function set_configured_booking_instances(int $bookingid) {
        $multibookingconfig = explode(',', get_config('local_urise', 'multibookinginstances'));
        if (in_array($bookingid, $multibookingconfig)) {
            $index = array_search($bookingid, $multibookingconfig);
            if ($index !== false) {
                unset($multibookingconfig[$index]);
                $multibookingconfig = array_values($multibookingconfig);
            } else {
                return false;
            }
        } else {
            $multibookingconfig[] = $bookingid;
        }
        $multibookingconfig = implode(',', $multibookingconfig);
        set_config('multibookinginstances', $multibookingconfig, 'local_urise');
        return true;
    }

}
