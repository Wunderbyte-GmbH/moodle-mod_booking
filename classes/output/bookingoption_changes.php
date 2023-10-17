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

    public function export_for_template(renderer_base $output) {
        global $CFG;

        $newchangesarray = [];
        foreach ($this->changesarray as $entry) {

            $entry = (array)$entry;
            if (isset($entry['fieldname'])) {
                if ($entry['fieldname'] == 'coursestarttime') {
                    if (isset($entry['oldvalue']) && isset($entry['newvalue'])) {
                        $temparray = [
                            'fieldname' => get_string('coursestarttime', 'booking'),
                            'oldvalue' => userdate($entry['oldvalue'], get_string('strftimedatetime', 'langconfig')),
                            'newvalue' => userdate($entry['newvalue'], get_string('strftimedatetime', 'langconfig')),
                        ];
                    } else if (isset($entry['newvalue'])) {
                        $temparray = [
                            'fieldname' => get_string('coursestarttime', 'booking'),
                            'newvalue' => userdate($entry['newvalue'], get_string('strftimedatetime', 'langconfig')),
                        ];
                    } else {
                        $temparray = [
                            'fieldname' => get_string('coursestarttime', 'booking'),
                            'oldvalue' => userdate($entry['oldvalue'], get_string('strftimedatetime', 'langconfig')),
                        ];
                    }
                } else if ($entry['fieldname'] == 'courseendtime') {
                    if (isset($entry['oldvalue']) && isset($entry['newvalue'])) {
                        $temparray = [
                            'fieldname' => get_string('courseendtime', 'booking'),
                            'oldvalue' => userdate($entry['oldvalue'], get_string('strftimedatetime', 'langconfig')),
                            'newvalue' => userdate($entry['newvalue'], get_string('strftimedatetime', 'langconfig')),
                        ];
                    } else if (isset($entry['newvalue'])) {
                        $temparray = [
                            'fieldname' => get_string('courseendtime', 'booking'),
                            'newvalue' => userdate($entry['newvalue'], get_string('strftimedatetime', 'langconfig')),
                        ];
                    } else {
                        $temparray = [
                            'fieldname' => get_string('courseendtime', 'booking'),
                            'oldvalue' => userdate($entry['oldvalue'], get_string('strftimedatetime', 'langconfig')),
                        ];
                    }
                } else {
                    $temparray = [
                        'fieldname' => get_string($entry['fieldname'], 'booking'),
                        'oldvalue' => $entry['oldvalue'],
                        'newvalue' => $entry['newvalue'],
                    ];
                }

                // If there is an info field, then add it.
                if (isset($entry['info'])) {
                    $temparray = array_merge($temparray, ['info' => $entry['info']]);
                }

                // Now add the current change to the newchangesarray.
                $newchangesarray[] = $temparray;

            } else {
                // Custom fields with links to video meeting sessions.
                if (isset($entry['newname']) &&
                    ($entry['newname'] == 'TeamsMeeting'
                        || $entry['newname'] == 'ZoomMeeting'
                        || $entry['newname'] == 'BigBlueButtonMeeting')) {

                    // Never show the link directly, but use link.php instead.
                    $baseurl = $CFG->wwwroot;

                    // Fieldid is only present at updates, not at inserts.
                    if (isset($entry['customfieldid'])) {
                        $fieldid = $entry['customfieldid'];
                    } else {
                        $fieldid = null;
                    }

                    if (!empty($entry['optionid'])) {
                        $link = new moodle_url($baseurl . '/mod/booking/view.php',
                        [
                            'id' => $this->cmid,
                        ]);
                    } else {
                        $link = new moodle_url($baseurl . '/mod/booking/link.php',
                        [
                            'id' => $this->cmid,
                            'optionid' => $entry['optionid'],
                            'action' => 'join',
                            'sessionid' => $entry['optiondateid'],
                            'fieldid' => $fieldid,
                            'meetingtype' => $entry['newname'],
                        ]);
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
