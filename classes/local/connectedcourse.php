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
 * The cartstore class handles the in and out of the cache.
 *
 * @package mod_booking
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use core_course_external;
use mod_booking\singleton_service;
use moodle_exception;
use stdClass;

/**
 * Connected course class.
 * This class handles the logic of the creation of a moodle course.
 *
 * @author Georg Maißer
 * @copyright 2024 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connectedcourse {

    /**
     * Create new course.
     * @param stdClass $newoption
     * @param stdClass $formdata
     * @return void
     */
    public static function create_course_from_template_course(stdClass &$newoption, stdClass &$formdata) {

        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);

        // Retrieve the origin courseid from the form.
        $origincourseid = $formdata->coursetemplateid;
        if (empty($origincourseid)) {
            $newoption->courseid = 0;
        }

        $shortname = 'newcourseshortname';

        while ($DB->record_exists('course', ['shortname' => $shortname])) {
            $shortname = $shortname . '1';
        }

        $categoryid = self::retrieve_categoryid($newoption, $formdata);

        $courseinfo = \core_course_external::duplicate_course(
            $origincourseid,
            $settings->text,
            $shortname,
            $categoryid,
            1
        );
        if (!empty($courseinfo["id"])) {
            $newoption->courseid = $courseinfo["id"];
            $formdata->courseid = $courseinfo["id"];

            // Also, we need to take away all tags from the newly created course.
            $tags = \core_tag_tag::get_item_tags('core', 'course', $newoption->courseid);

            \core_tag_tag::delete_instances_by_id(array_keys($tags));
        }
    }

    /**
     * Deal with the different options of the user.
     * @param stdClass $newoption
     * @param stdClass $formdata
     * @return void
     */
    public static function handle_user_choice(stdClass &$newoption, stdClass &$formdata) {

        switch ($formdata->chooseorcreatecourse) {

            case 0:
                // Do nothing.
                $newoption->courseid = 0;
                $formdata->courseid = 0;
                break;
            case 1:
                // Choose a Moodle course.
                $newoption->courseid = $formdata->courseid ?: 0;
                break;
            case 2:
                // Create new Moodle course.
                self::create_new_course_in_category($newoption, $formdata);
                break;
            case 3:
                // Create new Moodle course from template.
                self::create_course_from_template_course($newoption, $formdata);
                $newoption->courseid = $newoption->courseid ?: 0;
                break;
        }
    }

    /**
     * Create a new course in the category.
     * @param stdClass $newoption
     * @param stdClass $formdata
     * @return int
     */
    private static function retrieve_categoryid(stdClass &$newoption, stdClass &$formdata) {

        global $DB;

        $config = get_config('booking', 'newcoursecategorycfield');
        if (!empty($config) && $config !== "-1") {
            // FEATURE add more settingfields add customfield_ to ...
            // ... settingsvalue from customfields allwo only Textfields or Selects.
            $cfforcategory = 'customfield_' . get_config('booking', 'newcoursecategorycfield');
            $category = new stdClass();
            $category->name = $formdata->{$cfforcategory};

            if (is_array($category->name)) {
                $category->name = reset($category->name);
            }

            if (is_string($category->name) && !empty($category->name)) {

                try {
                    $categories = core_course_external::get_categories([
                        ['key' => 'name', 'value' => $category->name],
                    ]);
                } catch (\Exception $e) {
                    $categories = [];
                }

                if (empty($categories)) {
                    $category->idnumber = $category->name;
                    $categories = [
                            ['name' => $category->name, 'idnumber' => $category->idnumber, 'parent' => 0],
                    ];
                    $createdcats = core_course_external::create_categories($categories);
                    $categoryid = $createdcats[0]['id'];
                } else {
                    $categoryid = $categories[0]['id'];
                }
            } else if (is_int($category->name)) {
                // We check if this is a valid category.
                if ($DB->record_exists("course_categories", ['id' => $category->name])) {
                    $categoryid = $category->name;
                }
            }
        }

        if (!isset($categoryid)) {
            $categories = core_course_external::get_categories();
            $firstcat = reset($categories);
            $categoryid = $firstcat['id'];
        }

        return $categoryid;
    }

    /**
     * Create a new course in the category.
     * @param stdClass $newoption
     * @param stdClass $formdata
     * @return object
     */
    private static function create_new_course_in_category(stdClass &$newoption, stdClass &$formdata) {

        global $DB;

        $categoryid = self::retrieve_categoryid($newoption, $formdata);

        // Create course.
        $fullnamewithprefix = '';
        if (!empty($formdata->titleprefix)) {
            $fullnamewithprefix .= $formdata->titleprefix . ' - ';
        }
        $fullnamewithprefix .= $formdata->text;

        // Courses need to have unique shortnames.
        $i = 1;
        $shortname = $fullnamewithprefix;
        while ($DB->get_record('course', ['shortname' => $shortname])) {
            $shortname = $fullnamewithprefix . '_' . $i;
            $i++;
        };
        $newcourse['fullname'] = $fullnamewithprefix;
        $newcourse['shortname'] = $shortname;
        $newcourse['categoryid'] = $categoryid;

        $courses = [$newcourse];
        $createdcourses = core_course_external::create_courses($courses);
        $newoption->courseid = $createdcourses[0]['id'];
        $formdata->courseid = $newoption->courseid;

    }

    /**
     * Build sql query with config filters.
     *
     * @return array
     */
    public static function return_tagged_template_courses() {
        global $DB;
        $where = "c.id IN (SELECT t.itemid FROM {tag_instance} t";
        $configs = get_config('booking', 'templatetags');

        if (empty($configs)) {
            return [];
        }
        // Search courses that are tagged with the specified tag.
        $configtags['OR'] = explode(',', str_replace(' ', '', $configs));

        $params = [];

        // Filter according to the tags.
        if ($configtags['OR'][0] != null) {
            $where .= " WHERE (";

            $indexparam = 0;
            foreach ($configtags as $operator => $tags) {
                if (!empty($tags[0])) {
                    $tagscount = count($tags);
                    foreach ($tags as $index => $tag) {
                        $tag = $DB->get_record('tag', ['id' => $tag], 'id, name');
                        if (!$tag) {
                            throw new moodle_exception('tagnotfoundindb', 'mod_booking');
                        }
                        $params['tag'. $indexparam] = $tag->id;
                        $where .= "t.tagid";
                        $where .= $operator == 'OR' ? ' = ' : ' != ';
                        $where .= ":tag" . $indexparam;
                        if ($index + 1 < $tagscount) {
                            $where .= ' ' . $operator .' ';
                        } else {
                            $where .= ")";
                        };
                        $indexparam += 1;
                    }
                }
            }
            $where .= ")";
        }

        return self::get_course_records($where, $params);
    }

    /**
     * Build sql query with config filters.
     * @param str $whereclause
     * @param array $params
     * @return object
     */
    protected static function get_course_records($whereclause, $params) {
        global $DB;
        $fields = ['c.id', 'c.fullname', 'c.shortname'];
        $sql = "SELECT ". join(',', $fields).
                " FROM {course} c
                JOIN {context} ctx ON c.id = ctx.instanceid
                AND ctx.contextlevel = :contextcourse
                WHERE " .
                $whereclause."ORDER BY c.sortorder";
        $list = $DB->get_records_sql($sql,
            ['contextcourse' => CONTEXT_COURSE] + $params);
        return $list;
    }
}
