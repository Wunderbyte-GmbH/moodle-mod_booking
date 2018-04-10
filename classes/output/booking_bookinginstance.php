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

defined('MOODLE_INTERNAL') || die();

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
class booking_bookinginstance implements renderable, templatable {

    /** @var int $coursemoduleid */
    public $coursemoduleid = 0;

    /** @var string bookingname */
    public $bookingname = '';

    /** @var \moodle_url $url */
    public $url = null;

    /** @var array $options */
    public $options = array();

    /**
     * Constructor
     *
     * @param string $sort sort by course/user/my
     * @param \stdClass $data
     */
    public function __construct($sort, $data) {
        global $OUTPUT, $USER;
        $this->bookingname = $data->name;
        $this->coursemoduleid = $data->coursemodule;
        $url = new \moodle_url('/mod/booking/view.php', array('id' => $this->coursemoduleid));
        $this->url = $url->out();
        if (!empty($data->options)) {
            foreach ($data->options as $option) {
                $params = array('id' => $this->coursemoduleid, 'optionid' => $option->option->id);
                $url = new \moodle_url("/mod/booking/report.php", $params);
                $link = \html_writer::link($url, $option->option->text,
                        array('class' => 'btn btn-secondary'));
                $regulars = array();
                $waiting = array();
                if ($sort != 'my') {
                    if (!empty($option->usersonlist)) {
                        foreach ($option->usersonlist as $user) {
                            $user->id = $user->userid;
                            $userdata = new \user_picture($user);
                            $regulars[] = $OUTPUT->render($userdata) . ' ' . fullname($user);
                        }
                    }
                    if (!empty($option->usersonwaitinglist)) {
                        foreach ($option->usersonwaitinglist as $user) {
                            $user->id = $user->userid;
                            $userdata = new \user_picture($user);
                            $waiting[] = $OUTPUT->render($userdata) . ' ' . fullname($user);
                        }
                    }
                } else {
                    if (!empty($option->usersonlist) && isset($option->usersonlist[$USER->id])) {
                        $userdata = new \user_picture($USER);
                        $regulars[] = $OUTPUT->render($userdata) . ' ' . fullname($USER);
                    }
                    if (!empty($option->usersonwaitinglist) &&
                             isset($option->usersonwaitinglist[$USER->id])) {
                        $userdata = new \user_picture($USER);
                        $waiting[] = $OUTPUT->render($userdata) . ' ' . fullname($USER);
                    }
                }
                $waitinglistusersexist = false;
                $regularusersexist = false;
                if (!empty($regulars)) {
                    $regularusersexist = true;
                }
                if (!empty($waiting)) {
                    $waitinglistusersexist = true;
                }
                $this->options[] = array('name' => $link, 'regular' => $regulars,
                    'waiting' => $waiting, 'regularusersexist' => $regularusersexist,
                    'waitinglistusersexist' => $waitinglistusersexist);
            }
        }
    }

    public function export_for_template(renderer_base $output) {
        return $this;
    }
}