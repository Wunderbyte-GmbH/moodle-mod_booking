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
 * @copyright 2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Magdalena Holczik
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use local_entities\entitiesrelation_handler;
use mod_booking\booking_option_settings;
use mod_booking\option\dates_handler;
use moodle_url;
use renderer_base;
use renderable;
use templatable;
use stdClass;

/**
 * This class prepares data for displaying simple option dates.
 *
 * @package     mod_booking
 * @copyright   2024 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Magdalena Holczik
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class optiondates_with_entities implements renderable, templatable {

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

        $entities = class_exists('local_entities\entitiesrelation_handler');

        // Add entities.
        if ($entities = class_exists('local_entities\entitiesrelation_handler')) {
            $erhandler = new entitiesrelation_handler('mod_booking', 'optiondate');
        }

        foreach ($sessions as $session) {
            $session->starttime = $session->startdatetime;

            if ($session->startdate !== $session->enddate) {
                $session->endtime = $session->enddatetime;
            }
            if ($entities && $data = $erhandler->get_instance_data($session->id)) {
                $session->entityname = $data->name;
                $url = new moodle_url('/local/entities/view.php', ['id' => $data->id]);
                $session->entityurl = $url->out();
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
                'sessions' => $this->sessions,
        ];
    }
}
