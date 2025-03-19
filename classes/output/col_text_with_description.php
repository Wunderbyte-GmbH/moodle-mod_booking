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
 * This file contains the definition for the renderable classes for col_text with description.
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
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class col_text_with_description implements renderable, templatable {
    /** @var int $optionid */
    public $optionid = null;

    /** @var string $text */
    public $text = null;

    /** @var string $titleprefix */
    public $titleprefix = null;

    /** @var string $description */
    public $description = null;

    /**
     * Constructor
     * @param int $optionid option id
     * @param string $text the option's title (field 'text')
     * @param string $titleprefix prefix to be shown before the title
     * @param string $description the option's description
     */
    public function __construct(int $optionid, string $text, string $titleprefix, string $description) {
        $this->optionid = $optionid;
        $this->text = $text;
        $this->titleprefix = $titleprefix;
        $this->description = $description;
    }

    /**
     * Export the values for the mustache template.
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        $returnarr = [
            'optionid' => $this->optionid,
            'text' => $this->text,
            'description' => $this->description,
        ];

        // Only add titleprefix if it exists.
        if (!empty($this->titleprefix)) {
            $returnarr['titleprefix'] = $this->titleprefix;
        }

        return $returnarr;
    }
}
