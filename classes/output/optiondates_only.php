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
     * @param array $sessions an array of optiondates containing coursestarttime and courseendtime
     */
    public function __construct(array $sessions) {

        $dateformat = get_string('strftimedate', 'langconfig');
        $timeformat = get_string('strftimetime', 'langconfig');
        $datetimeformat = get_string('strftimedatetime', 'langconfig');

        if (empty($sessions)) {
            // Don't show anything if we have no sessions.
            $this->showsessions = false;

        } else {
            // If there only is one session, it could be that it's the course start and end time.
            // So check, if it's expanding over more than one day and format accordingly.
            if (count($sessions) == 1) {

                $this->onesession = true;

                $session = array_pop($sessions);

                $formattedsession = new stdClass;

                if (userdate($session->coursestarttime, $dateformat) == userdate($session->courseendtime, $dateformat)) {
                    // Session is on one day only.
                    $formattedsession->starttime = userdate($session->coursestarttime, $datetimeformat);
                    $formattedsession->endtime = userdate($session->courseendtime, $timeformat);
                } else {
                    // Session expands over more than one day.
                    $formattedsession->starttime = userdate($session->coursestarttime, $datetimeformat);
                    $formattedsession->endtime = userdate($session->courseendtime, $datetimeformat);
                }
                $this->sessions[] = $formattedsession;

            } else {

                $this->onesession = false;

                // Each session in a multisession will always be on the same day.
                foreach ($sessions as $session) {
                    $formattedsession = new stdClass;

                    $formattedsession->starttime = userdate($session->coursestarttime, $datetimeformat);
                    $formattedsession->endtime = userdate($session->courseendtime, $timeformat);

                    $this->sessions[] = $formattedsession;
                }
            }
        }
    }

    public function export_for_template(renderer_base $output) {

        return array(
                'showsessions' => $this->showsessions,
                'onesession' => $this->onesession,
                'sessions' => $this->sessions
        );
    }
}
