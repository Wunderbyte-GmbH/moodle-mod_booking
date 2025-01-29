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
 * The cartstore class handles the in and out of the cache.
 *
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\optiondates;

use stdClass;


/**
 * Class to handle booking answers for specific optiondates, e.g. for presence status.
 */
class optiondate_answer {
    /**
     * $userid
     *
     * @var int
     */
    private $userid;
    /**
     * $optiondateid]
     *
     * @var int
     */
    private $optiondateid;

    /**
     * $optionid]
     *
     * @var int
     */
    private $optionid;

    /**
     * Constructor to initialize the class with userid, optiondateid, and optionid.
     *
     * @param int $userid
     * @param int $optiondateid
     * @param int $optionid
     */
    public function __construct($userid, $optiondateid, $optionid) {
        $this->userid = $userid;
        $this->optiondateid = $optiondateid;
        $this->optionid = $optionid;
    }


    /**
     * Create or update a record in the booking_optiondates_answers table.
     *
     * @param int|null $status
     * @param string|null $notes
     * @param string|null $json
     * @return bool True on success, false on failure.
     */
    public function save_record($status = null, $notes = null, $json = null) {
        global $DB, $USER;

        $data = [
            'userid' => $this->userid,
            'optiondateid' => $this->optiondateid,
            'optionid' => $this->optionid,
            'status' => $status,
            'notes' => $notes,
            'timemodified' => time(),
            'timecreated' => time(),
            'usermodified' => $USER->id,
            'json' => $json,
        ];

        if (
            $existing = $DB->get_record(
                'booking_optiondates_answers',
                ['userid' => $this->userid, 'optiondateid' => $this->optiondateid, 'optionid' => $this->optionid]
            )
        ) {
            $data['id'] = $existing->id;
            $data['notes'] = $notes ?? $existing->notes ?? null;
            $data['status'] = $status ?? $existing->status ?? 0;
            $data['timecreated'] = $existing->timecreated;
            return $DB->update_record('booking_optiondates_answers', $data);
        } else {
            return $DB->insert_record('booking_optiondates_answers', $data);
        }
    }

    /**
     * Retrieve a record from the booking_optiondates_answers table.
     *
     * @return stdClass|null The record object or null if not found.
     */
    public function get_record() {
        global $DB;
        return $DB->get_record(
            'booking_optiondates_answers',
            ['userid' => $this->userid, 'optiondateid' => $this->optiondateid, 'optionid' => $this->optionid]
        );
    }

    /**
     * Delete a record from the booking_optiondates_answers table.
     *
     * @return bool True on success, false on failure.
     */
    public function delete_record() {
        global $DB;
        return $DB->delete_records(
            'booking_optiondates_answers',
            ['userid' => $this->userid, 'optiondateid' => $this->optiondateid, 'optionid' => $this->optionid]
        );
    }

    /**
     * Retrieve all records for a specific optiondate.
     *
     * @return array An array of record objects.
     */
    public function get_records_for_optiondate() {
        global $DB;
        return $DB->get_records(
            'booking_optiondates_answers',
            ['optiondateid' => $this->optiondateid, 'optionid' => $this->optionid]
        );
    }

    /**
     * Update the status of a specific record.
     *
     * @param int $status
     * @return bool True on success, false on failure.
     */
    public function add_or_update_status($status) {
        return $this->save_record($status);
    }

    /**
     * Update the status of a specific record.
     *
     * @param string $note
     * @return bool True on success, false on failure.
     */
    public function add_or_update_notes($note) {
        return $this->save_record(null, $note);
    }
}
