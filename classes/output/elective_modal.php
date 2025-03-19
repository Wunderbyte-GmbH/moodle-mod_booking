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
 * @copyright 2023 Georg Maißer <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use cache;
use html_writer;
use mod_booking\booking_settings;
use mod_booking\elective;
use renderer_base;
use renderable;
use templatable;


/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class elective_modal implements renderable, templatable {
    /** @var string $confirmbutton */
    public $confirmbutton;

    /** @var string $modalbuttonclass */
    public $modalbuttonclass;

    /** @var array $arrayofoptions */
    public $arrayofoptions = [];

    /** @var bool */
    public $isteacherorderforced = false;

    /** @var string */
    public $notbookablemessage;


    /**
     * Constructor
     *
     * @param booking_settings $booking
     * @param array $rawdata
     */
    public function __construct(booking_settings $booking, array $rawdata) {

        global $USER;

        // If there are no list items, we return null.
        if (!$rawdata) {
            return;
        }

        // First, we retrieve the saved order of the booking options.

        $cache = cache::make('mod_booking', 'electivebookingorder');

        $cmid = (int)$booking->cmid;
        $cachearray = $cache->get($cmid);
        $now = time();

        // If the the cache has not yet expired, we use it.
        if (isset($cachearray['expirationtime']) && $cachearray['expirationtime'] > $now) {
            $arrayofoptions = $cachearray['arrayofoptions'] ?? [];
        } else {
            // If there are items, we delete them.
            $arrayofoptions = [];
            if (isset($cachearray['arrayofoptions']) && count($cachearray['arrayofoptions']) > 0) {
                // We also reset the cache array.
                $cache->delete($cmid);
            }
        }

        foreach ($arrayofoptions as $item) {
            $this->arrayofoptions[] = (array)$rawdata[$item];
        }

        // Resort based on teacher sort order.
        if (!empty($booking->enforceteacherorder)) {
            $this->isteacherorderforced = true;
            usort($this->arrayofoptions, function ($a, $b) {
                if ($a['sortorder'] == $b['sortorder']) {
                    return 0;
                }

                return $a['sortorder'] < $b['sortorder'] ? -1 : 1;
            });
        }

        if (!elective::is_bookable_combination($booking)) {
            $this->notbookablemessage = get_string('notbookablecombiantion', 'mod_booking');
        }

        // If all credits have to be consumed at once, only enable the "book all selected" button...
        // ... when no more credits are left.
        if ($booking->consumeatonce == 1 && elective::return_credits_left($booking) !== 0) {
            $selectbtnoptions['class'] = 'btn btn-primary disabled';
        } else if (count($arrayofoptions) == 0) {
            // Also, disable the button when there is nothing selected.
            $selectbtnoptions['class'] = 'btn btn-primary disabled';
        } else {
            $selectbtnoptions['class'] = 'btn btn-primary';
        }

        $this->modalbuttonclass = $selectbtnoptions['class'];

        $selectbtnoptions['class'] .= ' booking-button-area noprice';
        $selectbtnoptions['data-itemid'] = $cmid;
        $selectbtnoptions['data-area'] = 'elective';
        $selectbtnoptions['data-userid'] = $USER->id;

        $selectbtnoptions['id'] = "confirmbutton";
        $this->confirmbutton = html_writer::tag('div', get_string('bookelectivesbtn', 'mod_booking'), $selectbtnoptions);
    }

    /**
     * Return as array
     *
     * @return array
     *
     */
    public function return_as_array() {
        return [
            'modalbuttonclass' => $this->modalbuttonclass,
            'confirmbutton' => $this->confirmbutton,
            'arrayofoptions' => $this->arrayofoptions,
            'isteacherorderforced' => $this->isteacherorderforced,
            'notbookablemessage' => $this->notbookablemessage,
        ];
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
        return $this->return_as_array();
    }
}
