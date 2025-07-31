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
 * This file contains the definition for the renderable classes for the booking module
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
 * This class prepares data for displaying the download form for signin sheet
 *
 * @package mod_booking
 * @copyright 2017 David Bogner {@link http://www.edulabs.org}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signin_downloadform implements renderable, templatable {
    /** @var int $id booking id */
    public $id = 0;

    /** @var int $optionid */
    public $optionid = 0;

    /** @var string $coursemoduleid */
    public $titleoption = '';

    /** @var string $titleinstanceoption */
    public $titleinstanceoption = '';

    /** @var string $instanceoption */
    public $instanceoption = '';

    /** @var \moodle_url $baseurl url to submit data to */
    public $baseurl = '';

    /** @var array $sessions */
    public $sessions = [];

    /** @var bool $teachersexist */
    public $teachersexist = false;

    /** @var string $htmlmode  */
    public $htmlmode = '';

    /**
     * Constructor
     *
     * @param \mod_booking\booking_option $bookingoption
     * @param \moodle_url $url baseurl
     */
    public function __construct(\mod_booking\booking_option $bookingoption, $url) {
        $this->titleinstanceoption = format_string($bookingoption->booking->settings->name) . ': ' .
            format_string($bookingoption->settings->get_title_with_prefix());
        $this->titleoption = format_string($bookingoption->settings->get_title_with_prefix());
        $this->instanceoption = format_string($bookingoption->booking->settings->name);
        $this->sessions = [];

        if (!empty($bookingoption->settings->sessions)) {
            foreach ($bookingoption->settings->sessions as $session) {
                $this->sessions[] = [
                    'sessiondateonly' => userdate($session->coursestarttime, get_string('strftimedate', 'langconfig')),
                    'coursestarttime' => userdate($session->coursestarttime, get_string('strftimedatetime', 'langconfig')),
                    'courseendtime' => userdate($session->courseendtime, get_string('strftimedatetime', 'langconfig')),
                    'id' => $session->id,
                ];
            }
        }
        $this->baseurl = $url->get_path();
        $this->id = $url->get_param('id');
        $this->optionid = $url->get_param('optionid');
        $signinsheetmode = get_config('booking', 'signinsheetmode');
        if ($signinsheetmode === 'htmltemplate') {
            $this->htmlmode = true;
        } else {
            $this->htmlmode = false;
        }
        if (!empty($bookingoption->teachers)) {
            $this->teachersexist = true;
        }
    }

    /**
     * Export for template
     *
     * @param renderer_base $output
     *
     * @return mixed
     *
     */
    public function export_for_template(renderer_base $output) {
        return $this;
    }
}
