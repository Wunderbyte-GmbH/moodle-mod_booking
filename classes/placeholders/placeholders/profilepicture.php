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
 * Handle fields for booking option.
 *
 * @package mod_booking
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\placeholders\placeholders;

use mod_booking\placeholders\placeholders_info;
use context_user;


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Control and manage placeholders for booking instances, options and mails.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class profilepicture {

    /**
     * Function which takes a text, replaces the placeholders...
     * ... and returns the text with the correct values.
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param int $installmentnr
     * @param int $duedate
     * @param float $price
     * @param string $text
     * @param array $params
     * @param int $descriptionparam
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0,
        string &$text = '',
        array &$params = [],
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE) {

        $classname = substr(strrchr(get_called_class(), '\\'), 1);

        if (!empty($userid)) {

            // The cachekey depends on the kind of placeholder and it's ttl.
            // If it's the same for all users, we don't use userid.
            // If it's the same for all options of a cmid, we don't use optionid.
            $cachekey = "$classname-$optionid";
            if (isset(placeholders_info::$placeholders[$cachekey])) {
                return placeholders_info::$placeholders[$cachekey];
            }

            // Add a param for the user profile picture so we can show it in e-mails.
            if ($usercontext = context_user::instance($userid, IGNORE_MISSING)) {
                $fs = get_file_storage();
                $files = $fs->get_area_files($usercontext->id, 'user', 'icon');
                $picturefile = null;
                foreach ($files as $file) {
                    $filenamewithoutextension = explode('.', $file->get_filename())[0];
                    if ($filenamewithoutextension === 'f1') {
                        $picturefile = $file;
                        // We found it, so break the loop.
                        break;
                    }
                }
                if ($picturefile) {
                    // Retrieve the image contents and encode them as base64.
                    $picturedata = $picturefile->get_content();
                    $picturebase64 = base64_encode($picturedata);
                    // Now load the HTML of the image into the profilepicture param.
                    $value = '<img src="data:image/image;base64,' . $picturebase64 . '" />';
                } else {
                    $value = '';
                }
            }
        } else {
            $value = get_string('sthwentwrongwithplaceholder', 'mod_booking', $classname);
        }

        return $value;
    }

    /**
     * Function determine if placeholder class should be called at all.
     *
     * @return bool
     *
     */
    public static function is_applicable(): bool {
        return true;
    }
}
