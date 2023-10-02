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
 * This file contains the definition for the renderable classes for the booking instance
 *
 * @package   mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\output;

use context_module;
use html_writer;
use mod_booking\booking;
use mod_booking\booking_bookit;
use mod_booking\booking_context_helper;
use mod_booking\option\dates_handler;
use mod_booking\price;
use mod_booking\singleton_service;
use moodle_url;
use renderer_base;
use renderable;
use stdClass;
use templatable;

/**
 * This class prepares data for displaying a booking option instance
 *
 * @package mod_booking
 * @copyright 2021 Georg Maißer {@link http://www.wunderbyte.at}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoption_description implements renderable, templatable {

    /** @var string $identifier unique identifier of the booking option */
    private $identifier = null;

    /** @var string $title the title (column text) as it is saved in db */
    private $title = null;

    /** @var string $titleprefix prefix to be shown before title */
    private $titleprefix = null;

    /** @var int $modalcounter */
    private $modalcounter = null;

    /** @var bool $invisible is the booking option invisible to normal users? */
    private $invisible = null;

    /** @var string $annotation internal annotation */
    private $annotation = null;

    /** @var int $userid */
    private $userid = null;

    /** @var string $description from DB */
    private $description = null;

    /** @var string $statusdescription depending on booking status */
    private $statusdescription = null;

    /** @var string $imageurl URL of an uploaded image for the option */
    private $imageurl = null;

    /** @var string $location as saved in db */
    private $location = null;

    /** @var string $address as saved in db */
    private $address = null;

    /** @var string $institution as saved in db */
    private $institution = null;

    /** @var string $duration is saved in db as seconds and will be formatted in this class */
    private $duration = null;

    /** @var string $booknowbutton as saved in db in minutes */
    private $booknowbutton = null;

    /** @var array $dates as saved in db in minutes */
    private $dates = [];

    /** @var array $teachers by names */
    private $teachers = [];

    /** @var float $price */
    private $price = null;

    /** @var float $priceformulaadd */
    private $priceformulaadd = null;

    /** @var float $priceformulamultiply */
    private $priceformulamultiply = null;

    /** @var string $currency */
    private $currency = null;

    /** @var string $pricecategoryname */
    private $pricecategoryname = null;

    /** @var string $dayofweektime */
    private $dayofweektime = null;

    /** @var array $customfields */
    private $customfields = [];

    /** @var array $bookinginformation */
    private $bookinginformation = [];

    /** @var stdClass $usertobuyfor */
    private $usertobuyfor = null;

    /** @var string $bookitsection */
    private $bookitsection = null;

    /** @var string $unitstring */
    private $unitstring = null;

    /** @var bool $showmanageresponses */
    private $showmanageresponses = null;

    /** @var string $manageresponsesurl */
    private $manageresponsesurl = null;

    /** @var string $responsiblecontact */
    private $responsiblecontact = null;

    /**
     * Constructor.
     * @param int $optionid
     * @param null $bookingevent
     * @param int $descriptionparam
     * @param bool $withcustomfields
     */
    public function __construct(
            int $optionid,
            $bookingevent = null,
            int $descriptionparam = DESCRIPTION_WEBSITE, // Default.
            bool $withcustomfields = true,
            bool $forbookeduser = null,
            object $user = null) {

        global $CFG, $PAGE, $USER;

        // Booking answers class uses caching.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;
        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        if ($user === null) {
            $user = $USER;
        }

        /* We need the possibility to render for other users,
        so the user status of the current USER is not enough.
        But we use it if nothing else is specified. */
        if ($forbookeduser === null) {
            if ($bookinganswers->user_status($user->id) == STATUSPARAM_BOOKED) {
                $forbookeduser = true;
            } else {
                $forbookeduser = false;
            }
        }

        // These fields can be gathered directly from settings.
        $this->title = $settings->text;

        // Prefix to be shown before title.
        $this->titleprefix = $settings->titleprefix;

        $this->identifier = $settings->identifier;

        if (!empty($settings->imageurl)) {
            $this->imageurl = $settings->imageurl;
        }

        // Is this an invisible option?
        $this->invisible = $settings->invisible;

        $this->userid = $user->id;

        // If there is an entity, we show it instead of the location field.
        if (!empty($settings->entity)) {
            $entityurl = new moodle_url('/local/entities/view.php', ['id' => $settings->entity['id']]);
            $entityfullname = $settings->entity['name'];
            if (!empty($settings->entity['shortname'])) {
                $entityfullname .= " (" . $settings->entity['shortname'] . ")";
            }
            $this->location = html_writer::tag('a', $entityfullname, ['href' => $entityurl->out(false)]);
        } else {
            $this->location = $settings->location;
        }
        $this->address = $settings->address;
        $this->institution = $settings->institution;

        // There can be more than one modal, therefore we use the id of this record.
        $this->modalcounter = $settings->id;

        // Format the duration correctly.
        $seconds = $settings->duration;
        $minutes = $seconds / 60;
        $d = floor ($minutes / 1440);
        $h = floor (($minutes - $d * 1440) / 60);
        $m = $minutes - ($d * 1440) - ($h * 60);
        $this->duration = "{$d} " . get_string("days") . "  {$h} " . get_string("hours") . "  {$m} " . get_string("minutes");

        // Datestring for date series and calculation of educational unit length.
        $this->dayofweektime = $settings->dayofweektime;

        // Set the number of educational units (calculated with dayofweektime string).
        if (!empty($settings->dayofweektime)) {
            $this->unitstring = dates_handler::calculate_and_render_educational_units($settings->dayofweektime);
        }

        // We got the array of all the booking information.
        $fullbookinginformation = $bookinganswers->return_all_booking_information($user->id);
        // We need to pop out the first value which is by itself another array containing the information we need.
        $this->bookinginformation = array_pop($fullbookinginformation);

        $context = context_module::instance($cmid);
        if (has_capability('mod/booking:updatebooking', $context) ||
             has_capability('mod/booking:addeditownoption', $context)) {
            $this->showmanageresponses = true;

            // Add a link to redirect to the booking option.
            $link = new moodle_url($CFG->wwwroot . '/mod/booking/report.php', [
                'id' => $cmid,
                'optionid' => $optionid,
            ]);
            // Use html_entity_decode to convert "&amp;" to a simple "&" character.
            if ($CFG->version >= 2023042400) {
                // Moodle 4.2 needs second param.
                $this->manageresponsesurl = html_entity_decode($link->out(), ENT_QUOTES);
            } else {
                // Moodle 4.1 and older.
                $this->manageresponsesurl = html_entity_decode($link->out(), ENT_COMPAT);
            }
        }

        // We need this to render a link to manage bookings in the template.
        if (!empty($this->showmanageresponses) && $this->showmanageresponses == true) {
            if (is_array($this->bookinginformation)) {
                $this->bookinginformation['showmanageresponses'] = true;
                $this->bookinginformation['manageresponsesurl'] = $this->manageresponsesurl;
            }
        }

        // With shortcodes & webservice we might not have a valid context object.
        booking_context_helper::fix_booking_page_context($PAGE, $cmid);

        // Description from booking option settings formatted as HTML.
        $this->description = format_text($settings->description, FORMAT_HTML);

        // Do the same for internal annotation.
        $this->annotation = format_text($settings->annotation, FORMAT_HTML);

        // Currently, this will only get the description for the current user.
        $this->statusdescription = $bookingoption->get_text_depending_on_status($bookinganswers);

        // Every date will be an array of datestring and customfields.
        // But customfields will only be shown if we show booking option information inline.

        $this->dates = $bookingoption->return_array_of_sessions($bookingevent,
                $descriptionparam, $withcustomfields, $forbookeduser);

        $col_teachers = new col_teacher($optionid, $settings);
        print_r($col_teachers->teachers);
        $this->teachers = $col_teachers->teachers;

        $responsiblecontact = $settings->responsiblecontact;

        if(!empty($responsiblecontact)) {
            $this->responsiblecontact = $responsiblecontact;
        }

        if (isset($settings->customfields)) {
            $this->customfields = $settings->customfields;
        }

        // Add price.
        // TODO: Currently this will only use the logged in $USER, this won't work for the cashier use case!
        $priceitem = price::get_price('option', $optionid, $user);
        if (!empty($priceitem)) {
            if (isset($priceitem['price'])) {
                $this->price = $priceitem['price'];
            }
            if (isset($priceitem['currency'])) {
                $this->currency = $priceitem['currency'];
            }
            if (isset($priceitem['pricecategoryname'])) {
                $this->pricecategoryname = $priceitem['pricecategoryname'];
            }
        }

        // Absolute value to be added to price calculation with formula.
        $this->priceformulaadd = $settings->priceformulaadd;

        // Manual factor to be applied to price calculation with formula.
        $this->priceformulamultiply = $settings->priceformulamultiply;

        $baseurl = $CFG->wwwroot;
        $moodleurl = new \moodle_url($baseurl . '/mod/booking/view.php', [
            'id' => $cmid,
            'optionid' => $settings->id,
            'whichview' => 'showonlyone',
        ]);

        switch ($descriptionparam) {
            case DESCRIPTION_WEBSITE:
                if ($forbookeduser) {
                    // If it is for booked user, we show a short info text that the option is already booked.
                    $this->booknowbutton = get_string('infoalreadybooked', 'booking');
                } else if ($bookinganswers->user_status($user->id) == STATUSPARAM_WAITINGLIST) {
                    // If onwaitinglist is 1, we show a short info text that the user is on the waiting list.
                    // Currently this is only working for the current USER.
                    $this->booknowbutton = get_string('infowaitinglist', 'booking');
                }
                break;

            case DESCRIPTION_CALENDAR:
                $encodedlink = booking::encode_moodle_url($moodleurl);
                $this->booknowbutton = "<a href=$encodedlink class='btn btn-primary'>"
                        . get_string('gotobookingoption', 'booking')
                        . "</a>";
                // TODO: We would need an event tracking status changes between notbooked, iambooked and onwaitinglist...
                // TODO: ...in order to update the event table accordingly.
                break;

            case DESCRIPTION_ICAL:
                $this->booknowbutton = get_string('gotobookingoption', 'booking') . ': '
                    .  $moodleurl->out(false);
                break;

            case DESCRIPTION_MAIL:
                // The link should be clickable in mails (placeholder {bookingdetails}).
                $this->booknowbutton = get_string('gotobookingoption', 'booking') . ': ' .
                    '<a href = "' . $moodleurl . '" target = "_blank">' .
                        $moodleurl->out(false) .
                    '</a>';
                break;

            case DESCRIPTION_OPTIONVIEW:
                // Get the availability information for this booking option.
                // boinfo contains availability information, description, visibility information etc.

                // We set usertobuyfor here for better performance.
                $this->usertobuyfor = price::return_user_to_buy_for();

                $this->bookitsection = booking_bookit::render_bookit_button($settings, $this->usertobuyfor->id);

                break;
        }
    }

    /**
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        return $this->get_returnarray();
    }

    /**
     * Helper function to get returnarray.
     * @return array
     */
    public function get_returnarray(): array {
        $returnarray = [
            'title' => $this->title,
            'titleprefix' => $this->titleprefix,
            'invisible' => $this->invisible,
            'annotation' => $this->annotation,
            'identifier' => $this->identifier,
            'modalcounter' => $this->modalcounter,
            'userid' => $this->userid,
            'description' => $this->description,
            'statusdescription' => $this->statusdescription,
            'imageurl' => $this->imageurl,
            'location' => $this->location,
            'address' => $this->address,
            'institution' => $this->institution,
            'duration' => $this->duration,
            'dates' => $this->dates,
            'booknowbutton' => $this->booknowbutton,
            'teachers' => $this->teachers,
            'responsiblecontact' => $this->responsiblecontact,
            'price' => $this->price,
            'priceformulaadd' => $this->priceformulaadd,
            'priceformulamultiply' => $this->priceformulamultiply,
            'currency' => $this->currency,
            'pricecategoryname' => $this->pricecategoryname,
            'dayofweektime' => $this->dayofweektime,
            'bookinginformation' => $this->bookinginformation,
            'bookitsection' => $this->bookitsection,
        ];

        if (!empty($this->unitstring)) {
            $returnarray['unitstring'] = $this->unitstring;
        }

        // We return all the customfields of the option.
        // But we make sure, the shortname of a customfield does not conflict with an existing key.
        if ($this->customfields) {
            foreach ($this->customfields as $key => $value) {
                if (!isset($returnarray[$key])) {
                    $returnarray[$key] = is_array($value) ? reset($value) : $value;
                }
            }
        }

        // In events we don't have the possibility, as on the website, to use display: none the same way.
        // So we need two helper variables.
        if (count($this->dates) > 0) {
            $returnarray['showdateslabel'] = 1;
        }
        if (count($this->teachers) > 0) {
            $returnarray['showteacherslabel'] = 1;
        }

        return $returnarray;
    }

    /**
     * Is the option invisible?
     * @return bool true if invisible, else false
     */
    public function is_invisible(): bool {
        if (isset($this->invisible) && $this->invisible == 1) {
            $ret = true;
        } else {
            $ret = false;
        }
        return $ret;
    }
}
