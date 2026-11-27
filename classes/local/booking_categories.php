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
 * Changes to the booking categories of a course.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use stdClass;

/**
 * Changes to the booking categories of a course.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_categories {
    /**
     * Delete a category of a course if it is not in use.
     * The rights of the user were checked for the course, so only a category of this course is deleted.
     *
     * @param int $courseid the course the user manages the categories of
     * @param int $cid id of the category
     * @return string message for the user
     */
    public static function delete(int $courseid, int $cid): string {
        global $DB;

        if (!self::belongs_to_course($courseid, $cid)) {
            return get_string('error');
        }
        if ($DB->record_exists('booking_category', ['cid' => $cid])) {
            return get_string('deletesubcategory', 'booking');
        }
        if (self::is_used_in_booking($cid)) {
            return get_string('usedinbooking', 'booking');
        }

        $DB->delete_records('booking_category', ['id' => $cid, 'course' => $courseid]);
        return get_string('successfulldeleted', 'booking');
    }

    /**
     * Save the data of the category form.
     * The rights of the user were checked for the course, so the category that is changed, its
     * parent and the course of the category are always the ones of this course.
     *
     * @param int $courseid the course the user manages the categories of
     * @param int $cid id of the category that is edited, 0 for a new one
     * @param stdClass $data data of the form
     * @return void
     */
    public static function save(int $courseid, int $cid, stdClass $data): void {
        global $DB;

        $id = (int)($data->id ?? 0);
        if ($id > 0 && !self::belongs_to_course($courseid, $id)) {
            return;
        }

        $category = new stdClass();
        $category->name = $data->name;
        $category->course = $courseid;

        // The parent is a category of the same course, and a category is not its own parent.
        $parentid = (int)($data->cid ?? 0);
        if ($parentid === $id || $parentid === $cid || !self::belongs_to_course($courseid, $parentid)) {
            $parentid = 0;
        }
        $category->cid = $parentid;

        if ($id > 0) {
            $category->id = $id;
            $DB->update_record('booking_category', $category);
        } else {
            $DB->insert_record('booking_category', $category);
        }
    }

    /**
     * Whether a category belongs to a course.
     *
     * @param int $courseid
     * @param int $cid id of the category
     * @return bool
     */
    public static function belongs_to_course(int $courseid, int $cid): bool {
        global $DB;

        return $cid > 0 && $DB->record_exists('booking_category', ['id' => $cid, 'course' => $courseid]);
    }

    /**
     * Whether a booking instance uses the category. A booking instance stores its categories
     * as a list of ids separated by commas.
     *
     * @param int $cid id of the category
     * @return bool
     */
    private static function is_used_in_booking(int $cid): bool {
        global $DB;

        $lists = $DB->get_fieldset_select('booking', 'categoryid', 'categoryid IS NOT NULL');
        foreach ($lists as $list) {
            if (in_array($cid, array_map('intval', explode(',', (string)$list)), true)) {
                return true;
            }
        }
        return false;
    }
}
