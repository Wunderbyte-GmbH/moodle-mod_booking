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
 * This file contains the definition for the renderable classes for simple optiondates.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use mod_booking\booking_option_settings;
use mod_booking\option\dates_handler;
use renderer_base;
use renderable;
use templatable;
use stdClass;

/**
 * This class prepares data for displaying simple option dates.
 *
 * @package     mod_booking
 * @copyright   2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondates_only implements renderable, templatable {
    /** @var bool $showsessions */
    public $showsessions = true;

    /** @var array $sessions */
    public $sessions = [];

    /** @var bool $onesession */
    public $onesession = false;

    /**
     * Constructor
     *
     * @param booking_option_settings $settings
     */
    public function __construct(booking_option_settings $settings) {

        $sessions = dates_handler::return_dates_with_strings($settings, '', true);

        $numberofsessions = count($sessions);

        $this->onesession = $numberofsessions === 1;
        $this->showsessions = $numberofsessions > 0;

        foreach ($sessions as $session) {
            $session->datestring = $session->startdatetime;

            if ($session->startdate !== $session->enddate) {
                $session->datestring .= " - " . $session->enddatetime;
            }
        }

        $this->sessions = $sessions;
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     *
     * @return void
     *
     */
    public function export_for_template(renderer_base $output) {

        return [
                'showsessions' => $this->showsessions,
                'onesession' => $this->onesession,
                'dates' => $this->sessions,
        ];
    }
}
