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
use cache_helper;
use MoodleQuickForm;
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
    private $id = 0;

    /** @var string $identifier a short identifier of the semester */
    private $identifier = '';

    /** @var string $name the full name of the semester */
    private $name = '';

    /** @var int $start start date as unix timestamp */
    private $start = 0;

    /** @var int $end end date as unix timestamp */
    private $end = 0;

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
     * @return stdClass|null
     */
    private function set_values(int $id, stdClass $dbrecord = null) {
        global $DB;

        // If we don't get the cached object, we have to fetch it here.
        if (empty($dbrecord)) {
            $dbrecord = $DB->get_record("booking_semesters", array("id" => $id));
        }

        if ($dbrecord) {
            // Fields in DB.
            $this->id = $id;
            $this->identifier = $dbrecord->identifier;
            $this->name = $dbrecord->name;
            $this->start = $dbrecord->start;
            $this->end = $dbrecord->end;

            return $dbrecord;
        } else {
            debugging('Could not create semester for id: ' . $id);
            return null;
        }
    }
}
