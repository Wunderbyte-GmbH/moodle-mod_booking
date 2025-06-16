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
 * This file contains the definition for the renderable classes for the booking instance
 *
 * @package   mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking_option;
use moodle_exception;
use renderer_base;
use renderable;
use templatable;
use mod_booking\option\dates_handler;
use mod_booking\price;
use mod_booking\singleton_service;

/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_coursestarttime implements renderable, templatable {
    /** @var bool|null $datesexist */
    public $datesexist = null;

    /** @var array $dates */
    public $dates = null;

    /** @var int $optionid */
    public $optionid = null;

    /** @var bool $showcollapsebtn */
    public $showcollapsebtn = null;

    /** @var bool $selflearningcourse */
    public $selflearningcourse = null;

    /** @var string $duration */
    public $duration = null;

    /** @var string $timeremaining */
    public $timeremaining = null;

    /** @var bool $selflearningcourseshowdurationinfo */
    private $selflearningcourseshowdurationinfo = null;

    /** @var bool $selflearningcourseshowdurationinfoexpired */
    private $selflearningcourseshowdurationinfoexpired = null;

    /**
     * Constructor
     *
     * @param int $optionid
     * @param object|null $booking booking instance
     * @param int|null $cmid course module id of the booking instance
     * @param bool $collapsed set to true, if dates should be collapsed
     *
     */
    public function __construct($optionid, $booking = null, $cmid = null, $collapsed = true) {
        global $USER;
        if (empty($booking) && empty($cmid)) {
            throw new moodle_exception('Error: either booking instance or cmid have to be provided.');
        } else if (!empty($booking) && empty($cmid)) {
            $cmid = $booking->cm->id;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->optionid = $optionid;

        // For self-learning courses, we do not show any optiondates (sessions).
        if (!empty($settings->selflearningcourse)) {
            $this->selflearningcourse = true;

            if (get_config('booking', 'selflearningcoursehideduration')) {
                $this->selflearningcourseshowdurationinfo = null;
            } else if (!empty($settings->duration)) {
                // We do not show duration info if it is set to 0.
                $this->selflearningcourseshowdurationinfo = true;

                // Format the duration correctly.
                $this->duration = format_time($settings->duration);

                $ba = singleton_service::get_instance_of_booking_answers($settings);
                $buyforuser = price::return_user_to_buy_for();
                if (isset($ba->usersonlist[$buyforuser->id])) {
                    $timebooked = $ba->usersonlist[$buyforuser->id]->timecreated;
                    $timeremainingsec = $timebooked + $settings->duration - time();

                    if ($timeremainingsec <= 0) {
                        $this->selflearningcourseshowdurationinfo = null;
                        $this->selflearningcourseshowdurationinfoexpired = true;
                    } else {
                        $this->timeremaining = format_time($timeremainingsec);
                    }
                }
            }
        } else {
            // No self-learning course.
            // Every date will be an array of dates, customfields and additional info like entities.
            $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
            // If the user is booked, we have a different kind of description.
            $bookedusers = $bookingoption->get_all_users_booked();
            $forbookeduser = isset($bookedusers[$USER->id]);
            $this->dates = $bookingoption->return_array_of_sessions(
                null,
                MOD_BOOKING_DESCRIPTION_WEBSITE,
                true,
                $forbookeduser,
                true,
                true
            );
            $this->datesexist = !empty($this->dates) ? true : false;

            $maxdates = get_config('booking', 'collapseshowsettings') ?? 2; // Hardcoded fallback on two.
            // Show a collapse button for the dates.
            if (!empty($this->dates) && count($this->dates) > $maxdates && $collapsed == true) {
                $this->showcollapsebtn = true;
            }
        }
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     *
     * @return array
     *
     */
    public function export_for_template(renderer_base $output) {

        if (!empty($this->selflearningcourse)) {
            $returnarr['selflearningcourse'] = $this->selflearningcourse;
            $returnarr['duration'] = $this->duration;
            $returnarr['selflearningcourseshowdurationinfo'] = $this->selflearningcourseshowdurationinfo;
            $returnarr['selflearningcourseshowdurationinfoexpired'] = $this->selflearningcourseshowdurationinfoexpired;
            if (!empty($this->timeremaining)) {
                $returnarr['timeremaining'] = $this->timeremaining;
            }
            return $returnarr;
        }

        if (empty($this->dates)) {
            return [];
        }

        $returnarr = [
            'optionid' => $this->optionid,
            'datesexist' => $this->datesexist,
            'dates' => $this->dates,
        ];

        if (!empty($this->showcollapsebtn)) {
            $returnarr['showcollapsebtn'] = $this->showcollapsebtn;
        }

        // Setting to show extra info like entities, custom fields, etc.
        if (get_config('booking', 'showoptiondatesextrainfo')) {
            $returnarr['showoptiondatesextrainfo'] = true;
        }

        return $returnarr;
    }
}
