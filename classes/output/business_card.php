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
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
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
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class business_card implements renderable, templatable {

    /** @var string $username the note as it is saved in db */
    public $username = null;

    /** @var string $userpictureurl */
    public $userpictureurl = null;

    /** @var string $sendmessageurl */
    public $sendmessageurl = null;

    /** @var string $description */
    public $description = null;

    /** @var string $userdescription */
    public $userdescription = null;

    /** @var string $userprofileurl */
    public $userprofileurl = null;

    /** @var string $duration */
    public $duration = null;

    /** @var string $points */
    public $points = null;

    /**
     * Constructor
     *
     * @param \stdClass $data
     */
    public function __construct($booking, $userid) {

        global $PAGE;

        $user = user_get_users_by_id([$userid]);
        $user = reset($user);
        $userpic = new \user_picture($user);
        $userpic->size = 200;
        $userpictureurl = $userpic->get_url($PAGE);
        $userprofileurl = new \moodle_url('../../user/profile.php', ['id' => $user->id]);
        $sendmessageurl = new \moodle_url('../../message/index.php', ['id' => $user->id]);
        $description = format_text($booking->settings->intro, $booking->settings->introformat);
        $userdescription = format_text($user->description, $user->descriptionformat);

        $this->username = "$user->firstname $user->lastname";
        $this->userpictureurl = $userpictureurl;
        $this->userprofileurl = $userprofileurl;
        $this->sendmessageurl = $sendmessageurl;
        $this->description = $description;
        $this->userdescription = $userdescription;
        $this->duration = $booking->settings->duration;
        $this->points = null;
        // Only show points if there are any.
        if ($booking->settings->points != '0.00') {
            $this->points = $booking->settings->points;
        }
    }

    public function export_for_template(renderer_base $output) {
        return array(
                'username' => $this->username,
                'userpictureurl' => $this->userpictureurl->out(),
                'userprofileurl' => $this->userprofileurl->out(),
                'sendmessageurl' => $this->sendmessageurl->out(),
                'description' => $this->description,
                'userdescription' => $this->userdescription,
                'duration' => $this->duration,
                'points' => $this->points
        );
    }
}
