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

use cache;
use stdClass;

/**
 * Semester class.
 *
 * @package mod_booking
 * @copyright 2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class semester {

    /** @var int $id the semester id */
    public $id = 0;

    /** @var string $identifier a short identifier of the semester */
    public $identifier = '';

    /** @var string $name the full name of the semester */
    public $name = '';

    /** @var int $start start date as unix timestamp */
    public $startdate = 0;

    /** @var int $end end date as unix timestamp */
    public $enddate = 0;

    /**
     * Constructor for the semester class.
     *
     * @param int $id id of the semester.
     * @throws dml_exception
     */
    public function __construct(int $id) {

        $cache = cache::make('mod_booking', 'cachedsemesters');
        $cachedsemester = $cache->get($id);

        if (!$cachedsemester) {
            $cachedsemester = null;
        }

        // If we have no object to pass to set values, the function will retrieve the values from db.
        if ($data = $this->set_values($id, $cachedsemester)) {
            // Only if we didn't pass anything to cachedsemester, we set the cache now.
            if (!$cachedsemester) {
                $cache->set($id, $data);
            }
        }
    }

    /**
     * Set all the values from DB if necessary.
     * If we have passed on the cached object, we use this one.
     *
     * @param int $id the semester id
     * @param ?stdClass $dbrecord
     * @return stdClass|null
     */
    private function set_values(int $id, ?stdClass $dbrecord = null) {
        global $DB;

        // If we don't get the cached object, we have to fetch it here.
        if (empty($dbrecord)) {
            $dbrecord = $DB->get_record("booking_semesters", ["id" => $id]);
        }

        if ($dbrecord) {
            // Fields in DB.
            $this->id = $id;
            $this->identifier = $dbrecord->identifier;
            $this->name = $dbrecord->name;
            $this->startdate = (int) $dbrecord->startdate;
            $this->enddate = (int) $dbrecord->enddate;

            return $dbrecord;
        } else {
            debugging('Could not create semester for id: ' . $id);
            return null;
        }
    }

    /**
     * Get an array of all semesters containing semester ids as keys
     * and semester names (including identifier in parantheses) as values.
     *
     * @return array
     */
    public static function get_semesters_id_name_array(): array {
        global $DB;

        $semestersarray = [0 => get_string('nosemester', 'mod_booking')];

        $data = $DB->get_records('booking_semesters');

        foreach ($data as $record) {
            $semestersarray[$record->id] = $record->name . ' (' . $record->identifier . ')';
        }

        return $semestersarray;
    }

    /**
     * Get an array of all semesters containing semester identifiers as keys
     * and semester names (including identifier in parantheses) as values.
     *
     * @return array
     */
    public static function get_semesters_identifier_name_array(): array {
        global $DB;

        $semestersarray = [];

        $data = $DB->get_records('booking_semesters');

        foreach ($data as $record) {
            $semestersarray[$record->identifier] = $record->name . ' (' . $record->identifier . ')';
        }

        return $semestersarray;
    }

    /**
     * Get the most recently added semester.
     *
     * @return int id of the most recently added semester
     */
    public static function get_semester_with_highest_id() {
        global $DB;

        if ($semesterobj = $DB->get_record_sql("SELECT max(id) as semesterid FROM {booking_semesters}")) {
            return $semesterobj->semesterid;
        } else {
            // No semesters in DB!
            return 0;
        }
    }
}
