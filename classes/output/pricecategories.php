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
 * This file contains the definition for the renderable classes for pricecategories.
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use renderer_base;
use renderable;
use templatable;

/**
 * This file contains the definition for the renderable classes for pricecategories.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author      Bernhard Fischer-Sengseis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pricecategories implements renderable, templatable {
    /** @var string $renderedpricecategoriesform */
    public $renderedpricecategoriesform = '';

    /** @var string $existingpricecategories */
    public $existingpricecategories = '';

    /**
     * Constructor
     *
     * @param string $renderedpricecategoriesform the rendered pricecategories form
     */
    public function __construct(string $renderedpricecategoriesform) {
        global $DB;
        $this->renderedpricecategoriesform = $renderedpricecategoriesform;
        $existingpricecategories = $DB->get_records('booking_pricecategories', null, 'id ASC');
        $this->existingpricecategories = base64_encode(json_encode($existingpricecategories));
    }

    /**
     * Export for template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        $returnarray = [
            'renderedpricecategoriesform' => $this->renderedpricecategoriesform,
        ];
        return $returnarray;
    }
}
