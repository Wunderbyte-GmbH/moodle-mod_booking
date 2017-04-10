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
 * A custom renderer class that extends the plugin_renderer_base and is used by the booking module.
 *
 * @package mod-booking
 * @copyright 2014 David Bogner, Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_booking_renderer extends plugin_renderer_base {

    // Prints tabs for options.
    public function print_booking_tabs($urlparams, $current = 'showactive', $mybookings = 0, $myoptions = 0) {

        // Output tabs
        $row = array();

        unset($urlparams['sort']);
        $tmpurlparams = $urlparams; // "#goenrol"

        $tmpurlparams['whichview'] = 'myinstitution';
        $row[] = new tabobject('myinstitution',
                new moodle_url('/mod/booking/view.php', $tmpurlparams, "goenrol"),
                get_string('showonlymyinstitutions', 'booking'));
        $tmpurlparams['whichview'] = 'showactive';
        $row[] = new tabobject('showactive',
                new moodle_url('/mod/booking/view.php', $tmpurlparams, "goenrol"),
                get_string('showactive', 'booking'));
        $tmpurlparams['whichview'] = 'showall';
        $row[] = new tabobject('showall',
                new moodle_url('/mod/booking/view.php', $tmpurlparams, "goenrol"),
                get_string('showallbookings', 'booking'));
        $tmpurlparams['whichview'] = 'mybooking';
        $row[] = new tabobject('mybooking',
                new moodle_url('/mod/booking/view.php', $tmpurlparams, "goenrol"),
                get_string('showmybookings', 'booking', $mybookings));

        if ($myoptions > 0) {
            $tmpurlparams['whichview'] = 'myoptions';
            $row[] = new tabobject('myoptions',
                    new moodle_url('/mod/booking/view.php', $tmpurlparams, "goenrol"),
                    get_string('myoptions', 'booking', $myoptions));
        }

        echo $this->tabtree($row, $current);
    }

    /**
     * This method is used to generate HTML for a subscriber selection form that uses two user_selector controls
     *
     * @param user_selector_base $existinguc
     * @param user_selector_base $potentialuc
     * @return string
     */
    public function subscriber_selection_form(user_selector_base $existinguc,
            user_selector_base $potentialuc) {
        $output = '';
        $formattributes = array();
        $formattributes['id'] = 'subscriberform';
        $formattributes['action'] = '';
        $formattributes['method'] = 'post';
        $output .= html_writer::start_tag('form', $formattributes);
        $output .= html_writer::empty_tag('input',
                array('type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()));

        $existingcell = new html_table_cell();
        $existingcell->text = $existinguc->display(true);
        $existingcell->attributes['class'] = 'existing';
        $actioncell = new html_table_cell();
        $actioncell->text = html_writer::start_tag('div', array());
        $actioncell->text .= html_writer::empty_tag('input',
                array('type' => 'submit', 'name' => 'subscribe',
                    'value' => $this->page->theme->larrow . ' ' . get_string('add'),
                    'class' => 'actionbutton'));
        $actioncell->text .= html_writer::empty_tag('br', array());
        $actioncell->text .= html_writer::empty_tag('input',
                array('type' => 'submit', 'name' => 'unsubscribe',
                    'value' => $this->page->theme->rarrow . ' ' . get_string('remove'),
                    'class' => 'actionbutton'));
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
     * This function generates HTML to display a subscriber overview, primarily used on the subscribers page if editing was turned off
     *
     * @param array $users
     * @param object $booking
     * @param object $option
     * @return string
     */
    public function subscriber_overview($users, $option, $course) {
        $output = '';
        if (!$users || !is_array($users) || count($users) === 0) {
            $output .= $this->output->heading(get_string("nosubscribers", "booking"));
        } else {
            $output .= $this->output->heading(
                    get_string("subscribersto", "booking", "'" . format_string($option->text) . "'"));
            $table = new html_table();
            $table->cellpadding = 5;
            $table->cellspacing = 5;
            $table->tablealign = 'center';
            $table->data = array();
            foreach ($users as $user) {
                $table->data[] = array(
                    $this->output->user_picture($user, array('courseid' => $course->id)),
                    fullname($user), $user->email);
            }
            $output .= html_writer::table($table);
        }
        return $output;
    }

    /**
     * This is used to display a control containing all of the subscribed users so that it can be searched
     *
     * @param user_selector_base $existingusers
     * @return string
     */
    public function subscribed_users(user_selector_base $existingusers) {
        $output = $this->output->box_start('subscriberdiv boxaligncenter');
        $output .= html_writer::tag('p', get_string('forcessubscribe', 'booking'));
        $output .= $existingusers->display(true);
        $output .= $this->output->box_end();
        return $output;
    }

    /**
     * render userbookings for the whole site sorted per user
     *
     * @param array $userbookings
     * @return string rendered html
     */
    public function render_bookings_per_user($userbookings) {
        $output = html_writer::div(' ');
        $items = array();

        foreach ($userbookings as $userid => $options) {
            $items = array();

            foreach ($options as $optionid => $user) {
                // if the user is visible in only one booking instance, than show the user otherwise do not show
                if ($user->status[$optionid]->bookingvisible) {
                    $bookinginstanceurl = new moodle_url('/mod/booking/view.php',
                            array('id' => $user->status[$optionid]->bookingcmid));
                    $bookingcourseurl = new moodle_url('/course/view.php',
                            array('id' => $user->status[$optionid]->courseid));
                    $bookinglink = html_writer::link($bookinginstanceurl,
                            $user->status[$optionid]->bookingtitle);
                    $courselink = html_writer::link($bookingcourseurl,
                            $user->status[$optionid]->coursename);
                    $html = html_writer::span(
                            $user->status[$optionid]->bookingoptiontitle .
                                     " $bookinglink.  $courselink " .
                                     get_string($user->status[$optionid]->booked, 'booking'));
                    $items[] = $html;
                }
            }
            if (!empty($items)) {
                $user = reset($options);
                $output .= html_writer::tag('span',
                        $this->output->user_picture($user) . " " . fullname($user)) . " ";
                $output .= html_writer::link('mailto:' . $user->email, $user->email);
                $output .= html_writer::alist($items);
            }
        }

        return $output;
    }

    /**
     * Produces the html that represents this rating in the UI
     *
     * @param rating $rating the page object on which this rating will appear
     * @return string
     */
    public function render_rating(rating $rating) {
        global $CFG, $USER;

        if ($rating->settings->aggregationmethod == RATING_AGGREGATE_NONE) {
            return null; // ratings are turned off
        }

        $ratingmanager = new rating_manager();
        // Initialise the JavaScript so ratings can be done by AJAX.

        $strrate = get_string("rate", "rating");
        $ratinghtml = ''; // the string we'll return

        // permissions check - can they view the aggregate?
        if ($rating->user_can_view_aggregate()) {

            $aggregatelabel = $ratingmanager->get_aggregate_label(
                    $rating->settings->aggregationmethod);
            $aggregatestr = $rating->get_aggregate_string();

            $aggregatehtml = html_writer::tag('span', $aggregatestr,
                    array('id' => 'ratingaggregate' . $rating->itemid, 'class' => 'ratingaggregate')) .
                     ' ';
            if ($rating->count > 0) {
                $countstr = "({$rating->count})";
            } else {
                $countstr = '-';
            }
            $aggregatehtml .= html_writer::tag('span', $countstr,
                    array('id' => "ratingcount{$rating->itemid}", 'class' => 'ratingcount')) . ' ';

            $ratinghtml .= html_writer::tag('span', $aggregatelabel,
                    array('class' => 'rating-aggregate-label'));
            if ($rating->settings->permissions->viewall &&
                     $rating->settings->pluginpermissions->viewall) {

                $nonpopuplink = $rating->get_view_ratings_url();
                $popuplink = $rating->get_view_ratings_url(true);

                $action = new popup_action('click', $popuplink, 'ratings',
                        array('height' => 400, 'width' => 600));
                $ratinghtml .= $this->action_link($nonpopuplink, $aggregatehtml, $action);
            } else {
                $ratinghtml .= $aggregatehtml;
            }
        }

        $formstart = null;
        // if the item doesn't belong to the current user, the user has permission to rate
        // and we're within the assessable period
        if ($rating->user_can_rate()) {

            $rateurl = $rating->get_rate_url();
            // $inputs = $rateurl->params();

            // start the rating form
            $formattrs = array('id' => "postrating{$rating->itemid}", 'class' => 'postratingform',
                'method' => 'post', 'action' => $rateurl->out_omit_querystring());
            $formstart .= html_writer::start_tag('div', array('class' => 'ratingform'));

            // add the hidden inputs
            /*
             * foreach ($inputs as $name => $value) { $attributes = array('type' => 'hidden', 'class' => 'ratinginput', 'name' => $name, 'value' =>
             * $value); $formstart .= html_writer::empty_tag('input', $attributes); }
             */

            if (empty($ratinghtml)) {
                $ratinghtml .= $strrate . ': ';
            }
            $ratinghtml = $formstart . $ratinghtml;

            $scalearray = array(RATING_UNSET_RATING => $strrate . '...') + $rating->settings->scale->scaleitems;
            $scaleattrs = array('class' => 'postratingmenu ratinginput',
                'id' => 'menurating' . $rating->itemid);
            $ratinghtml .= html_writer::label($rating->rating, 'menurating' . $rating->itemid,
                    false, array('class' => 'accesshide'));
            $ratinghtml .= html_writer::select($scalearray, 'rating' . $rating->itemid,
                    $rating->rating, false, $scaleattrs);

            if (!$rating->settings->scale->isnumeric) {
                // If a global scale, try to find current course ID from the context
                if (empty($rating->settings->scale->courseid) and
                         $coursecontext = $rating->context->get_course_context(false)) {
                    $courseid = $coursecontext->instanceid;
                } else {
                    $courseid = $rating->settings->scale->courseid;
                }
                $ratinghtml .= $this->help_icon_scale($courseid, $rating->settings->scale);
            }
            $ratinghtml .= html_writer::end_tag('div');
        }

        return $ratinghtml;
    }
}
