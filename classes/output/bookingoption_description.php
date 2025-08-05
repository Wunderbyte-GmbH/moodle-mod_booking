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
use context_system;
use core_plugin_manager;
use html_writer;
use local_wunderbyte_table\local\customfield\wbt_field_controller_info;
use mod_booking\booking;
use mod_booking\booking_answers;
use mod_booking\booking_bookit;
use mod_booking\booking_context_helper;
use mod_booking\booking_option;
use mod_booking\local\modechecker;
use mod_booking\option\dates_handler;
use mod_booking\option\fields\competencies;
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
    /** @var int $optionid optionid */
    private $optionid = null;

    /** @var string $identifier unique identifier of the booking option */
    private $identifier = null;

    /** @var string $title the title (column text) as it is saved in db */
    private $title = null;

    /** @var string $titleprefix prefix to be shown before title */
    private $titleprefix = null;

    /** @var int $modalcounter */
    private $modalcounter = null;

    /** @var bool $invisible is the booking option invisible to normal users? */
    public $invisible = null;

    /** @var string $annotation internal annotation */
    private $annotation = null;

    /** @var int $userid */
    private $userid = null;

    /** @var string $description from DB */
    private $description = null;

    /** @var string $statusdescription depending on booking status */
    private $statusdescription = null;

    /** @var string $attachments HTML for attached files as links */
    private $attachments = null;

    /** @var string $imageurl URL of an uploaded image for the option */
    private $imageurl = null;

    /** @var string $editurl URL to edit the option */
    private $editurl = null;

    /** @var string $returnurl URL to edit the option */
    public $returnurl = '';

    /** @var string $location as saved in db */
    private $location = null;

    /** @var string $address as saved in db */
    private $address = null;

    /** @var string $institution as saved in db */
    private $institution = null;

    /** @var string $duration is saved in db as seconds and will be formatted in this class */
    private $duration = null;

    /** @var string $timeremaining */
    private $timeremaining = null;

    /** @var string $booknowbutton as saved in db in minutes */
    private $booknowbutton = null;

    /** @var array $dates as saved in db in minutes */
    private $dates = [];

    /** @var bool $datesexist flag true if dates exist, else null (not false!) */
    private $datesexist = null;

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

    /** @var array $responsiblecontactuser */
    private $responsiblecontactuser = null;

    /** @var string $bookingopeningtime */
    private $bookingopeningtime = '';

    /** @var string $bookingclosingtime */
    private $bookingclosingtime = '';

    /** @var bool $selflearningcourse */
    private $selflearningcourse = null;

    /** @var bool $canstillbecancelled */
    private $canstillbecancelled = null;

    /** @var string $canceluntil */
    private $canceluntil = null;

    /** @var bool $selflearningcourseshowdurationinfo */
    private $selflearningcourseshowdurationinfo = null;

    /** @var bool $selflearningcourseshowdurationinfoexpired */
    private $selflearningcourseshowdurationinfoexpired = null;

    /** @var string $competencies */
    private $competencies = '';

    /** @var string $competencyheader */
    private $competencyheader = '';

    /** @var array $subpluginstemplatedata */
    private $subpluginstemplatedata = [];

    /**
     * Constructor.
     *
     * @param int $optionid
     * @param object|null $bookingevent
     * @param int $descriptionparam
     * @param bool $withcustomfields
     * @param bool|null $forbookeduser
     * @param object|null $user
     * @param bool $ashtml
     *
     */
    public function __construct(
        int $optionid,
        $bookingevent = null,
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE,
        bool $withcustomfields = true,
        ?bool $forbookeduser = null,
        ?object $user = null,
        $ashtml = false
    ) {

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
            if ($bookinganswers->user_status($user->id) == MOD_BOOKING_STATUSPARAM_BOOKED) {
                $forbookeduser = true;
            } else {
                $forbookeduser = false;
            }
        }

        // We store the optionid.
        $this->optionid = $optionid;

        // These fields can be gathered directly from settings.
        $this->title = $settings->get_title_with_prefix();

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

            if (!empty($settings->entity['parentname'])) {
                $nametobeshown = $settings->entity['parentname'] . " (" . $settings->entity['name'] . ")";
            } else {
                $nametobeshown = $settings->entity['name'];
            }
            $this->location = html_writer::tag('a', $nametobeshown, ['href' => $entityurl->out(false)]);
        } else {
            $this->location = $settings->location;
        }

        $this->address = $settings->address;
        $this->institution = $settings->institution;

        // There can be more than one modal, therefore we use the id of this record.
        $this->modalcounter = $settings->id;

        // Check if it's a self-learning course. There's a JSON flag for this.
        if (!empty($settings->selflearningcourse)) {
            $this->selflearningcourse = true;

            if (get_config('booking', 'selflearningcoursehideduration')) {
                $this->selflearningcourseshowdurationinfo = null;
            } else if (!empty($settings->duration)) {
                // We do not show duration info if it is set to 0.
                $this->selflearningcourseshowdurationinfo = true;

                // Format the duration correctly.
                $this->duration = format_time($settings->duration);

                $ba = singleton_service::get_instance_of_booking_answers($settings);
                $buyforuser = price::return_user_to_buy_for();
                if (isset($ba->usersonlist[$buyforuser->id])) {
                    $timebooked = $ba->usersonlist[$buyforuser->id]->timecreated;
                    $timeremainingsec = $timebooked + $settings->duration - time();

                    if ($timeremainingsec <= 0) {
                        $this->selflearningcourseshowdurationinfo = null;
                        $this->selflearningcourseshowdurationinfoexpired = true;
                    } else {
                        $this->timeremaining = format_time($timeremainingsec);
                    }
                }
            }
        }

        // Show info until when the booking option can be cancelled.
        // If cancelling was disabled in the booking option or for the whole instance...
        // ...then we do not show the cancel until info.
        if (
            booking_option::get_value_of_json_by_key($optionid, 'disablecancel')
            || booking::get_value_of_json_by_key($settings->bookingid, 'disablecancel')
        ) {
            $this->canceluntil = null;
        } else {
            // Check if the option has its own canceluntil date.
            $canceluntiltimestamp = booking_option::get_value_of_json_by_key($optionid, 'canceluntil');
            if (!empty($canceluntiltimestamp)) {
                $this->canceluntil = userdate($canceluntiltimestamp, get_string('strftimedatetime', 'langconfig'));
            } else {
                $canceluntiltimestamp = booking_option::return_cancel_until_date($optionid);
                if (!empty($canceluntiltimestamp)) {
                    $this->canceluntil = userdate($canceluntiltimestamp, get_string('strftimedatetime', 'langconfig'));
                }
            }
            if (!empty($canceluntiltimestamp) && ($canceluntiltimestamp > time())) {
                $this->canstillbecancelled = true;
            }
        }

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

        $syscontext = context_system::instance();
        $modcontext = context_module::instance($cmid);
        $isteacher = booking_check_if_teacher($optionid);
        if (
            has_capability('mod/booking:updatebooking', $modcontext)
            || has_capability('mod/booking:updatebooking', $syscontext)
            || has_capability('mod/booking:viewreports', $syscontext)
            || (has_capability('mod/booking:addeditownoption', $modcontext) && $isteacher)
            || (has_capability('mod/booking:addeditownoption', $syscontext) && $isteacher)
        ) {
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
        $this->description = $settings->description;

        // Do the same for internal annotation.
        $this->annotation = $settings->annotation;

        // Currently, this will only get the description for the current user.
        $this->statusdescription = $bookingoption->get_text_depending_on_status($bookinganswers);

        // Attachments.
        $this->attachments = booking_option::render_attachments($optionid, 'optionview-bookingoption-attachments mb-3');

        // Every date will be an array of datestrings, customfields and additional info like entities.
        // Make sure, that optiondates (sessions) are not stored/shown for self-learning courses.
        if (empty($settings->selflearningcourse)) {
            $this->dates = $bookingoption->return_array_of_sessions(
                $bookingevent,
                $descriptionparam,
                $withcustomfields,
                $forbookeduser,
                $ashtml
            );
            if (!empty($this->dates)) {
                $this->datesexist = true;
            }
        }

        $colteacher = new col_teacher($optionid, $settings, true);
        $this->teachers = $colteacher->teachers;

        // Array User object of the responsible contact.
        $responsibles = $settings->responsiblecontactuser;

        // If no responsible contact is set, we take the first teacher.
        if (empty($responsibles) && !empty($settings->teachers)) {
            $teacher = reset($settings->teachers);
            $responsible = new stdClass();
            $responsible->id = $teacher->userid;
            $responsible->firstname = $teacher->firstname ?? '';
            $responsible->lastname = $teacher->lastname ?? '';
            $responsible->email = $teacher->email ?? '';

            $responsible->link = (new moodle_url(
                '/mod/booking/teacher.php',
                ['teacherid' => $teacher->userid]
            ));
            $responsibles = [$responsible];
        }
        if (!empty($responsibles)) {
            foreach ($responsibles as &$responsiblecontact) {
                if (empty($responsiblecontact)) {
                    continue;
                }
                $responsiblecontact->link = (new moodle_url(
                    '/user/profile.php',
                    ['id' => $responsiblecontact->id]
                ));
            }
        } else {
            $responsibles = [];
        }

        // List of responsible contact users.
        $this->responsiblecontactuser = $responsibles;

        if (empty($settings->bookingopeningtime)) {
            $this->bookingopeningtime = null;
        } else {
            $this->bookingopeningtime = userdate($settings->bookingopeningtime, get_string('strftimedatetime', 'langconfig'));
        }

        if (empty($settings->bookingclosingtime)) {
            $this->bookingclosingtime = null;
        } else {
            $this->bookingclosingtime = userdate($settings->bookingclosingtime, get_string('strftimedatetime', 'langconfig'));
        }

        if (isset($settings->customfields)) {
            $this->customfields = $settings->customfields;
        }

        // Add price.
        // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
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

        if (!modechecker::is_ajax_or_webservice_request()) {
            $returnurl = $PAGE->url->out();
        } else {
            $returnurl = '/';
        }

        // The current page is not /mod/booking/optionview.php.
        $moodleurl = new moodle_url("/mod/booking/optionview.php", [
            "optionid" => (int)$settings->id,
            "cmid" => (int)$cmid,
            "userid" => (int)$user->id,
            'returnto' => 'url',
            'returnurl' => $returnurl,
        ]);

        // Set the returnurl to navigate back to after form is saved.
        $viewphpurl = new moodle_url('/mod/booking/view.php', ['id' => $cmid]);
        $returnurl = $viewphpurl->out();

        if (
            has_capability('mod/booking:updatebooking', $modcontext)
            || (has_capability('mod/booking:addeditownoption', $modcontext) && $isteacher)
            || (has_capability('mod/booking:addeditownoption', $syscontext) && $isteacher)
        ) {
            // The current page is not /mod/booking/optionview.php.
            $editurl = new moodle_url("/mod/booking/editoptions.php", [
                "optionid" => (int)$settings->id,
                "id" => (int)$cmid,
                'returnto' => 'url',
                'returnurl' => $returnurl,
            ]);

            $this->editurl = $editurl->out(false);
        }

        switch ($descriptionparam) {
            case MOD_BOOKING_DESCRIPTION_WEBSITE:
                if ($forbookeduser) {
                    // If it is for booked user, we show a short info text that the option is already booked.
                    $this->booknowbutton = get_string('infoalreadybooked', 'booking');
                } else if ($bookinganswers->user_status($user->id) == MOD_BOOKING_STATUSPARAM_WAITINGLIST) {
                    // If onwaitinglist is 1, we show a short info text that the user is on the waiting list.
                    // Currently this is only working for the current USER.
                    $this->booknowbutton = get_string('infowaitinglist', 'booking');
                }
                // If competencies are active, we return a list here.
                $this->competencies = competencies::get_list_of_similar_options(
                    $bookingoption->settings->competencies ?? "",
                    $bookingoption
                );
                break;

            case MOD_BOOKING_DESCRIPTION_CALENDAR:
                $encodedlink = booking::encode_moodle_url($moodleurl);
                $this->booknowbutton = "<a href=$encodedlink class='btn btn-primary'>"
                        . get_string('gotobookingoption', 'booking')
                        . "</a>";
                // phpcs:ignore moodle.Commenting.TodoComment.MissingInfoInline
                /* TODO: We would need an event tracking status changes between notbooked, iambooked and onwaitinglist...
                TODO: ...in order to update the event table accordingly. */
                break;

            case MOD_BOOKING_DESCRIPTION_ICAL:
                $this->booknowbutton = get_string('gotobookingoption', 'booking') . ': '
                    .  $moodleurl->out(false);
                break;

            case MOD_BOOKING_DESCRIPTION_MAIL:
                // The link should be clickable in mails (placeholder {bookingdetails}).
                $this->booknowbutton = get_string('gotobookingoption', 'booking') . ': ' .
                    '<a href = "' . $moodleurl . '" target = "_blank">' .
                        get_string('gotobookingoptionlink', 'booking', $moodleurl->out(false)) .
                    '</a>';
                break;

            case MOD_BOOKING_DESCRIPTION_OPTIONVIEW:
                // Get the availability information for this booking option.

                // Add availability info texts to $bookinginformation.
                booking_answers::add_availability_info_texts_to_booking_information($this->bookinginformation);

                // We set usertobuyfor here for better performance.
                $this->usertobuyfor = price::return_user_to_buy_for();

                $this->bookitsection = booking_bookit::render_bookit_button($settings, $this->usertobuyfor->id);

                // If competencies are active, we return a list here.
                $this->competencies = competencies::get_list_of_similar_options(
                    $bookingoption->settings->competencies ?? "",
                    $bookingoption
                );
                if (!empty($this->competencies)) {
                    $this->competencyheader = get_string('showsimilaroptions', 'mod_booking');
                }

                break;
        }
        foreach (core_plugin_manager::instance()->get_plugins_of_type('bookingextension') as $plugin) {
            $class = "\\bookingextension_{$plugin->name}\\{$plugin->name}";
            if (!class_exists($class)) {
                continue; // Skip if the class does not exist.
            }
            $sublplugindata = $class::set_template_data_for_optionview($settings);
            if (!empty($sublplugindata)) {
                foreach ($sublplugindata as $data) {
                    $this->subpluginstemplatedata[] = $data;
                }
            }
        }
    }

    /**
     * Export for template.
     *
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
            'title' => format_string($this->title),
            'titleprefix' => $this->titleprefix,
            'invisible' => $this->invisible,
            'annotation' => format_text($this->annotation),
            'identifier' => $this->identifier,
            'modalcounter' => $this->modalcounter,
            'userid' => $this->userid,
            'description' => format_text($this->description),
            'attachments' => $this->attachments,
            'statusdescription' => $this->statusdescription,
            'imageurl' => $this->imageurl,
            'location' => $this->location,
            'address' => $this->address,
            'institution' => $this->institution,
            'selflearningcourse' => $this->selflearningcourse,
            'selflearningcourseshowdurationinfo' => $this->selflearningcourseshowdurationinfo,
            'selflearningcourseshowdurationinfoexpired' => $this->selflearningcourseshowdurationinfoexpired,
            'duration' => $this->duration,
            'dates' => $this->dates,
            'datesexist' => $this->datesexist,
            'booknowbutton' => $this->booknowbutton,
            'teachers' => $this->teachers,
            'responsiblecontactuser' => $this->responsiblecontactuser,
            'price' => $this->price,
            'priceformulaadd' => $this->priceformulaadd,
            'priceformulamultiply' => $this->priceformulamultiply,
            'currency' => $this->currency,
            'pricecategoryname' => format_string($this->pricecategoryname),
            'dayofweektime' => $this->dayofweektime,
            'bookinginformation' => $this->bookinginformation,
            'bookitsection' => $this->bookitsection,
            'bookingopeningtime' => $this->bookingopeningtime,
            'bookingclosingtime' => $this->bookingclosingtime,
            'editurl' => !empty($this->editurl) ? $this->editurl : false,
            'returnurl' => !empty($this->returnurl) ? $this->returnurl : false,
            'canceluntil' => $this->canceluntil,
            'canstillbecancelled' => $this->canstillbecancelled,
            'competencies' => $this->competencies,
            'competencyheader' => $this->competencyheader,
            'subpluginstemplatedata' => $this->subpluginstemplatedata,
        ];

        if (!empty($this->timeremaining)) {
            $returnarray['timeremaining'] = $this->timeremaining;
        }

        if (!empty($this->unitstring)) {
            $returnarray['unitstring'] = $this->unitstring;
        }

        // We return all the customfields of the option.
        // But we make sure, the shortname of a customfield does not conflict with an existing key.
        if ($this->customfields) {
            foreach ($this->customfields as $key => $value) {
                if (!isset($returnarray[$key])) {
                    // Make sure, print value for arrays will be converted to string.
                    $printvalue = is_array($value) ? implode(',', $value) : $value;

                    // Get the correct field controller from Wunderbyte table.
                    $fieldcontroller = wbt_field_controller_info::get_instance_by_shortname($key);

                    // Get the option value from field controller.
                    $returnarray[$key] = $fieldcontroller->get_option_value_by_key($printvalue);
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

        // In plugin settings, we can choose customfields we want to have rendered together.
        $returnarray['optionviewcustomfields'] = '';
        if (!empty($cfstoshowstring = get_config('booking', 'optionviewcustomfields'))) {
            $cfstoshow = explode(',', $cfstoshowstring);
            foreach ($cfstoshow as $cftoshow) {
                if (!empty($returnarray[$cftoshow])) {
                    $returnarray['optionviewcustomfields'] .=
                        "<div class='optionview-customfield-$cftoshow'>" .
                            $returnarray[$cftoshow] .
                        "</div>";
                }
            }
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
