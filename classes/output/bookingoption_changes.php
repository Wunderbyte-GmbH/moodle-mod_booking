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
 * This file contains the definition for the renderable classes for booking option changes ("What has changed?").
 *
 * @package   mod_booking
 * @copyright 2021 Bernhard Fischer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

defined('MOODLE_INTERNAL') || die();

use renderer_base;
use renderable;
use templatable;


/**
 * This class prepares data for displaying booking option changes.
 *
 * @package mod_booking
 * @copyright 2021 Bernhard Fischer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption_changes implements renderable, templatable {

    /** @var array $changesarray an array containing fieldname, oldvalue and newvalue of changes */
    public $changesarray = null;

    /**
     * Constructor
     *
     * @param array $changesarray
     */
    public function __construct($changesarray) {
        $this->changesarray = $changesarray;
    }

    public function export_for_template(renderer_base $output) {

        $newchangesarray = [];
        foreach($this->changesarray as $entry) {
            if ($entry['fieldname'] == 'coursestarttime') {
                $newchangesarray[] = [
                    'fieldname' => get_string('coursestarttime', 'booking'),
                    'oldvalue' => userdate($entry['oldvalue'], get_string('strftimedatetime')),
                    'newvalue' => userdate($entry['newvalue'], get_string('strftimedatetime'))
                ];
            } else if ($entry['fieldname'] == 'courseendtime') {
                $newchangesarray[] = [
                    'fieldname' => get_string('courseendtime', 'booking'),
                    'oldvalue' => userdate($entry['oldvalue'], get_string('strftimedatetime')),
                    'newvalue' => userdate($entry['newvalue'], get_string('strftimedatetime'))
                ];
            } else {
                $newchangesarray[] = [
                    'fieldname' => get_string($entry['fieldname'], 'booking'),
                    'oldvalue' => $entry['oldvalue'],
                    'newvalue' => $entry['newvalue']
                ];
            }
        }

        return array(
            'changes' => $newchangesarray
        );
    }
}