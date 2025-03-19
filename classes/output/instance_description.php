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

use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying a booking instance.
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instance_description implements renderable, templatable {
    /** @var string $description */
    public $description = null;

    /** @var string $duration */
    public $duration = null;

    /** @var string $points */
    public $points = null;

    /** @var string $organizatorname */
    public $organizatorname = null;

    /**
     * Constructor
     *
     * @param object $settings
     */
    public function __construct($settings) {
        $this->description = $settings->intro;
        $this->duration = $settings->duration;
        $this->organizatorname = $settings->organizatorname;
        $this->points = null;
        // Only show points if there are any.
        if ($settings->points != '0.00') {
            $this->points = $settings->points;
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
        return [
                'description' => $this->description,
                'duration' => $this->duration,
                'points' => $this->points,
                'organizatorname' => $this->organizatorname,
        ];
    }
}
