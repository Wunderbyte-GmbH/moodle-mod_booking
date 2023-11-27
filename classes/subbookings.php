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

use context_module;
use context_system;
use local_entities\entitiesrelation_handler;
use mod_booking\customfield\booking_handler;
use moodle_exception;
use stdClass;
use moodle_url;

/**
 * Subbookings class.
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subbookings {

    /** @var int $id The ID of the booking option. */
    public $id = null;


    /**
     * Constructor for the subbookings class.
     *
     * @param int $optionid Booking option id.
     * @throws dml_exception
     */
    public function __construct(int $optionid) {

        // Even if we have a record, we still get the cache...
        // Because in the cache, we have also information from other tables.
        $cache = \cache::make('mod_booking', 'subbookings');
        if (!$cachedoption = $cache->get($optionid)) {
            $savecache = true;
        } else {
            $savecache = false;
        }
    }

    /**
     * This function is used to deal with responses from the user. We use the naming from booking_option class.
     *
     * @param int $userid
     * @param int $sboid // booking_subbooking_options id
     * @param string $json
     * @param int $timestart
     * @param int $timeend
     * @param bool $addedtocart
     * @return void
     */
    public function user_submit_response(
            int $userid,
            int $sboid,
            string $json = '',
            int $timestart = 0,
            int $timeend = 0,
            bool $addedtocart = false) {

        global $DB, $USER;

        $now = time();

        $status = $addedtocart ? MOD_BOOKING_STATUSPARAM_BOOKED : MOD_BOOKING_STATUSPARAM_RESERVED;

        $record = (object)[
            'sboptionid' => $sboid,
            'userid' => $userid,
            'usermodified' => $USER->id,
            'json' => '',
            'timestart' => $timestart,
            'timeend' => $timeend,
            'status' => $status,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        $DB->insert_record('booking_subbooking_answers', $record);

    }



}
