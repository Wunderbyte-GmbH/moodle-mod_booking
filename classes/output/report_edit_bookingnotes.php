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
 * @copyright 2017 David Bogner {@link http://www.edulabs.org}
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
 * @copyright 2017 David Bogner {@link http://www.edulabs.org}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_edit_bookingnotes implements renderable, templatable {
    /** @var string $note the note as it is saved in db */
    public $note = null;

    /** @var int bookinganswer id */
    public $baid = 0;

    /** @var bool indicates if the current user is allowed to edit - set in constructor after permissions are checked */
    protected $editable = false;

    /**
     * @var string value of the editable element as it should be displayed,
     * must be formatted and may contain links or other html tags
     */
    protected $displayvalue = null;

    /**
     * Constructor
     *
     * @param array $data
     */
    public function __construct(array $data) {
        $this->note = $data['note'];
        if (empty($data['note'])) {
            $this->note = " ";
        }
        $this->displayvalue = $data['note'];
        $this->baid = $data['baid'];
        $this->editable = $data['editable'];
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
        if (!$this->editable) {
            return ['displayvalue' => (string) $this->displayvalue];
        }

        return ['note' => $this->note, 'baid' => $this->baid,
        ];
    }
}
