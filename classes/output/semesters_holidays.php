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
 * This file contains the definition for the renderable classes for semesters and holidays.
 *
 * @package   mod_booking
 * @copyright 2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes for semesters and holidays.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class semesters_holidays implements renderable, templatable {

    /**
     * Constructor
     *
     * @param string $renderedsemestersform the rendered semesters form
     * @param string $renderedholidaysform the rendered holidays form
     */
    public function __construct(string $renderedsemestersform, string $renderedholidaysform, string $renderedchangesemesterform) {

        $this->renderedsemestersform = $renderedsemestersform;
        $this->renderedholidaysform = $renderedholidaysform;
        $this->renderedchangesemesterform = $renderedchangesemesterform;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        $returnarray = [
            'renderedsemestersform' => $this->renderedsemestersform,
            'renderedholidaysform' => $this->renderedholidaysform
        ];

        // We only add the key if it's not empty.
        if (!empty($this->renderedchangesemesterform)) {
            $returnarray['renderedchangesemesterform'] = $this->renderedchangesemesterform;
        }

        return $returnarray;
    }
}
