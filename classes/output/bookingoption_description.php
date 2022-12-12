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
use mod_booking\bo_availability\bo_info;
use mod_booking\booking;
use mod_booking\booking_option;
use mod_booking\dates_handler;
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
    public $identifier = null;

    /** @var string $title the title (column text) as it is saved in db */
    public $title = null;

    /** @var string $titleprefix prefix to be shown before title */
    public $titleprefix = null;

    /** @var int $modalcounter */
    public $modalcounter = null;

    /** @var bool $invisible is the booking option invisible to normal users? */
    public $invisible = null;

    /** @var string $annotation internal annotation */
    public $annotation = null;

    /** @var int $userid */
    public $userid = null;

    /** @var string $description from DB */
    public $description = null;

    /** @var string $statusdescription depending on booking status */
    public $statusdescription = null;

    /** @var string $imageurl URL of an uploaded image for the option */
    public $imageurl = null;

    /** @var string $location as saved in db */
    public $location = null;

    /** @var string $address as saved in db */
    public $address = null;

    /** @var string $institution as saved in db */
    public $institution = null;

    /** @var string $duration is saved in db as seconds and will be formatted in this class */
    public $duration = null;

    /** @var string $booknowbutton as saved in db in minutes */
    public $booknowbutton = null;

    /** @var array $dates as saved in db in minutes */
    public $dates = [];

    /** @var array $teachers by names */
    public $teachers = [];

    /** @var array $prices by names */
    public $prices = [];

    /** @var float $price */
    public $price = null;

    /** @var float $priceformulaadd */
    public $priceformulaadd = null;

    /** @var float $priceformulamultiply */
    public $priceformulamultiply = null;

    /** @var string $currency */
    public $currency = null;

    /** @var string $pricecategoryname */
    public $pricecategoryname = null;

    /** @var string $dayofweektime */
    public $dayofweektime = null;

    /** @var array $customfields */
    public $customfields = [];

    /** @var array $bookinginformation */
    public $bookinginformation = [];

    /** @var stdClass $usertobuyfor */
    public $usertobuyfor = null;

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

        // Performance: Last param is set to true so users won't be retrieved from DB.
        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found,moodle.Commenting.InlineComment.NotCapital
        // $bookingoption = new booking_option($booking->cm->id, $optionid, [], 0, 0, true);

        // Booking answers class uses caching.
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $cmid = $settings->cmid;
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
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
        $this->bookinginformation = $bookinganswers->return_all_booking_information($user->id);

        // Description from booking option settings formatted as HTML.
        // When we call this via webservice, we don't have a context, this throws an error.
        // It's no use passing the context object either.
        if (!isset($PAGE->context)) {
            $PAGE->set_context(context_module::instance($cmid));
        }
        $this->description = format_text($settings->description, FORMAT_HTML);

        // Do the same for internal annotation.
        $this->annotation = format_text($settings->annotation, FORMAT_HTML);

        // Currently, this will only get the description for the current user.
        $this->statusdescription = $bookingoption->get_option_text($bookinganswers);

        // Every date will be an array of datestring and customfields.
        // But customfields will only be shown if we show booking option information inline.

        $this->dates = $bookingoption->return_array_of_sessions($bookingevent,
                $descriptionparam, $withcustomfields, $forbookeduser);

        $teachers = $settings->teachers;

        $teachernames = [];
        foreach ($teachers as $teacher) {
            $teachernames[] = "$teacher->firstname $teacher->lastname";
        }
        $this->teachers = $teachernames;

        if (isset($settings->customfields)) {
            $this->customfields = $settings->customfields;
        }

        // Add price.
        // TODO: Currently this will only use the logged in $USER, this won't work for the cashier use case!
        $priceitem = price::get_price('option', $optionid, $user);
        $this->prices = price::get_prices_from_cache_or_db('option',$optionid);

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
        $moodleurl = new \moodle_url($baseurl . '/mod/booking/view.php', array(
            'id' => $cmid,
            'optionid' => $settings->id,
            'action' => 'showonlyone',
            'whichview' => 'showonlyone'
        ));

        switch ($descriptionparam) {
            case DESCRIPTION_WEBSITE:
                // Only show "already booked" or "on waiting list" text in modal.
                if ($bookingsettings->showdescriptionmode == 0) {
                    if ($forbookeduser) {
                        // If it is for booked user, we show a short info text that the option is already booked.
                        $this->booknowbutton = get_string('infoalreadybooked', 'booking');
                    } else if ($bookinganswers->user_status($user->id) == 1) {
                        // If onwaitinglist is 1, we show a short info text that the user is on the waiting list.
                        // Currently this is only working for the current USER.
                        $this->booknowbutton = get_string('infowaitinglist', 'booking');
                    }
                } else {
                    // Inline we don't want to show it because it would be redundant information.
                    $this->booknowbutton = '';
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
                $boinfo = new bo_info($settings);

                // We set usertobuyfor here for better performance.
                $this->usertobuyfor = price::return_user_to_buy_for();

                if (list($conditionid, $isavailable, $description) = $boinfo->get_description(true,
                    $settings, $this->usertobuyfor->id)) {

                    // Values object needed for col_price.
                    $values = new stdClass;
                    $values->id = $settings->id;
                    $values->text = $settings->text;
                    $values->description = $settings->description;

                    // Price blocks normal availability, if it's the only one, we show the cart.
                    if (!$isavailable) {
                        switch ($conditionid) {
                            case BO_COND_ALREADYBOOKED:
                                $this->conditionmessage = bo_info::render_conditionmessage($description, 'success');
                                break;
                            case BO_COND_ISCANCELLED:
                                $this->conditionmessage = bo_info::render_conditionmessage($description, 'danger');
                                break;
                            case BO_COND_ONWAITINGLIST:
                                $this->conditionmessage = bo_info::render_conditionmessage($description, 'warning');
                                break;
                            case BO_COND_FULLYBOOKED:
                                if (get_config('booking', 'usenotificationlist')) {
                                    $this->conditionmessage = bo_info::render_conditionmessage($description, 'warning',
                                        $values->id, false, null, true, $this->usertobuyfor);
                                } else {
                                    $this->conditionmessage = bo_info::render_conditionmessage($description, 'warning');
                                }
                                break;
                        }
                    }
                    // TODO: If no price is set at all, we need to add possibility to book right away without shopping cart!
                }
                break;
        }
    }

    // TODO: Wenn kein Preis gesetzt ist, dann "klassischer" Book now button (Auch in shortcodes einbauen?).

    /**
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {

        $returnarray = array(
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
                'price' => $this->price,
                'prices' => $this->prices,
                'priceformulaadd' => $this->priceformulaadd,
                'priceformulamultiply' => $this->priceformulamultiply,
                'currency' => $this->currency,
                'pricecategoryname' => $this->pricecategoryname,
                'dayofweektime' => $this->dayofweektime,
                'bookinginformation' => $this->bookinginformation
        );

        if (!empty($this->unitstring)) {
            $returnarray['unitstring'] = $this->unitstring;
        }

        if (!empty($this->conditionmessage)) {
            $returnarray['conditionmessage'] = $this->conditionmessage;
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
}
