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
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context_system;
use mod_booking\singleton_service;
use moodle_url;
use renderer_base;
use renderable;
use templatable;
use user_picture;

/**
 * This class prepares data for displaying a booking instance
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class business_card implements renderable, templatable {
    /** @var string $username the note as it is saved in db */
    public $username = null;

    /** @var moodle_url $sendmessageurl */
    public $sendmessageurl = null;

    /** @var string $description */
    public $description = null;

    /** @var string $userdescription */
    public $userdescription = null;

    /** @var moodle_url $userprofileurl */
    public $userprofileurl = null;

    /** @var moodle_url $userpictureurl */
    public $userpictureurl = null;

    /** @var string $duration */
    public $duration = null;

    /** @var string $points */
    public $points = null;

    /** @var string $errormessage */
    public $errormessage = null;

    /**
     * Constructor
     *
     * @param object $bookingsettings
     * @param int $userid
     *
     */
    public function __construct($bookingsettings, $userid) {

        global $PAGE;

        $syscontext = context_system::instance();

        $user = user_get_users_by_id([$userid]);
        $user = reset($user);

        // Add safety, if - for some reason - user is  not found.
        if (empty($user)) {
            // Try once more using singleton.
            $user = singleton_service::get_instance_of_user($userid);
            // If it's still empty, we prepare the template to show an error.
            if (empty($user)) {
                if (has_capability('mod/booking:updatebooking', $syscontext)) {
                    $this->errormessage = get_string('errorusernotfound', 'mod_booking', $userid);
                }
                return;
            }
        }

        $userpic = new user_picture($user);
        $userpic->size = 200;
        $userpictureurl = $userpic->get_url($PAGE);
        $userprofileurl = new moodle_url('../../user/profile.php', ['id' => $user->id]);
        $sendmessageurl = new moodle_url('../../message/index.php', ['id' => $user->id]);
        $userdescription = format_text($user->description, $user->descriptionformat);

        $this->username = "$user->firstname $user->lastname";
        $this->userpictureurl = $userpictureurl->out();
        $this->userprofileurl = $userprofileurl->out();
        $this->sendmessageurl = $sendmessageurl->out();
        $this->userdescription = $userdescription;
        $this->duration = $bookingsettings->duration;
        $this->points = null;
        // Only show points if there are any.
        if ($bookingsettings->points != '0.00') {
            $this->points = $bookingsettings->points;
        }
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
        return [
            'username' => $this->username,
            'userpictureurl' => $this->userpictureurl,
            'userprofileurl' => $this->userprofileurl,
            'sendmessageurl' => $this->sendmessageurl,
            'userdescription' => $this->userdescription,
            'duration' => $this->duration,
            'points' => $this->points,
            'errormessage' => $this->errormessage,
        ];
    }
}
