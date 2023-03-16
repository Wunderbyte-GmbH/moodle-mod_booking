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

namespace mod_booking;
defined('MOODLE_INTERNAL') || die();

use cache_helper;
use context_module;
use stdClass;
use moodle_exception;
use core_user;
use core_text;
use context_system;
use context_user;
use core\message\message;
use mod_booking\booking_option;
use mod_booking\booking_settings;
use mod_booking\booking_option_settings;
use mod_booking\output\optiondates_only;
use mod_booking\output\bookingoption_changes;
use mod_booking\output\bookingoption_description;
use mod_booking\task\send_confirmation_mails;
use moodle_url;

require_once($CFG->dirroot.'/user/profile/lib.php');

/**
 * Manage booking messages which will be sent by email.
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_controller {

    /** @var int $msgcontrparam send mail now | queue adhoc task */
    private $msgcontrparam;

    /** @var int $messageparam the message type */
    private $messageparam;

    /** @var int $cmid course module id */
    private $cmid;

    /** @var int $bookingid booking id */
    private $bookingid;

    /** @var int $optionid */
    private $optionid;

    /** @var booking_option $option */
    private $option;

    /** @var int $userid */
    private $userid;

    /** @var int $optiondateid - optional - needed for session reminders */
    private $optiondateid = null;

    /** @var booking_settings $bookingsettings */
    private $bookingsettings;

    /** @var booking_option_settings $optionsettings */
    private $optionsettings;

    /** @var stdClass $user */
    private $user;

    /** @var message|stdClass $messagedata */
    private $messagedata;

    /** @var string $messagefieldname */
    private $messagefieldname;

    /** @var string $messagebody */
    private $messagebody;

    /** @var array $changes */
    private $changes;

    /** @var stdClass $bookingmanager */
    private $bookingmanager;

    /** @var stdClass $params email params */
    private $params;

    /** @var string $customsubject for custom messages */
    private $customsubject;

    /** @var string $custommessage for custom messages */
    private $custommessage;

    /** @var renderer_base $output*/
    private $output;

    /**
     * Constructor
     * @param int $msgcontrparam message controller param (send now | queue adhoc)
     * @param int $messageparam the message type
     * @param int $cmid course module id
     * @param int $bookingid booking id
     * @param int $optionid option id
     * @param int $userid user id
     * @param int|null $optiondateid optional id of a specific session (optiondate)
     * @param array $changes array of changes for change notifications
     * @param string $customsubject subject of custom messages
     * @param string $custommessage body of custom messages
     */
    public function __construct(int $msgcontrparam, int $messageparam, int $cmid, int $bookingid = null,
        int $optionid, int $userid, int $optiondateid = null, $changes = null,
        string $customsubject = '', string $custommessage = '') {

        global $DB, $USER, $PAGE;

        // Purge booking instance settings before sending mails to make sure, we use correct data.
        cache_helper::invalidate_by_event('setbackbookinginstances', [$cmid]);

        // When we call this via webservice, we don't have a context, this throws an error.
        // It's no use passing the context object either.

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        if (!isset($PAGE->context)) {
            $PAGE->set_context(context_module::instance($cmid));
        }

        if (!$bookingid) {
            $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
            $this->bookingid = $booking->id;
        } else {
            $this->bookingid = $bookingid;
        }

        // Settings.
        $this->bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $this->optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->output = $PAGE->get_renderer('mod_booking');

        if (empty($this->optionsettings->id)) {
            debugging('ERROR: Option settings could not be created. Most probably, the option was deleted from DB.',
                DEBUG_DEVELOPER);
            return;
        }

        // Get the booking manager.
        $this->bookingmanager = $this->bookingsettings->bookingmanageruser;

        // Standard params.
        $this->msgcontrparam = $msgcontrparam;
        $this->messageparam = $messageparam;
        $this->cmid = $cmid;
        $this->optionid = $optionid;
        $this->userid = $userid;
        $this->optiondateid = $optiondateid;
        $this->changes = $changes;

        // For custom messages only.
        if ($this->messageparam == MSGPARAM_CUSTOM_MESSAGE) {
            $this->customsubject = $customsubject;
            $this->custommessage = $custommessage;
        }

        // Booking_option instance needed to access functions get_all_users_booked and get_all_users_on_waitinglist.
        $this->option = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        // Resolve the correct message fieldname.
        $this->messagefieldname = $this->get_message_fieldname();

        // Generate user data.
        if ($userid == $USER->id) {
            $this->user = $USER;
        } else {
            $this->user = $DB->get_record('user', array('id' => $userid));
        }

        // Generate email params.
        $this->params = $this->get_email_params();

        // Generate the email body.
        $this->messagebody = $this->get_email_body();

        // For adhoc task mails, we need to prepare data differently.
        if ($this->msgcontrparam == MSGCONTRPARAM_QUEUE_ADHOC) {
            $this->messagedata = $this->get_message_data_queue_adhoc();
        } else {
            $this->messagedata = $this->get_message_data_send_now();
        }
    }

    /**
     * Prepares the email parameters.
     * @return stdClass data to be sent via mail
     */
    private function get_email_params(): stdClass {

        global $CFG;

        $params = new stdClass();

        $timeformat = get_string('strftimetime', 'langconfig');
        $dateformat = get_string('strftimedate', 'langconfig');

        $courselink = '';
        if ($this->optionsettings->courseid) {
            $courselink = new \moodle_url('/course/view.php', array('id' => $this->optionsettings->courseid));
            $courselink = \html_writer::link($courselink, $courselink->out());
        }
        $bookinglink = new \moodle_url('/mod/booking/view.php', array('id' => $this->cmid));
        $bookinglink = \html_writer::link($bookinglink, $bookinglink->out());

        // We add the URLs for the user to subscribe to user and course event calendar.
        $bu = new booking_utils();

        // These links will not be clickable (beacuse they will be copied by users).
        $params->usercalendarurl = '<a href="#" style="text-decoration:none; color:#000">' .
        $bu->booking_generate_calendar_subscription_link($this->user, 'user') .
        '</a>';

        $params->coursecalendarurl = '<a href="#" style="text-decoration:none; color:#000">' .
        $bu->booking_generate_calendar_subscription_link($this->user, 'courses') .
        '</a>';

        // Add a placeholder with a link to go to the current booking option.
        $gotobookingoptionlink = new \moodle_url($CFG->wwwroot . '/mod/booking/view.php', array(
            'id' => $this->cmid,
            'optionid' => $this->optionid,
            'whichview' => 'showonlyone'
        ));
        $params->gotobookingoption = \html_writer::link($gotobookingoptionlink, $gotobookingoptionlink->out());

        // Important: We have to delete answers cache before calling $bookinganswer->user_status.
        $cache = \cache::make('mod_booking', 'bookingoptionsanswers');
        $data = $cache->delete($this->optionid);
        $bookinganswer = singleton_service::get_instance_of_booking_answers($this->optionsettings);
        $params->status = $this->option->get_user_status_string($this->userid, $bookinganswer->user_status($this->userid));

        $params->qr_id = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
            rawurlencode($this->userid) . '&choe=UTF-8" title="Link to Google.com" />';
        $params->qr_username = '<img src="https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' .
            rawurlencode($this->user->username) . '&choe=UTF-8" title="Link to Google.com" />';
        $params->participant = fullname($this->user);
        $params->email = $this->user->email;
        $params->title = format_string($this->optionsettings->get_title_with_prefix());
        $params->duration = $this->bookingsettings->duration;
        $params->starttime = $this->optionsettings->coursestarttime ?
            userdate($this->optionsettings->coursestarttime, $timeformat) : '';
        $params->endtime = $this->optionsettings->courseendtime ?
            userdate($this->optionsettings->courseendtime, $timeformat) : '';
        $params->startdate = $this->optionsettings->coursestarttime ?
            userdate($this->optionsettings->coursestarttime, $dateformat) : '';
        $params->enddate = $this->optionsettings->courseendtime ?
            userdate($this->optionsettings->courseendtime, $dateformat) : '';
        $params->courselink = $courselink;
        $params->bookinglink = $bookinglink;
        $params->location = $this->optionsettings->location;
        $params->institution = $this->optionsettings->institution;
        $params->address = $this->optionsettings->address;
        $params->eventtype = $this->bookingsettings->eventtype;
        $params->shorturl = $this->optionsettings->shorturl;
        $params->pollstartdate = $this->optionsettings->coursestarttime ?
            userdate((int) $this->optionsettings->coursestarttime, get_string('pollstrftimedate', 'booking')) : '';
        if (empty($this->optionsettings->pollurl)) {
            $params->pollurl = $this->bookingsettings->pollurl;
        } else {
            $params->pollurl = $this->optionsettings->pollurl;
        }
        if (empty($this->optionsettings->pollurlteachers)) {
            $params->pollurlteachers = $this->bookingsettings->pollurlteachers;
        } else {
            $params->pollurlteachers = $this->optionsettings->pollurlteachers;
        }

        // Placeholder for the number of booked users.
        $params->numberparticipants = strval(count($this->option->get_all_users_booked()));

        // Placeholder for the number of users on the waiting list.
        $params->numberwaitinglist = strval(count($this->option->get_all_users_on_waitinglist()));

        // If there are changes, let's render them.
        if (!empty($this->changes)) {
            $data = new bookingoption_changes($this->changes, $this->cmid);
            $params->changes = $this->output->render_bookingoption_changes($data);
        }

        switch ($this->msgcontrparam) {
            case MSGCONTRPARAM_SEND_NOW:
            case MSGCONTRPARAM_QUEUE_ADHOC:
                // Add placeholder {bookingdetails} so we can add the detailed option description (similar to calendar, modal...
                // ... and ical) to mails.
                $params->bookingdetails = get_rendered_eventdescription($this->optionsettings->id,
                    $this->cmid, DESCRIPTION_MAIL);
                break;
            case MSGCONTRPARAM_VIEW_CONFIRMATION:
                // For viewconfirmation.php.
                $params->bookingdetails = get_rendered_eventdescription($this->optionsettings->id,
                    $this->cmid, DESCRIPTION_WEBSITE);
                break;
            case MSGCONTRPARAM_DO_NOT_SEND:
            default:
                break;
        }

        // Params for session reminders.
        if ($this->messageparam == MSGPARAM_SESSIONREMINDER) {

            // For session reminders we only have ONE session.
            $sessions = [];
            foreach ($this->optionsettings->sessions as $session) {
                if (!empty($session->optiondateid) && !empty($this->optiondateid)) {
                    if ($session->optiondateid == $this->optiondateid) {
                        $sessions[] = $session;
                    }
                }
            }

            // Render optiontimes using a template.
            $data = new optiondates_only($this->optionsettings);
            $params->dates = $this->output->render_optiondates_only($data);

            // Rendered session description.
            $params->sessiondescription = get_rendered_eventdescription($this->optionid, $this->cmid, DESCRIPTION_CALENDAR);

        } else {
            // Render optiontimes using a template.
            $data = new optiondates_only($this->optionsettings);
            $params->dates = $this->output->render_optiondates_only($data);
        }

        // Add placeholders for additional user fields.
        if (isset($this->user->username)) {
            $params->username = $this->user->username;
        }
        if (isset($this->user->firstname)) {
            $params->firstname = $this->user->firstname;
        }
        if (isset($this->user->lastname)) {
            $params->lastname = $this->user->lastname;
        }
        if (isset($this->user->department)) {
            $params->department = $this->user->department;
        }

        // Get bookingoption_description instance for rendering certain data.
        $params->teachers = $this->optionsettings->render_list_of_teachers();

        // Params for individual teachers.
        $i = 1;
        foreach ($this->optionsettings->teachers as $teacher) {
            $params->{"teacher" . $i} = $teacher->firstname . ' ' . $teacher->lastname;
            $i++;
        }
        // If there's only one teacher, we can use either {teacher} or {teacher1}.
        if (!empty($params->teacher1)) {
            $params->teacher = $params->teacher1;
        } else {
            $params->teacher = '';
        }

        // Add user profile fields to e-mail params.
        // If user profile fields are missing, we need to load them correctly.
        if (empty($this->user->profile)) {
            $this->user->profile = [];
            profile_load_data($this->user);
            foreach ($this->user as $userkey => $uservalue) {
                if (substr($userkey, 0, 14) == "profile_field_") {
                    $profilefieldkey = str_replace('profile_field_', '', $userkey);
                    $this->user->profile[$profilefieldkey] = $uservalue;
                }
            }
        }
        foreach ($this->user->profile as $profilefieldkey => $profilefieldvalue) {
            // Ignore fields that use a param name that is already in use.
            if (!isset($params->{$profilefieldkey})) {
                // Example: There is a user profile field called "Title".
                // We can now use the placeholder {Title}. (Keep in mind that this is case-sensitive!).
                $params->{$profilefieldkey} = $profilefieldvalue;
            }
        }

        // Add a param for the user profile picture.
        if ($usercontext = context_user::instance($this->userid, IGNORE_MISSING)) {
            $fs = get_file_storage();
            $files = $fs->get_area_files($usercontext->id, 'user', 'icon');
            $picturefile = null;
            foreach ($files as $file) {
                $filenamewithoutextension = explode('.', $file->get_filename())[0];
                if ($filenamewithoutextension === 'f1') {
                    $picturefile = $file;
                    // We found it, so break the loop.
                    break;
                }
            }
            if ($picturefile) {
                // Retrieve the image contents and encode them as base64.
                $picturedata = $picturefile->get_content();
                $picturebase64 = base64_encode($picturedata);
                // Now load the HTML of the image into the profilepicture param.
                $params->profilepicture = '<img src="data:image/image;base64,' . $picturebase64 . '" />';
            } else {
                $params->profilepicture = '';
            }
        }

        return $params;
    }

    /**
     * Generate the email body based on the message type and the booking parameters
     *
     * @return string the email body
     */
    private function get_email_body(): string {

        // List of fieldnames that also have a global template (currently 'activitycompletiontext' has no global template).
        $mailtemplatesfieldnames = [
            'bookedtext', 'waitingtext', 'notifyemail', 'notifyemailteachers', 'statuschangetext', 'userleave',
            'deletedtext', 'bookingchangedtext', 'pollurltext', 'pollurlteacherstext'
        ];

        if ($this->messageparam == MSGPARAM_CUSTOM_MESSAGE) {
            // For custom messages, we already have a message body.
            $text = $this->custommessage;
        } else if (isset($this->bookingsettings->mailtemplatessource) && $this->bookingsettings->mailtemplatessource == 1
            && in_array($this->messagefieldname, $mailtemplatesfieldnames)) {
            // Check if global mail templates are enabled and if the field name also has a global mail template.
            // Get the mail template specified in plugin config.
            $text = get_config('booking', 'global' . $this->messagefieldname);

        } else if (isset($this->bookingsettings->{$this->messagefieldname})
            && $this->bookingsettings->{$this->messagefieldname} === "0") {
            /* NOTE: By entering 0 into a mail template, we can turn the specific mail reminder off.
            This is why we need the === check for the exact string of "0". */
            $text = "0";
        } else if (!empty($this->bookingsettings->{$this->messagefieldname})) {
            // If there is an instance-specific template, then use it.
            $text = $this->bookingsettings->{$this->messagefieldname};
        } else {
            // Use default message if none is specified.
            $text = get_string($this->messagefieldname . 'message', 'booking', $this->params);
        }

        // Replace the placeholders.
        foreach ($this->params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }

        return $text;
    }

    /**
     * Get the actual message data needed to send the message.
     * @return message the message object
     */
    private function get_message_data_send_now(): message {

        global $USER;

        $messagedata = new message();

        // If a valid booking manager was set, use booking manager as sender, else global $USER will be set.
        if (!empty($this->bookingmanager)) {
            $messagedata->userfrom = $this->bookingmanager;
        } else {
            $messagedata->userfrom = $USER;
        }
        $messagedata->userto = $this->user;
        $messagedata->modulename = 'booking';

        if ($this->messageparam == MSGPARAM_CUSTOM_MESSAGE) {
            // For custom messages use the custom subject.
            $messagedata->subject = $this->customsubject;
        } else {
            // Else use the localized lang string for the correspondent message type.
            $messagedata->subject = get_string($this->messagefieldname . 'subject', 'booking', $this->params);
        }

        $messagedata->fullmessage = strip_tags(preg_replace('#<br\s*?/?>#i', "\n", $this->messagebody));
        $messagedata->fullmessageformat = FORMAT_HTML;
        $messagedata->fullmessagehtml = $this->messagebody;
        $messagedata->smallmessage = '';
        $messagedata->component = 'mod_booking';
        $messagedata->name = 'bookingconfirmation';
        $messagedata->courseid = $this->bookingsettings->course;

        return $messagedata;
    }

    /**
     * Get the actual message data needed to send the message.
     * @return stdClass the message object
     */
    private function get_message_data_queue_adhoc(): stdClass {

        global $USER, $PAGE;

        $messagedata = new stdClass();

        // If a valid booking manager was set, use booking manager as sender, else global $USER will be set.
        if (!empty($this->bookingmanager)) {
            $messagedata->userfrom = $this->bookingmanager;
        } else {
            $messagedata->userfrom = $USER;
        }

        $messagedata->modulename = 'booking';

        if ($this->messageparam == MSGPARAM_CUSTOM_MESSAGE) {
            // For custom messages use the custom subject.
            $messagedata->subject = $this->customsubject;
        } else {
            // Else use the localized lang string for the correspondent message type.
            $messagedata->subject = get_string($this->messagefieldname . 'subject', 'booking', $this->params);
        }

        $messagedata->messagetext = format_text_email($this->messagebody, FORMAT_HTML);
        $messagedata->messagehtml = text_to_html($this->messagebody, false, false, true);
        $messagedata->messageparam = $this->messageparam;
        $messagedata->name = 'bookingconfirmation';

        // The "send mail to booker" setting is only available for adhoc mails.
        if (!empty($this->bookingsettings->sendmailtobooker)) {
            $messagedata->userto = $USER;
        } else {
            $messagedata->userto = $this->user;
        }

        if ($this->messageparam == MSGPARAM_CHANGE_NOTIFICATION) {
            $updated = true;
        } else {
            $updated = false;
        }

        // Add attachments if there are any.
        list($attachments, $attachname) = $this->get_attachments($updated);

        if (!empty($attachments)) {
            $messagedata->attachment = $attachments;
            $messagedata->attachname = $attachname;
        }

        return $messagedata;
    }

    /**
     * Send the message.
     * @return bool true if successful
     */
    public function send_or_queue(): bool {

        // If user entered "0" as template, then mails are turned off for this type of messages.
        if ($this->messagebody === "0") {
            $this->msgcontrparam = MSGCONTRPARAM_DO_NOT_SEND;
        }

        // Only send if we have message data and if the user hasn't been deleted.
        // Also, do not send, if the param MSGCONTRPARAM_DO_NOT_SEND has been set.
        if ($this->msgcontrparam != MSGCONTRPARAM_DO_NOT_SEND
            && !empty( $this->messagedata ) && !$this->user->deleted) {

            if ($this->msgcontrparam == MSGCONTRPARAM_QUEUE_ADHOC) {

                return $this->send_mail_with_adhoc_task();

            } else {

                // In all other cases, use message_send.
                if (message_send($this->messagedata)) {

                    // Use an event to log that a message has been sent.
                    $event = \mod_booking\event\message_sent::create(array(
                        'context' => context_system::instance(),
                        'userid' => $this->messagedata->userto->id,
                        'relateduserid' => $this->messagedata->userfrom->id,
                        'other' => array(
                            'messageparam' => $this->messageparam,
                            'subject' => $this->messagedata->subject
                        )
                    ));
                    $event->trigger();

                    return true;
                } else {
                    return false;
                }
            }
        } else {
            return false;
        }
    }

    /**
     * Helper function to queue an adhoc tasks for sending an email.
     * @return bool
     */
    private function send_mail_with_adhoc_task() {

        // Purge booking instance settings before sending mails to make sure, we use correct data.
        cache_helper::invalidate_by_event('setbackbookinginstances', [$this->cmid]);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($this->cmid);

        if (!empty($bookingsettings->sendmail) || !empty($bookingsettings->copymail)) {

            if (!empty($bookingsettings->sendmail)) {
                $sendtask = new send_confirmation_mails();
                $sendtask->set_custom_data($this->messagedata);
                \core\task\manager::queue_adhoc_task($sendtask);
            }

            // If the setting to send a copy to the booking manger has been enabled,
            // then also send a copy to the booking manager.
            // DO NOT send copies of change notifications to booking managers.
            if (!empty($bookingsettings->copymail) &&
                $this->messageparam != MSGPARAM_CHANGE_NOTIFICATION
            ) {
                // Get booking manager from booking instance settings.
                $this->messagedata->userto = $bookingsettings->bookingmanageruser;

                if ($this->messageparam == MSGPARAM_CONFIRMATION || $this->messageparam == MSGPARAM_WAITINGLIST) {
                    $this->messagedata->subject = get_string($this->messagefieldname . 'subjectbookingmanager',
                        'mod_booking', $this->params);
                }

                $sendtask = new send_confirmation_mails();
                $sendtask->set_custom_data($this->messagedata);
                \core\task\manager::queue_adhoc_task($sendtask);
            }

            return true;
        } else {
            return false;
        }
    }

    /**
     * Get ical attachments.
     * @param bool $updated if set to true, it will create an update ical (METHOD: REQUEST, SEQUENCE: 1)
     * @return array [array $attachments, string $attachname]
     */
    private function get_attachments(bool $updated = false): array {
        $attachments = null;
        $attachname = '';

        if ($this->messageparam == MSGPARAM_CANCELLED_BY_PARTICIPANT
            || $this->messageparam == MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM) {
            // Check if setting to send a cancel ical is enabled.
            if (get_config('booking', 'icalcancel')) {
                $ical = new ical($this->bookingsettings, $this->optionsettings, $this->user, $this->bookingmanager, false);
                $attachments = $ical->get_attachments(true);
                $attachname = $ical->get_name();
            }

        } else {
            // Generate ical attachments to go with the message. Check if ical attachments enabled.
            if (get_config('booking', 'attachical') || get_config('booking', 'attachicalsessions')) {
                $ical = new ical($this->bookingsettings, $this->optionsettings, $this->user, $this->bookingmanager, $updated);
                $attachments = $ical->get_attachments(false);
                $attachname = $ical->get_name();
            }
        }

        return [$attachments, $attachname];
    }

    /**
     * Public getter function for the message body.
     * @return string the message body
     */
    public function get_messagebody(): string {

        return $this->messagebody;

    }

    /**
     * Public getter function for the email params.
     * @return stdClass email params
     */
    public function get_params(): stdClass {

        return $this->params;

    }

    /**
     * Helper function to get the fieldname for the message type.
     * @return string the field name
     */
    private function get_message_fieldname() {

        switch ($this->messageparam) {
            case MSGPARAM_CONFIRMATION:
                $fieldname = 'bookedtext';
                break;
            case MSGPARAM_WAITINGLIST:
                $fieldname = 'waitingtext';
                break;
            case MSGPARAM_REMINDER_PARTICIPANT:
                $fieldname = 'notifyemail';
                break;
            case MSGPARAM_REMINDER_TEACHER:
                $fieldname = 'notifyemailteachers';
                break;
            case MSGPARAM_STATUS_CHANGED:
                $fieldname = 'statuschangetext';
                break;
            case MSGPARAM_CANCELLED_BY_PARTICIPANT:
                $fieldname = 'userleave';
                break;
            case MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM:
                $fieldname = 'deletedtext';
                break;
            case MSGPARAM_CHANGE_NOTIFICATION:
                $fieldname = 'bookingchangedtext';
                break;
            case MSGPARAM_POLLURL_PARTICIPANT:
                $fieldname = 'pollurltext';
                break;
            case MSGPARAM_POLLURL_TEACHER:
                $fieldname = 'pollurlteacherstext';
                break;
            case MSGPARAM_COMPLETED:
                $fieldname = 'activitycompletiontext';
                break;
            case MSGPARAM_SESSIONREMINDER:
                $fieldname = 'sessionremindermail';
                break;
            case MSGPARAM_REPORTREMINDER:
                $fieldname = 'reportreminder';
                break;
            case MSGPARAM_CUSTOM_MESSAGE:
                $fieldname = 'custommessage';
                break;
            default:
                throw new moodle_exception('ERROR: Unknown message parameter!');
        }
        return $fieldname;
    }

    /**
     * WE WANT TO GET RID OF THIS MONSTER FUNCTION IN THE FUTURE.
     * WE CURRENTLY NEED IT TO SUPPORT MULTIPLE ICAL-ATTACHMENTS.
     * USAGE IN: send_confirmation_mails.php.
     *
     * Send an email to a specified user
     *
     * @param stdClass $user A {@see $USER} object
     * @param stdClass $from A {@see $USER} object
     * @param string $subject plain text subject line of the email
     * @param string $messagetext plain text version of the message
     * @param string $messagehtml complete html version of the message (optional)
     * @param string $attachment a file on the filesystem, either relative to $CFG->dataroot or a full
     *        path to a file in $CFG->tempdir
     * @param string $attachname the name of the file (extension indicates MIME)
     * @param bool $usetrueaddress determines whether $from email address should
     *        be sent out. Will be overruled by user profile setting for maildisplay
     * @param string $replyto Email address to reply to
     * @param string $replytoname Name of reply to recipient
     * @param int $wordwrapwidth custom word wrap width, default 79
     * @return bool Returns true if mail was sent OK and false if there was an error.
     */
    public static function phpmailer_email_to_user($user, $from, $subject, $messagetext, $messagehtml = '',
        $attachment = '', $attachname = '', $usetrueaddress = true, $replyto = '', $replytoname = '',
        $wordwrapwidth = 79) {

        global $CFG, $PAGE, $SITE;

        if (empty($user) || empty($user->id)) {
            debugging('Can not send email to null user', DEBUG_DEVELOPER);
            return false;
        }

        if (empty($user->email)) {
            debugging('Can not send email to user without email: ' . $user->id, DEBUG_DEVELOPER);
            return false;
        }

        if (!empty($user->deleted)) {
            debugging('Can not send email to deleted user: ' . $user->id, DEBUG_DEVELOPER);
            return false;
        }

        /* // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        if (defined('BEHAT_SITE_RUNNING')) {
            // Fake email sending in behat.
            return true;
        }

        if (!empty($CFG->noemailever)) {
            // Hidden setting for development sites, set in config.php if needed.
            debugging('Not sending email due to $CFG->noemailever config setting', DEBUG_NORMAL);
            return true;
        }
        */

        if (email_should_be_diverted($user->email)) {
            $subject = "[DIVERTED {$user->email}] $subject";
            $user = clone ($user);
            $user->email = $CFG->divertallemailsto;
        }

        // Skip mail to suspended users.
        if ((isset($user->auth) && $user->auth == 'nologin') ||
            (!empty($user->suspended))) {
            return true;
        }

        if (!validate_email($user->email)) {
            // We can not send emails to invalid addresses - it might create security issue or confuse...
            // ...the mailer.
            debugging(
                    "email_to_user: User $user->id (" . fullname($user) .
                    ") email ($user->email) is invalid! Not sending.");
            return false;
        }

        if (over_bounce_threshold($user)) {
            debugging(
                    "email_to_user: User $user->id (" . fullname($user) .
                    ") is over bounce threshold! Not sending.");
            return false;
        }

        // TLD .invalid is specifically reserved for invalid domain names.
        // For More information, see {@link http://tools.ietf.org/html/rfc2606#section-2}.
        if (substr($user->email, -8) == '.invalid') {
            debugging(
                    "email_to_user: User $user->id (" . fullname($user) .
                    ") email domain ($user->email) is invalid! Not sending.");
            return true; // This is not an error.
        }

        // If the user is a remote mnet user, parse the email text for URL to the
        // wwwroot and modify the url to direct the user's browser to login at their
        // home site (identity provider - idp) before hitting the link itself.
        if (is_mnet_remote_user($user)) {
            require_once($CFG->dirroot . '/mnet/lib.php');

            $jumpurl = mnet_get_idp_jump_url($user);
            $callback = partial('mnet_sso_apply_indirection', $jumpurl);

            $messagetext = preg_replace_callback("%($CFG->wwwroot[^[:space:]]*)%", $callback,
                    $messagetext);
            $messagehtml = preg_replace_callback("%href=[\"'`]($CFG->wwwroot[\w_:\?=#&@/;.~-]*)[\"'`]%",
                    $callback, $messagehtml);
        }
        $mail = get_mailer();

        if (!empty($mail->SMTPDebug)) {
            echo '<pre>' . "\n";
        }

        $temprecipients = array();
        $tempreplyto = array();

        // Make sure that we fall back onto some reasonable no-reply address.
        $noreplyaddressdefault = 'noreply@' . get_host_from_url($CFG->wwwroot);
        $noreplyaddress = empty($CFG->noreplyaddress) ? $noreplyaddressdefault : $CFG->noreplyaddress;

        if (!validate_email($noreplyaddress)) {
            debugging('email_to_user: Invalid noreply-email ' . s($noreplyaddress));
            $noreplyaddress = $noreplyaddressdefault;
        }

        // Make up an email address for handling bounces.
        if (!empty($CFG->handlebounces)) {
            $modargs = 'B' . base64_encode(pack('V', $user->id)) . substr(md5($user->email), 0, 16);
            $mail->Sender = generate_email_processing_address(0, $modargs);
        } else {
            $mail->Sender = $noreplyaddress;
        }

        // Make sure that the explicit replyto is valid, fall back to the implicit one.
        if (!empty($replyto) && !validate_email($replyto)) {
            debugging('email_to_user: Invalid replyto-email ' . s($replyto));
            $replyto = $noreplyaddress;
        }

        if (is_string($from)) { // So we can pass whatever we want if there is need.
            $mail->From = $noreplyaddress;
            $mail->FromName = $from;
            // Check if using the true address is true, and the email is in the list of allowed domains
            // for sending email,
            // and that the senders email setting is either displayed to everyone, or display to only
            // other users that are enrolled
            // in a course with the sender.
        } else if ($usetrueaddress && can_send_from_real_email_address($from, $user)) {
            if (!validate_email($from->email)) {
                debugging('email_to_user: Invalid from-email ' . s($from->email) . ' - not sending');
                // Better not to use $noreplyaddress in this case.
                return false;
            }
            $mail->From = $from->email;
            $fromdetails = new stdClass();
            $fromdetails->name = fullname($from);
            $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
            $fromdetails->siteshortname = format_string($SITE->shortname);
            $fromstring = $fromdetails->name;
            if ($CFG->emailfromvia == EMAIL_VIA_ALWAYS) {
                $fromstring = get_string('emailvia', 'core', $fromdetails);
            }
            $mail->FromName = $fromstring;
            if (empty($replyto)) {
                $tempreplyto[] = array($from->email, fullname($from));
            }
        } else {
            $mail->From = $noreplyaddress;
            $fromdetails = new stdClass();
            $fromdetails->name = fullname($from);
            $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
            $fromdetails->siteshortname = format_string($SITE->shortname);
            $fromstring = $fromdetails->name;
            if ($CFG->emailfromvia != EMAIL_VIA_NEVER) {
                $fromstring = get_string('emailvia', 'core', $fromdetails);
            }
            $mail->FromName = $fromstring;
            if (empty($replyto)) {
                $tempreplyto[] = array($noreplyaddress, get_string('noreplyname'));
            }
        }

        if (!empty($replyto)) {
            $tempreplyto[] = array($replyto, $replytoname);
        }

        $temprecipients[] = array($user->email, fullname($user));

        // Set word wrap.
        $mail->WordWrap = $wordwrapwidth;

        if (!empty($from->customheaders)) {
            // Add custom headers.
            if (is_array($from->customheaders)) {
                foreach ($from->customheaders as $customheader) {
                    $mail->addCustomHeader($customheader);
                }
            } else {
                $mail->addCustomHeader($from->customheaders);
            }
        }

        // If the X-PHP-Originating-Script email header is on then also add an additional
        // header with details of where exactly in moodle the email was triggered from,
        // either a call to message_send() or to email_to_user().
        if (ini_get('mail.add_x_header')) {
            // phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.Changed
            $stack = debug_backtrace(false);
            $origin = $stack[0];

            foreach ($stack as $depth => $call) {
                if ($call['function'] == 'message_send') {
                    $origin = $call;
                }
            }

            $originheader = $CFG->wwwroot . ' => ' . gethostname() . ':' .
                    str_replace($CFG->dirroot . '/', '', $origin['file']) . ':' . $origin['line'];
            $mail->addCustomHeader('X-Moodle-Originating-Script: ' . $originheader);
        }

        if (!empty($from->priority)) {
            $mail->Priority = $from->priority;
        }

        $renderer = $PAGE->get_renderer('core');
        $context = array('sitefullname' => $SITE->fullname, 'siteshortname' => $SITE->shortname,
        'sitewwwroot' => $CFG->wwwroot, 'subject' => $subject, 'to' => $user->email,
        'toname' => fullname($user), 'from' => $mail->From, 'fromname' => $mail->FromName);
        if (!empty($tempreplyto[0])) {
            $context['replyto'] = $tempreplyto[0][0];
            $context['replytoname'] = $tempreplyto[0][1];
        }
        if ($user->id > 0) {
            $context['touserid'] = $user->id;
            $context['tousername'] = $user->username;
        }

        if (!empty($user->mailformat) && $user->mailformat == 1) {
            // Only process html templates if the user preferences allow html email.

            if ($messagehtml) {
                // If html has been given then pass it through the template.
                $context['body'] = $messagehtml;
                $messagehtml = $renderer->render_from_template('core/email_html', $context);
            } else {
                // If no html has been given, BUT there is an html wrapping template then
                // auto convert the text to html and then wrap it.
                $autohtml = trim(text_to_html($messagetext));
                $context['body'] = $autohtml;
                $temphtml = $renderer->render_from_template('core/email_html', $context);
                if ($autohtml != $temphtml) {
                    $messagehtml = $temphtml;
                }
            }
        }

        $context['body'] = $messagetext;
        $mail->Subject = $renderer->render_from_template('core/email_subject', $context);
        $mail->FromName = $renderer->render_from_template('core/email_fromname', $context);
        $messagetext = $renderer->render_from_template('core/email_text', $context);

        // Autogenerate a MessageID if it's missing.
        if (empty($mail->MessageID)) {
            $mail->MessageID = generate_email_messageid();
        }

        if ($messagehtml && !empty($user->mailformat) && $user->mailformat == 1) {
            // Don't ever send HTML to users who don't want it.
            $mail->isHTML(true);
            $mail->Encoding = 'quoted-printable';
            $mail->Body = $messagehtml;
            $mail->AltBody = "\n$messagetext\n";
        } else {
            $mail->isHTML(false);
            $mail->Body = "\n$messagetext\n";
        }

        // Fix: Prevent from adding empty attachments.
        if (!empty($attachment)) {
            if (!is_array((array) $attachment) && ($attachment && $attachname)) {
                $attachment[$attachname] = $attachment;
            }
            if (is_array((array) $attachment)) {
                $attachment = (array) $attachment;
                foreach ($attachment as $attachname => $attachlocation) {
                    if (preg_match("~\\.\\.~", $attachlocation)) {
                        // Security check for ".." in dir path.
                        $supportuser = core_user::get_support_user();
                        $temprecipients[] = array($supportuser->email, fullname($supportuser, true));
                        $mail->addStringAttachment(
                                'Error in attachment.  User attempted to attach a filename with an unsafe name.',
                                'error.txt', '8bit', 'text/plain');
                    } else {
                        require_once($CFG->libdir . '/filelib.php');
                        $mimetype = mimeinfo('type', $attachname);

                        $attachmentpath = $attachlocation;

                        // Before doing the comparison, make sure that the paths are correct (Windows uses
                        // slashes in the other direction).
                        $attachpath = str_replace('\\', '/', $attachmentpath);
                        // Make sure both variables are normalised before comparing.
                        $temppath = str_replace('\\', '/', realpath($CFG->tempdir));

                        // If the attachment is a full path to a file in the tempdir, use it as is,
                        // otherwise assume it is a relative path from the dataroot (for backwards
                        // compatibility reasons).
                        if (strpos($attachpath, $temppath) !== 0) {
                            $attachmentpath = $CFG->dataroot . '/' . $attachmentpath;
                        }

                        $mail->addAttachment($attachmentpath, $attachname, 'base64', $mimetype);
                    }
                }
            }
        }

        // Check if the email should be sent in an other charset then the default UTF-8.
        if ((!empty($CFG->sitemailcharset) || !empty($CFG->allowusermailcharset))) {

            // Use the defined site mail charset or eventually the one preferred by the recipient.
            $charset = $CFG->sitemailcharset;
            if (!empty($CFG->allowusermailcharset)) {
                if ($useremailcharset = get_user_preferences('mailcharset', '0', $user->id)) {
                    $charset = $useremailcharset;
                }
            }

            // Convert all the necessary strings if the charset is supported.
            $charsets = get_list_of_charsets();
            unset($charsets['UTF-8']);
            if (in_array($charset, $charsets)) {
                $mail->CharSet = $charset;
                $mail->FromName = core_text::convert($mail->FromName, 'utf-8', strtolower($charset));
                $mail->Subject = core_text::convert($mail->Subject, 'utf-8', strtolower($charset));
                $mail->Body = core_text::convert($mail->Body, 'utf-8', strtolower($charset));
                $mail->AltBody = core_text::convert($mail->AltBody, 'utf-8', strtolower($charset));

                foreach ($temprecipients as $key => $values) {
                    $temprecipients[$key][1] = core_text::convert($values[1], 'utf-8',
                            strtolower($charset));
                }
                foreach ($tempreplyto as $key => $values) {
                    $tempreplyto[$key][1] = core_text::convert($values[1], 'utf-8', strtolower(
                            $charset));
                }
            }
        }

        foreach ($temprecipients as $values) {
            $mail->addAddress($values[0], $values[1]);
        }
        foreach ($tempreplyto as $values) {
            $mail->addReplyTo($values[0], $values[1]);
        }

        if ($mail->send()) {
            set_send_count($user);
            if (!empty($mail->SMTPDebug)) {
                echo '</pre>';
            }
            return true;
        } else {
            // Trigger event for failing to send email.
            $event = \core\event\email_failed::create(
                    array('context' => context_system::instance(), 'userid' => $from->id,
                        'relateduserid' => $user->id,
                        'other' => array('subject' => $subject, 'message' => $messagetext,
                            'errorinfo' => $mail->ErrorInfo)));
            $event->trigger();

            if (!empty($mail->SMTPDebug)) {
                echo '</pre>';
            }

            return false;
        }
    }
}
