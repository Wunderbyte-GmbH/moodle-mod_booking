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
 * Define all the restore steps that will be used by the restore_booking_activity_task
 */

/**
 * Structure step to restore one booking activity
 */
class restore_booking_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('booking', '/activity/booking');
        $paths[] = new restore_path_element('booking_option', '/activity/booking/options/option');
        $paths[] = new restore_path_element('booking_category', '/activity/booking/categories/category');        
        $paths[] = new restore_path_element('booking_institution', '/activity/booking/institutions/institution');
        $paths[] = new restore_path_element('booking_tag', '/activity/booking/tags/tag');
        if ($userinfo) {
            $paths[] = new restore_path_element('booking_answer', '/activity/booking/answers/answer');
            $paths[] = new restore_path_element('booking_teacher', '/activity/booking/teachers/teacher');
        }

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_booking($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        $data->timeopen = $this->apply_date_offset($data->timeopen);
        $data->timeclose = $this->apply_date_offset($data->timeclose);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        // insert the booking record
        $newitemid = $DB->insert_record('booking', $data);
        // immediately after inserting "activity" record, call this
        $this->apply_activity_instance($newitemid);
    }

    protected function process_booking_option($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newitemid = $DB->insert_record('booking_options', $data);
        $this->set_mapping('booking_option', $oldid, $newitemid);
    }

    protected function process_booking_answer($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        
        $newitemid = $DB->insert_record('booking_answers', $data);
        // No need to save this mapping as far as nothing depend on it
        // (child paths, file areas nor links decoder)
    }

    protected function process_booking_category($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // insert the booking_institutions record
        $oldcategories = $DB->get_records('booking_category', array('course' => $data->course));
        $countsamename = 0;
        $countdiffcid = 0;
        foreach ($oldcategories as $oldcategory) {
            $namecompare =  strcmp($data->name, $oldcategory->name);
            if($namecompare == 0) {
                   $countsamename = 1;
                   $newitemid = $oldcategory->id;
            }
        }
        if(count($oldcategories)== 0 || $countsamename == 0) {
            if ($data->cid > 0) {
                $diffcategories = $oldid - $data->cid;
            }
            $newitemid = $DB->insert_record('booking_category', $data);
            if($data->cid > 0) {
                $newdata = $DB->get_record('booking_category', array('id' => $newitemid));
                $newdata->cid= $newdata->id - $diffcategories;
                $newitemcid = $DB->update_record('booking_category', $newdata);
            }
        }
        // Update the categories number in table booking
        $newdata = $DB->get_record('booking_category', array('id' => $newitemid));
        $bookingid = $this->get_new_parentid('booking');
        $booking = $DB->get_record('booking', array('id' => $bookingid));
        if(!$booking->categoryid == NULL) {
            $oldcategoryids = explode(',', $booking->categoryid);
            
            foreach($oldcategoryids as $oldcategoryid) {
                if($oldcategoryid == $data->id) {
                    $newbooking->categoryid = str_replace($oldcategoryid, $newdata->id, $booking->categoryid);
                    $newbooking->id = $bookingid;
                    $newitemid = $DB->update_record('booking', $newbooking);  
                }
            }                     
            
        }
    }
    
    protected function process_booking_institution($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();

        // insert the booking_institutions record
        $oldinstitutions = $DB->get_records('booking_institutions', array('course' => $data->course));
        $countsamename = 0;
        foreach ($oldinstitutions as $oldinstitution) {
            $namecompare =  strcmp($data->name, $oldinstitution->name);#
            if($namecompare == 0) {
                $countsamename = $countsamename + 1;
            }
        }
        if(count($oldinstitutions)== 0 || $countsamename == 0) {
            $newitemid = $DB->insert_record('booking_institutions', $data);
        }
    }
    
     protected function process_booking_tag($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->courseid = $this->get_courseid();

        // insert the booking_tags record
        $oldtags = $DB->get_records('booking_tags', array('courseid' => $data->courseid, 'tag' => $data->tag));
        if(count($oldtags) == 0) {
            $newitemid = $DB->insert_record('booking_tags', $data);
        }
        
        // If you want to update the field text with the values of the backup
        //foreach ($oldtags as $oldtag) {
        //    $textcompare =  strcmp($data->text, $oldtag->text);
        //}
        //if (!$textcompare == 0) {
        //    $data->id = $oldtag->id;
        //    $newitemid = $DB->update_record('booking_tags', $data);
        //} 
    }
    
    protected function process_booking_teacher($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;

        $data->bookingid = $this->get_new_parentid('booking');
        $data->optionid = $this->get_mappingid('booking_option', $data->optionid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        
        // insert booking_teachers record
        $newitemid = $DB->insert_record('booking_teachers', $data);
        // No need to save this mapping as far as nothing depend on it
        // (child paths, file areas nor links decoder)
    }

    protected function after_execute() {
        // Add booking related files, no need to match by itemname (just internally handled context)
        $this->add_related_files('mod_booking', 'intro', null);
    }

}
