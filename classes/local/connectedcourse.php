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
use context_course;

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

        global $DB, $USER;

        $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);

        // Retrieve the origin courseid from the form.
        $origincourseid = $formdata->coursetemplateid;
        if (empty($origincourseid)) {
            $newoption->courseid = 0;
        }

        // Create course.
        $fullnamewithprefix = '';
        if (!empty($formdata->titleprefix)) {
            $fullnamewithprefix .= $formdata->titleprefix . ' - ';
        }

        // phpcs:ignore
        //$fullname = $settings->text ?? $fullnamewithprefix; // Preserved upon decision.

        $fullnamewithprefix .= trim($formdata->text);

        // Courses need to have unique shortnames.
        $i = 1;
        $shortname = !empty($fullnamewithprefix) ? $fullnamewithprefix : 'newshortname';
        while ($DB->get_record('course', ['shortname' => $shortname])) {
            $shortname = $fullnamewithprefix . '_' . $i;
            $i++;
        };

        $categoryid = self::retrieve_categoryid($newoption, $formdata);

        $options = [];

        if (empty($formdata->createnewmoodlecoursefromtemplatewithusers)) {
            $options[] = ['name' => 'users', 'value' => false];
            $options[] = ['name' => 'role_assignments', 'value' => false];
        }

        // We need to switch the user.
        $previoususer = $USER;
        $USER = get_admin();

        $courseinfo = core_course_external::duplicate_course(
            $origincourseid,
            $fullnamewithprefix,
            $shortname,
            $categoryid,
            1,
            $options
        );
        if (!empty($courseinfo["id"])) {
            $newoption->courseid = $courseinfo["id"];
            $formdata->courseid = $courseinfo["id"];

            // Also, we need to take away all tags from the newly created course.
            $tags = \core_tag_tag::get_item_tags('core', 'course', $newoption->courseid);

            \core_tag_tag::delete_instances_by_id(array_keys($tags));
        }

        $USER = $previoususer;
        fix_course_sortorder();
    }

    /**
     * Deal with the different options of the user.
     * @param stdClass $newoption
     * @param stdClass $formdata
     * @return void
     */
    public static function handle_user_choice(stdClass &$newoption, stdClass &$formdata) {

        switch ($formdata->chooseorcreatecourse ?? 1) {
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

            if (is_numeric($category->name)) {
                // We check if this is a valid category.
                if ($DB->record_exists("course_categories", ['id' => $category->name])) {
                    $categoryid = $category->name;
                }
            } else if (is_string($category->name) && !empty($category->name)) {
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
        $fullnamewithprefix .= trim($formdata->text);

        // Courses need to have unique shortnames.
        $i = 1;
        $shortname = !empty(self::clean_text($fullnamewithprefix)) ? $fullnamewithprefix : 'newshortname';
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

        return $formdata;
    }

    /**
     * Build sql query with config filters.
     * @param string $query
     * @return array
     */
    public static function return_tagged_template_courses(string $query = '') {
        global $DB, $USER;
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
                        $params['tag' . $indexparam] = $tag->id;
                        $where .= "t.tagid";
                        $where .= $operator == 'OR' ? ' = ' : ' != ';
                        $where .= ":tag" . $indexparam;
                        if ($index + 1 < $tagscount) {
                            $where .= ' ' . $operator . ' ';
                        } else {
                            $where .= ")";
                        };
                        $indexparam += 1;
                    }
                }
            }
            $where .= ")";
            // Add query, if there is any.
            if (!empty($query)) {
                $query1sql = $DB->sql_like('c.fullname', ':query1', false);
                $query2sql = $DB->sql_like('c.shortname', ':query2', false);

                $where .= " AND ($query1sql OR $query2sql )";
                $params['query1'] = '%' . $query . '%';
                $params['query2'] = '%' . $query . '%';
            }
        }

        $courses = self::get_course_records($where, $params);

        foreach ($courses as $key => $course) {
            $context = context_course::instance($course->id);
            if (
                !has_capability('moodle/course:view', $context)
                && !is_enrolled($context, $USER->id)
            ) {
                unset($courses[$key]);
            }
        }

        return $courses;
    }

    /**
     * Build sql query with config filters.
     * @param string $whereclause
     * @param array $params
     * @return array
     */
    protected static function get_course_records($whereclause, $params) {
        global $DB;
        $fields = ['c.id', 'c.fullname', 'c.shortname'];
        $sql = "SELECT " . join(',', $fields) .
                " FROM {course} c
                JOIN {context} ctx ON c.id = ctx.instanceid
                AND ctx.contextlevel = :contextcourse
                WHERE " .
                $whereclause . "ORDER BY c.sortorder";
        $list = $DB->get_records_sql(
            $sql,
            ['contextcourse' => CONTEXT_COURSE] + $params
        );
        return $list;
    }

    /**
     * Clean text to be able to use it as shortname.
     *
     * @param string $text
     * @return string
     *
     */
    private static function clean_text($text) {
        // Convert the text to lowercase.
        $lowertext = strtolower($text);
        // Remove all whitespace characters (spaces, tabs, newlines, etc.).
        $nowhitespacetext = preg_replace('/\s+/', '', $lowertext);
        // Remove all non-alphanumeric characters.
        $cleantext = preg_replace('/[^a-z0-9]/', '', $nowhitespacetext);
        return $cleantext;
    }
}
