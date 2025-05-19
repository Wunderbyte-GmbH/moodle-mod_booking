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
 *
 *
 * @package mod_booking
 * @author David Ala
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\services;

/**
 * Service to ensure required custom user profile fields exist.
 */
class evasysuser_profile_field_initializer {
    /** @var string */
    private const CATEGORY_NAME = 'evasys';

    /** @var string */
    private const FIELD_SHORTNAME = 'evasysid';

    /** @var string */
    private const FIELD_NAME = 'Evasysid';

    /**
     * Ensures the 'Evasysid' custom field exists in the 'evasys' category.
     *
     * If the category or the field does not exist, they will be created.
     *
     * @return void
     * @throws \moodle_exception
     */
    public static function ensure_evasyscustomfield_exists(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/user/profile/definelib.php');

        $category = $DB->get_record('user_info_category', ['name' => self::CATEGORY_NAME]);
        if (!$category) {
            $categorydata = (object)[
                'name' => self::CATEGORY_NAME,
            ];
            profile_save_category($categorydata);
            $category = $DB->get_record('user_info_category', ['name' => self::CATEGORY_NAME], '*', MUST_EXIST);
        }

        $field = $DB->get_record('user_info_field', ['shortname' => self::FIELD_SHORTNAME]);
        if ($field) {
            return;
        }

        $customfield = (object)[
            "datatype" => "text",
            "shortname" => self::FIELD_SHORTNAME,
            "name" => self::FIELD_NAME,
            "description" => [
                "text" => "",
                "format" => FORMAT_HTML,
            ],
            "required" => 0,
            "locked" => 1,
            "forceunique" => 0,
            "signup" => 0,
            "visible" => 0,
            "categoryid" => $category->id,
            "defaultdata" => "",
            "param1" => 30,
            "param2" => 2048,
            "param3" => 0,
        ];
        profile_save_field($customfield, []);
    }
}
