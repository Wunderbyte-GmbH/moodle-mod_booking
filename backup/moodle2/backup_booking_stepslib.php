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
 * @package moodlecore
 * @subpackage backup-moodle2
 * @copyright 2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Define all the backup steps that will be used by the backup_booking_activity_task
 */

/**
 * Define the complete booking structure for backup, with file and id annotations
 */
class backup_booking_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        // To know if we are including userinfo
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated
        $booking = new backup_nested_element('booking', array('id'), array(
            'name', 'intro', 'introformat', 'bookingmanager',
            'sendmail', 'copymail', 'allowupdate', 'bookingpolicy',
            'bookingpolicyformat', 'timeopen', 'timeclose', 'showdatetime', 'limitanswers',
            'maxanswers', 'maxoverbooking', 'timemodified', 'autoenrol', 'bookedtext',
            'waitingtext', 'statuschangetext', 'deletedtext', 'maxperuser', 'sendmailtobooker',
            'duration', 'points', 'organizatorname', 'pollurl', 'addtogroup', 'categoryid',
            'pollurltext', 'additionalfields', 'eventtype', 'notificationtext', 'userleave',
            'enablecompletion', 'pollurlteachers', 'pollurlteacherstext', 'cancancelbook', 
            'conectedbooking', 'showinapi', 'lblbooking', 'lbllocation', 'lblinstitution',
            'lblname', 'lblsurname', 'btncacname', 'lblteachname', 'lblsputtname', 
            'btnbooknowname', 'btncancelname'));

        $options = new backup_nested_element('options');

        $option = new backup_nested_element('option', array('id'), array(
            'text', 'maxanswers', 'maxoverbooking','bookingopeningtime', 'bookingclosingtime', 'showdatetime', 'courseid', 
            'coursestarttime', 'courseendtime', 'description', 'descriptionformat', 'limitanswers', 'timemodified',
            'addtocalendar', 'calendarid', 'pollurl', 'groupid', 'daystonotify', 'notificationtext', 'notificationtextformat', 
            'sent', 'location', 'institution', 'address', 'pollurlteachers', 'conectedoption', 'howmanyusers', 'pollsend', 
            'removeafterminutes', 'disablebookingusers'));

        $answers = new backup_nested_element('answers');

        $answer = new backup_nested_element('answer', array('id'), array(
            'bookingid', 'userid', 'optionid', 'timemodified', 'completed', 'timecreated', 'waitinglist'));

        $categories = new backup_nested_element('categories');

        $category = new backup_nested_element('category', array('id'), array('cid', 'course', 'name'));
        
        $institutions = new backup_nested_element('institutions');

        $institution = new backup_nested_element('institution', array('id'), array('course', 'name'));

        $tags = new backup_nested_element('tags');

        $tag = new backup_nested_element('tag', array('id'), array(
          'courseid', 'tag', 'text', 'textformat'));
        
        $teachers = new backup_nested_element('teachers');

        $teacher = new backup_nested_element('teacher', array('id'), array(
            'bookingid', 'userid', 'optionid', 'completed'));
        
        // Build the tree
        $booking->add_child($options);
        $options->add_child($option);

        $booking->add_child($answers);
        $answers->add_child($answer);

        $booking->add_child($categories);
        $categories->add_child($category);
        
        $booking->add_child($institutions);
        $institutions->add_child($institution);
        
        $booking->add_child($tags);
        $tags->add_child($tag);
        
        $booking->add_child($teachers);
        $teachers->add_child($teacher);

        // Define sources
        $booking->set_source_table('booking', array('id' => backup::VAR_ACTIVITYID));

        $option->set_source_sql('
            SELECT *
              FROM {booking_options}
             WHERE bookingid = ?', array(backup::VAR_PARENTID));

        $category->set_source_table('booking_category', array('course' => backup::VAR_COURSEID));
        
        $institution->set_source_table('booking_institutions', array('course' => backup::VAR_COURSEID));

        $tag->set_source_table('booking_tags', array('courseid' => backup::VAR_COURSEID));
        
        // All the rest of elements only happen if we are including user info
        if ($userinfo) {
            $answer->set_source_table('booking_answers', array('bookingid' => '../../id'));
            $teacher->set_source_table('booking_teachers', array('bookingid' => backup::VAR_PARENTID));
        }

        // Define id annotations
        $answer->annotate_ids('user', 'userid');
        $teacher->annotate_ids('user', 'userid');

        // Define file annotations
        $booking->annotate_files('mod_booking', 'intro', null); // This file area hasn't itemid
      
        // Return the root element (booking), wrapped into standard activity structure
        return $this->prepare_activity_structure($booking);
    }

}
