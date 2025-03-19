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

use mod_booking\option\dates_handler;
use mod_booking\option\fields\pollurl;
use mod_booking\option\fields_info;
use mod_booking\singleton_service;
use renderer_base;
use renderable;
use templatable;
use html_writer;
use moodle_url;

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

    /** @var null course module id */
    public $cmid = null;

    /**
     * Constructor
     *
     * @param array $changesarray an array containing fieldname, oldvalue and newvalue of changes
     * @param int $cmid course module id
     */
    public function __construct(array $changesarray, int $cmid) {
        $this->changesarray = $changesarray['changes'] ?? [];
        $this->cmid = $cmid;
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
        global $CFG;

        $newchangesarray = [];
        foreach ($this->changesarray as $entry) {
            $entry = (array)$entry;
            if (isset($entry['fieldname'])) {
                $fieldname = $entry['fieldname'];
                $classname = fields_info::get_namespace_from_class_name($fieldname);
                if (!empty($classname)) {
                    $fieldsclass = new $classname();
                    $changes = $fieldsclass->get_changes_description($entry);
                } else if ($fieldname == "pollurlteachers") {
                    // Todo: Create dummy fields class to access abstract method get_changes_description generically.
                    $fieldsclass = new pollurl();
                    $changes = $fieldsclass->get_changes_description($entry);
                    $changes['fieldname'] = get_string($fieldname, 'mod_booking');
                } else {
                    // Probably the classname doesn't match the namespace.
                    $changes = [];
                }

                // Now add the current change to the newchangesarray.
                $newchangesarray[] = $changes;
            } else {
                // Custom fields with links to video meeting sessions.
                if (
                    isset($entry['newname'])
                    && preg_match('/^((zoom)|(big.*blue.*button)|(teams)).*meeting$/i', $entry['newname'])
                ) {
                    // Never show the link directly, but use link.php instead.
                    $baseurl = $CFG->wwwroot;

                    // Fieldid is only present at updates, not at inserts.
                    if (isset($entry['customfieldid'])) {
                        $fieldid = $entry['customfieldid'];
                    } else {
                        $fieldid = null;
                    }

                    if (!empty($entry['optionid'])) {
                        $link = new moodle_url(
                            $baseurl . '/mod/booking/view.php',
                            [
                                'id' => $this->cmid,
                            ]
                        );
                    } else {
                        $link = new moodle_url(
                            $baseurl . '/mod/booking/link.php',
                            [
                                'id' => $this->cmid,
                                'optionid' => $entry['optionid'],
                                'action' => 'join',
                                'sessionid' => $entry['optiondateid'],
                                'fieldid' => $fieldid,
                                'meetingtype' => $entry['newname'],
                            ]
                        );
                    }

                    $entry['newvalue'] = html_writer::link($link, $link->out());
                }

                // Add all custom fields here.
                $newchangesarray[] = $entry;
            }
        }

        return [
            'changes' => $newchangesarray,
        ];
    }
}
