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
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner, Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use ErrorException;
use Exception;
use mod_booking;
use mod_booking\booking;
use mod_booking\output\instance_description;
use mod_booking\output\bookingoption_description;
use mod_booking\output\business_card;
use mod_booking\output\bookingoption_changes;
use mod_booking\output\signin_downloadform;
use mod_booking\output\report_edit_bookingnotes;
use tabobject;
use html_writer;
use plugin_renderer_base;
use moodle_url;
use Throwable;
use user_selector_base;
use html_table_cell;
use html_table;
use html_table_row;
use rating;
use rating_manager;
use popup_action;
use stdClass;
use templatable;

/**
 * A custom renderer class that extends the plugin_renderer_base and is used by the booking module.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner, Andraž Prinčič
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {
    /**
     * This method is used to generate HTML for a subscriber selection form that uses two user_selector controls
     *
     * @param user_selector_base $existinguc
     * @param user_selector_base $potentialuc
     * @param int $courseid
     * @return string
     */
    public function subscriber_selection_form(
        user_selector_base $existinguc,
        user_selector_base $potentialuc,
        $courseid
    ) {
        $output = '';
        $formattributes = [];
        $formattributes['id'] = 'subscriberform';
        $formattributes['action'] = '';
        $formattributes['method'] = 'post';
        $output .= html_writer::start_tag('form', $formattributes);
        $output .= html_writer::empty_tag(
            'input',
            ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]
        );

        $existingcell = new html_table_cell();
        $existingcell->text = $existinguc->display(true);
        $existingcell->attributes['class'] = 'existing';
        $actioncell = new html_table_cell();
        $actioncell->text = html_writer::start_tag('div', []);
        $actioncell->text .= html_writer::empty_tag(
            'input',
            ['type' => 'submit', 'name' => 'subscribe',
                    'value' => $this->page->theme->larrow . ' ' . get_string('add'),
                    'class' => 'actionbutton',
            ]
        );
        $actioncell->text .= html_writer::empty_tag('br', []);
        $actioncell->text .= html_writer::empty_tag(
            'input',
            ['type' => 'submit', 'name' => 'unsubscribe',
                    'value' => $this->page->theme->rarrow . ' ' . get_string('remove'),
                    'class' => 'actionbutton',
            ]
        );
        $actioncell->text .= html_writer::end_tag('div');
        $actioncell->attributes['class'] = 'actions';
        $potentialcell = new html_table_cell();
        $potentialcell->text = $potentialuc->display(true);
        $potentialcell->attributes['class'] = 'potential';

        $table = new html_table();
        $table->attributes['class'] = 'subscribertable boxaligncenter';
        $table->data = [new html_table_row([$existingcell, $actioncell, $potentialcell])];
        $output .= html_writer::table($table);

        $output .= html_writer::end_tag('form');
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
        $items = [];

        foreach ($userbookings as $userid => $options) {
            $items = [];

            foreach ($options as $optionid => $user) {
                // If the user is visible in only one booking instance, than show the user otherwise do not show.
                if ($user->bookingvisible) {
                    // Waitinglist or regular.
                    if ($user->waitinglist == 0) {
                        $bookingstatus = get_string('booked', 'mod_booking');
                    } else {
                        $bookingstatus = get_string('onwaitinglist', 'mod_booking');
                    }

                    $bookinginstanceurl = new moodle_url(
                        '/mod/booking/view.php',
                        ['id' => $user->cmid]
                    );
                    $bookingcourseurl = new moodle_url(
                        '/course/view.php',
                        ['id' => $user->courseid]
                    );
                    $bookinglink = html_writer::link(
                        $bookinginstanceurl,
                        $user->bookingtitle
                    );
                    $courselink = html_writer::link(
                        $bookingcourseurl,
                        $user->coursename
                    );
                    $html = html_writer::span(
                        $user->bookingoptionname .
                        " $bookinglink.  $courselink $bookingstatus"
                    );
                    $items[] = $html;
                }
            }
            if (!empty($items)) {
                $user = reset($options);
                $content = $this->output->user_picture($user) . " " . fullname($user) . " ";
                $content .= html_writer::link('mailto:' . $user->email, $user->email);
                $output .= html_writer::tag('h3', $content);
                $output .= html_writer::start_tag('div', ['class' => 'list-group']);
                foreach ($items as $item) {
                    $output .= html_writer::tag('div', $item, ['class' => 'list-group-item']);
                }
                $output .= html_writer::end_tag('div');
                $output .= html_writer::empty_tag('br');
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

        if ($rating->settings->aggregationmethod == RATING_AGGREGATE_NONE) {
            return null; // Ratings are turned off.
        }

        $ratingmanager = new rating_manager();

        $strrate = get_string("rate", "rating");
        $ratinghtml = ''; // The string we'll return.

        // Permissions check - can they view the aggregate?
        if ($rating->user_can_view_aggregate()) {
            $aggregatelabel = $ratingmanager->get_aggregate_label(
                $rating->settings->aggregationmethod
            );
            $aggregatestr = $rating->get_aggregate_string();

            $aggregatehtml = html_writer::tag(
                'span',
                $aggregatestr,
                ['id' => 'ratingaggregate' . $rating->itemid, 'class' => 'ratingaggregate']
            ) .
                     ' ';
            if ($rating->count > 0) {
                $countstr = "({$rating->count})";
            } else {
                $countstr = '-';
            }
            $aggregatehtml .= html_writer::tag(
                'span',
                $countstr,
                ['id' => "ratingcount{$rating->itemid}", 'class' => 'ratingcount']
            ) . ' ';

            $ratinghtml .= html_writer::tag(
                'span',
                $aggregatelabel,
                ['class' => 'rating-aggregate-label']
            );
            if (
                $rating->settings->permissions->viewall &&
                     $rating->settings->pluginpermissions->viewall
            ) {
                $nonpopuplink = $rating->get_view_ratings_url();
                $popuplink = $rating->get_view_ratings_url(true);

                $action = new popup_action(
                    'click',
                    $popuplink,
                    'ratings',
                    ['height' => 400, 'width' => 600]
                );
                $ratinghtml .= $this->action_link($nonpopuplink, $aggregatehtml, $action);
            } else {
                $ratinghtml .= $aggregatehtml;
            }
        }

        $formstart = null;
        // If the item doesn't belong to the current user, the user has permission to rate
        // and we're within the assessable period.
        if ($rating->user_can_rate()) {
            $rateurl = $rating->get_rate_url();

            // Start the rating form.
            $formstart .= html_writer::start_tag('div', ['class' => 'ratingform']);

            if (empty($ratinghtml)) {
                $ratinghtml .= $strrate . ': ';
            }
            $ratinghtml = $formstart . $ratinghtml;

            $scalearray = [RATING_UNSET_RATING => $strrate . '...'] + $rating->settings->scale->scaleitems;
            $scaleattrs = ['class' => 'postratingmenu ratinginput', 'id' => 'menurating' . $rating->itemid];
            $ratinghtml .= html_writer::label(
                $rating->rating,
                'menurating' . $rating->itemid,
                false,
                ['class' => 'accesshide']
            );
            $ratinghtml .= html_writer::select(
                $scalearray,
                'rating' . $rating->itemid,
                $rating->rating,
                false,
                $scaleattrs
            );

            if (!$rating->settings->scale->isnumeric) {
                // If a global scale, try to find current course ID from the context.
                /** @var \context $ratingcontext */
                $ratingcontext = $rating->context;
                if (
                    empty($rating->settings->scale->courseid) && !empty($ratingcontext) &&
                         $coursecontext = $ratingcontext->get_course_context(false)
                ) {
                    $courseid = $coursecontext->instanceid;
                } else {
                    $courseid = $rating->settings->scale->courseid;
                }
                $ratinghtml .= $this->output->help_icon_scale($courseid, $rating->settings->scale);
            }
            $ratinghtml .= html_writer::end_tag('div');
        }

        return $ratinghtml;
    }

    /**
     * Display signinsheet pdf download form
     *
     * @param signin_downloadform $data
     * @return string rendered html
     */
    public function render_signin_pdfdownloadform(signin_downloadform $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/signin_downloadform', $data);
        return $o;
    }

    /**
     * Render the template for editing booking notes on the report page
     *
     * @param report_edit_bookingnotes $data
     * @return string rendered html
     */
    public function render_report_edit_bookingnotes(report_edit_bookingnotes $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/edit_bookingnotes', $data);
        return $o;
    }


    /**
     * Function to print user picture plus text as html
     * @param business_card $data
     * @return string
     */
    public function render_business_card(business_card $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/business_card', $data);
        return $o;
    }

    /**
     * Function to print instance description
     * @param instance_description $data
     * @return string
     */
    public function render_instance_description(instance_description $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/instance_description', $data);
        return $o;
    }

    /**
     * Function to print booking option description.
     * @param bookingoption_description $data
     * @return string
     */
    public function render_bookingoption_description(bookingoption_description $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/bookingoption_description', $data);
        return $o;
    }

    /**
     * Function to print booking option description for event.
     * @param bookingoption_description $data
     * @return string
     */
    public function render_bookingoption_description_event(bookingoption_description $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/bookingoption_description_event', $data);
        return $o;
    }

    /**
     * Function to print booking option description for ical.
     * @param bookingoption_description $data
     * @return string
     */
    public function render_bookingoption_description_ical(bookingoption_description $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/bookingoption_description_ical', $data);
        return $o;
    }

    /**
     * Function to print booking option description for mail placeholder {bookingdetails}.
     * @param bookingoption_description $data
     * @return string
     */
    public function render_bookingoption_description_mail(bookingoption_description $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/bookingoption_description_mail', $data);
        return $o;
    }

    /**
     * Function to print booking option description for mail placeholder {bookingdetails}.
     * @param bookingoption_description $data
     * @return string
     */
    public function render_bookingoption_description_cartitem(bookingoption_description $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/bookingoption_description_cartitem', $data);
        return $o;
    }

    /**
     * Function to print list of teachers for mail placeholder {teachers}.
     *
     * @param array $data
     * @return string
     */
    public function render_bookingoption_description_teachers(array $data) {
        $o = $this->render_from_template('mod_booking/bookingoption_description_teachers', $data);
        return $o;
    }

    /**
     * Function to print list of option dates for mail placeholder {dates}.
     *
     * @param bookingoption_description $data
     * @return string
     */
    public function render_bookingoption_description_dates(bookingoption_description $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/bookingoption_description_dates', $data);
        return $o;
    }

    /**
     * Function to print booking option single view on optionview.php
     * @param bookingoption_description $data
     * @return string
     */
    public function render_bookingoption_description_view(bookingoption_description $data) {
        $o = '';
        $data = $data->export_for_template($this);
        try {
            $o .= $this->render_from_template('mod_booking/bookingoption_description_view', $data);
        } catch (Exception $e) {
            $o .= get_string('bookingoptionupdated', 'mod_booking');
        }
        return $o;
    }

    /**
     * Function to print booking option changes ("What has changed?").
     * @param bookingoption_changes $data
     * @return string
     */
    public function render_bookingoption_changes(bookingoption_changes $data) {
        $o = '';
        $data = $data->export_for_template($this);

        try {
            // This line might cause an error or exception.
            $o .= $this->render_from_template('mod_booking/bookingoption_changes', $data);
        } catch (Throwable $e) {
            $o = '';
        };

        return $o;
    }

    /**
     * Render function.
     * @param object $data
     * @return string
     */
    public function render_coursepage_shortinfo_and_button($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/coursepage_shortinfo_and_button', $data);
        return $o;
    }

    /**
     * Render function.
     * @param object $data
     * @return string
     */
    public function render_col_coursestarttime($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/col_coursestarttime', $data);
        return $o;
    }

    /**
     * Render function.
     * @param object $data
     * @return string
     */
    public function render_col_text_with_description($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/col_text_with_description', $data);
        return $o;
    }

    /**
     * Render function to render a simple string of optiondates separated by ", ".
     * @param object $data
     * @return string
     */
    public function render_optiondates_only($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/optiondates_only', $data);
        return $o;
    }

    /**
     * Render function to render a simple string of optiondates separated by ", ".
     * @param object $data
     * @return string
     */
    public function render_optiondates_for_placeholder($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/optiondates_for_placeholder', $data);
        return $o;
    }

    /**
     * Render function to render a simple string of optiondates separated by ", ".
     * @param object $data
     * @return string
     */
    public function render_optiondates_with_entities($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/optiondates_with_entities', $data);
        return $o;
    }

    /**
     * Render a bookingoptions_wbtable using wunderbyte_table plugin.
     *
     * @param templatable $bookingoptionswbtable
     * @return string|bool
     */
    public function render_bookingoptions_wbtable(templatable $bookingoptionswbtable) {
        $data = $bookingoptionswbtable->export_for_template($this);
        return $this->render_from_template('mod_booking/shortcodes_table', $data);
    }

    /**
     * Render output for text column.
     * @param object $data
     * @return string
     */
    public function render_col_text($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/col_text', $data);
        return $o;
    }

    /**
     * Render output for teacher column.
     * @param object $data
     * @return string
     */
    public function render_col_teacher($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/col_teacher', $data);
        return $o;
    }

    /**
     * Render output for price column.
     * @param object $data
     * @return string
     */
    public function render_col_price($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/col_price', $data);
        return $o;
    }

    /**
     * Render output for action column.
     * @param object $data
     * @return string
     */
    public function render_col_action($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/col_action', $data);
        return $o;
    }

    /**
     * Render output for action column.
     * @param object $data
     * @return string
     */
    public function render_col_availableplaces($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/col_availableplaces', $data);
        return $o;
    }

    /**
     * Render bookingoption dates.
     * @param object $data
     * @return string
     */
    public function render_bookingoption_dates($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/bookingoption_dates', $data);
        return $o;
    }

    /**
     * Render semesters and holidays form.
     * @param object $data
     * @return string
     */
    public function render_semesters_holidays($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/semesters_holidays', $data);
        return $o;
    }

    /**
     * Render pricecategories form.
     * @param object $data
     * @return string
     */
    public function render_pricecategories($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/pricecategories', $data);
        return $o;
    }

    /**
     * Render notifyme button.
     * @param object $data
     * @return string
     */
    public function render_notifyme_button($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/button_notifyme', $data);
        return $o;
    }

    /**
     * Render ruleslist
     * @param object $data
     * @return string
     */
    public function render_ruleslist($data) {
        $data = $data->export_for_template($this);
        return $this->render_from_template('mod_booking/ruleslist', $data);
    }

    /**
     * Render campaignslist
     * @param object $data
     * @return string
     */
    public function render_campaignslist($data) {
        $data = $data->export_for_template($this);
        return $this->render_from_template('mod_booking/campaignslist', $data);
    }

    /**
     * Render output for booked users.
     * @param object $data
     * @return string
     */
    public function render_booked_users($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/booked_users', $data);
        return $o;
    }

    /**
     * Render subbookings list
     * @param object $data
     * @return string
     */
    public function render_subbookingslist($data) {
        $data = $data->export_for_template($this);
        return $this->render_from_template('mod_booking/subbookingslist', $data);
    }

    /**
     * Render booking actions list
     * @param object $data
     * @return string
     */
    public function render_boactionslist($data) {
        $data = $data->export_for_template($this);
        return $this->render_from_template('mod_booking/bookingactions/boactionslist', $data);
    }

    /**
     * Render subbookings pre page modal.
     * @param object $data
     * @return string
     */
    public function render_prepagemodal($data) {
        $data = $data->export_for_template($this);
        return $this->render_from_template('mod_booking/bookingpage/prepagemodal', $data);
    }

    /**
     * Render subbookings pre page inline.
     * @param object $data
     * @return string
     */
    public function render_prepageinline($data) {
        $data = $data->export_for_template($this);
        return $this->render_from_template('mod_booking/bookingpage/prepageinline', $data);
    }

    /**
     * Render subbooking timeslot
     * @param object $data
     * @return string
     */
    public function render_sb_timeslot($data) {
        $data = $data->export_for_template($this);
        return $this->render_from_template('mod_booking/subbooking/timeslottable', $data);
    }

    /**
     * Render output for bookit.
     * @param object $data
     * @return string
     */
    public function render_bookit_price($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/bookit_price', $data);
        return $o;
    }

    /**
     * Render output for bookit button.
     * @param object $data
     * @param string $template
     * @return string
     */
    public function render_bookit_button($data, string $template) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template($template, $data);
        return $o;
    }

    /**
     * Function to render the page of a single teacher.
     *
     * @param object $data
     * @return string
     */
    public function render_teacherpage($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/page_teacher', $data);
        return $o;
    }

    /**
     * Function to render the page showing all teachers.
     *
     * @param object $data
     * @return string
     */
    public function render_allteacherspage($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/page_allteachers', $data);
        return $o;
    }

    /**
     * Render main booking options view.
     * @param object $data
     * @return string
     */
    public function render_view($data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/view', $data);
        return $o;
    }
    /**
     * renders col_responsiblecontacts.
     *
     * @param object $data
     *
     * @return string
     *
     */
    public function render_col_responsiblecontacts(object $data) {
        $o = '';
        $data = $data->export_for_template($this);
        $o .= $this->render_from_template('mod_booking/col_responsiblecontact', $data);
        return $o;
    }
}
