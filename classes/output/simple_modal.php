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
 * This file contains the definition for the renderable classes for column 'price'.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Georg Maißer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use renderer_base;
use renderable;
use templatable;

/**
 * This class prepares data for displaying a simple modal in different contexts.
 *
 * @package     mod_booking
 * @copyright   2022 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Georg Maißer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class simple_modal implements renderable, templatable {

    /** @var int $modalcounter Modal counter for more than one modals on a page. */
    public $modalcounter = 0;

    /** @var string $modaltitle string for button text */
    public $modaltitle = "";

    /** @var string $description html string for body */
    public $description = "";

    /**
     * Constructor
     *
     * @param int $modalcounter
     * @param string $modaltitle
     * @param string $description
     */
    public function __construct(int $modalcounter, string $modaltitle, string $description) {

        $this->modalcounter = $modalcounter;
        $this->modaltitle = $modaltitle;
        $this->description = $description;
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        return [
            'modalcounter' => $this->modalcounter,
            'modaltitle' => $this->modaltitle,
            'description' => $this->description,
        ];
    }
}
