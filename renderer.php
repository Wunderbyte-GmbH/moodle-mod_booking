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
 * A custom renderer class that extends the plugin_renderer_base and
 * is used by the booking module.
 *
 * @package mod-booking
 * @copyright 2014 David Bogner, Andraž Prinčič
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/
class mod_booking_renderer extends plugin_renderer_base {
    /**
     * This method is used to generate HTML for a subscriber selection form that
     * uses two user_selector controls
     *
     * @param user_selector_base $existinguc
     * @param user_selector_base $potentialuc
     * @return string
     */
 	public function subscriber_selection_form(user_selector_base $existinguc, user_selector_base $potentialuc) {
        $output = '';
        $formattributes = array();
        $formattributes['id'] = 'subscriberform';
        $formattributes['action'] = '';
        $formattributes['method'] = 'post';
        $output .= html_writer::start_tag('form', $formattributes);
        $output .= html_writer::empty_tag('input', array('type'=>'hidden', 'name'=>'sesskey', 'value'=>sesskey()));

        $existingcell = new html_table_cell();
        $existingcell->text = $existinguc->display(true);
        $existingcell->attributes['class'] = 'existing';
        $actioncell = new html_table_cell();
        $actioncell->text  = html_writer::start_tag('div', array());
        $actioncell->text .= html_writer::empty_tag('input', array('type'=>'submit', 'name'=>'subscribe', 'value'=>$this->page->theme->larrow.' '.get_string('add'), 'class'=>'actionbutton'));
        $actioncell->text .= html_writer::empty_tag('br', array());
        $actioncell->text .= html_writer::empty_tag('input', array('type'=>'submit', 'name'=>'unsubscribe', 'value'=>$this->page->theme->rarrow.' '.get_string('remove'), 'class'=>'actionbutton'));
        $actioncell->text .= html_writer::end_tag('div', array());
        $actioncell->attributes['class'] = 'actions';
        $potentialcell = new html_table_cell();
        $potentialcell->text = $potentialuc->display(true);
        $potentialcell->attributes['class'] = 'potential';

        $table = new html_table();
        $table->attributes['class'] = 'subscribertable boxaligncenter';
        $table->data = array(new html_table_row(array($existingcell, $actioncell, $potentialcell)));
        $output .= html_writer::table($table);

        $output .= html_writer::end_tag('form');
        return $output;
    }

    /**
     * This function generates HTML to display a subscriber overview, primarily used on
     * the subscribers page if editing was turned off
     *
     * @param array $users
     * @param object $booking
     * @param object $option
     * @return string
     */
    public function subscriber_overview($users, $option , $course) {
        $output = '';
        if (!$users || !is_array($users) || count($users)===0) {
            $output .= $this->output->heading(get_string("nosubscribers", "booking"));
        } else {
            $output .= $this->output->heading(get_string("subscribersto","booking", "'".format_string($option->text)."'"));
            $table = new html_table();
            $table->cellpadding = 5;
            $table->cellspacing = 5;
            $table->tablealign = 'center';
            $table->data = array();
            foreach ($users as $user) {
                $table->data[] = array($this->output->user_picture($user, array('courseid'=>$course->id)), fullname($user), $user->email);
            }
            $output .= html_writer::table($table);
        }
        return $output;
    }

    /**
     * This is used to display a control containing all of the subscribed users so that
     * it can be searched
     *
     * @param user_selector_base $existingusers
     * @return string
     */
    public function subscribed_users(user_selector_base $existingusers) {
        $output  = $this->output->box_start('subscriberdiv boxaligncenter');
        $output .= html_writer::tag('p', get_string('forcessubscribe', 'booking'));
        $output .= $existingusers->display(true);
        $output .= $this->output->box_end();
        return $output;
    }
    
    /**
     * display all the bookings of the whole moodle site
     * sorted by course
     * @param booking_options $bookingoptions
     * @return string rendered html
     */
    public function render_bookings(booking_options $bookingoptions){
        $output = '';    
        $output .= html_writer::start_div();
        $header = html_writer::tag('h3', $bookingoptions->booking->name);
        $url = new moodle_url('/mod/booking/view.php', array('id' => $bookingoptions->cm->id));
        $output .= html_writer::link($url, $header);
        foreach ($bookingoptions->options as $optionid => $bookingoption){
            if(!empty($bookingoptions->allbookedusers[$optionid])){
                $waitinglist = array();                
                $output .= html_writer::tag('h4', $bookingoption->text);
                $output .= html_writer::start_div('mod-booking-regular');
                $output .= html_writer::div(get_string('bookedusers', 'booking'));
                $table = new html_table();
                $table->cellpadding = 1;
                $table->cellspacing = 0;
                $table->tablealign = 'left';
                $table->data = array();
                foreach($bookingoptions->allbookedusers[$optionid] as $user){
                    if($user->status[$optionid]->bookingvisible && $user->status[$optionid]->booked == 'booked'){
                        $table->data[] = array($this->output->user_picture($user, array('courseid'=>$bookingoptions->booking->course)), fullname($user) . "<br />" . $user->email);
                    } else if ($user->status[$optionid]->bookingvisible) {
                        $waitinglist[] = $user;
                    }
                }
                $output .= html_writer::table($table);
                $output .= html_writer::end_div();
            } 
            if(!empty($waitinglist)){
                $output .= html_writer::start_div('mod-booking-waiting');
                $output .= html_writer::div(get_string('waitinglistusers', 'booking'));
                $table = new html_table();
                $table->cellpadding = 1;
                $table->cellspacing = 0;
                $table->tablealign = 'left';
                $table->data = array();
                foreach($waitinglist as $user){
                    if($user->status[$optionid]->bookingvisible){
                        $table->data[] = array($this->output->user_picture($user, array('courseid'=>$bookingoptions->booking->course)), fullname($user) . "<br />" . $user->email);
                    }
                }
                $output .= html_writer::table($table);
                $output .= html_writer::end_div();
            }
        }
        $output .= html_writer::end_div();
        return $output;
    }
    
    /**
     * render userbookings for the whole site sorted per user
     * @param array $userbookings
     * @return string rendered html
     */
    public function render_bookings_per_user($userbookings){
        $output = html_writer::div(' ');
        $items = array();
        
        foreach($userbookings as $userid => $options){
            $items = array();
            
            foreach($options as $optionid => $user){
                // if the user is visible in only one booking instance, than show the user otherwise do not show
                if($user->status[$optionid]->bookingvisible){
                    $bookinginstanceurl = new moodle_url('/mod/booking/view.php', array('id' => $user->status[$optionid]->bookingcmid));
                    $bookingcourseurl = new moodle_url('/course/view.php', array('id' => $user->status[$optionid]->courseid));
                    $bookinglink = html_writer::link($bookinginstanceurl, $user->status[$optionid]->bookingtitle);
                    $courselink = html_writer::link($bookingcourseurl, $user->status[$optionid]->coursename);
                    $html = html_writer::span($user->status[$optionid]->bookingoptiontitle ." $bookinglink.  $courselink ". get_string($user->status[$optionid]->booked,'booking'));
                    $items[] = $html;
                }
            }
            if(!empty($items)){
                $user = reset($options);
                $output .= html_writer::tag('span', $this->output->user_picture($user) . " ".  fullname($user))." ";
                $output .= html_writer::link('mailto:'.$user->email, $user->email);                
                $output .= html_writer::alist($items);
            }
        }        

        return $output;
    }
}
