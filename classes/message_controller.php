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
use stdClass;
use moodle_exception;
use context_system;
use core\message\message;
use mod_booking\booking_option;
use mod_booking\booking_settings;
use mod_booking\booking_option_settings;
use mod_booking\output\optiondates_only;
use mod_booking\output\bookingoption_changes;
use mod_booking\output\renderer;
use mod_booking\placeholders\placeholders_info;
use mod_booking\task\send_confirmation_mails;

require_once($CFG->dirroot . '/user/profile/lib.php');

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

    /** @var stdClass $params for strings */
    private $stringparams;

    /** @var string $customsubject for custom messages */
    private $customsubject;

    /** @var string $custommessage for custom messages */
    private $custommessage;

    /** @var int $descriptionparam param to render booking option description for mails, websites etc. */
    private $descriptionparam;

    /** @var int $installmentnr number of installment - starting with 0. */
    private $installmentnr;

    /** @var string $rulejson eventdata string */
    private $rulejson;

    /** @var int $duedate duedate of installment. */
    private $duedate;

    /** @var float $price price given in installment */
    private $price;

    /** @var stdClass $rulesettings duedate of installment. */
    private $rulesettings;

    /** @var array $ruleid the id of the running rule. */
    private $ruleid;

    /**
     * Constructor
     *
     * @param int $msgcontrparam message controller param (send now | queue adhoc)
     * @param int $messageparam the message type
     * @param int $cmid course module id
     * @param int $optionid option id
     * @param int $userid user id
     * @param ?int $bookingid booking id
     * @param ?int $optiondateid optional id of a specific session (optiondate)
     * @param ?array $changes array of changes for change notifications
     * @param string $customsubject subject of custom messages
     * @param string $custommessage body of custom messages
     * @param int $installmentnr number of installment
     * @param int $duedate UNIX timestamp for duedate of installment
     * @param float $price price of installment
     * @param string $rulejson event data
     * @param ?int $ruleid the id of the running rule
     */
    public function __construct(
        int $msgcontrparam,
        int $messageparam,
        int $cmid,
        int $optionid,
        int $userid,
        ?int $bookingid = null,
        ?int $optiondateid = null,
        ?array $changes = null,
        string $customsubject = '',
        string $custommessage = '',
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0.0,
        string $rulejson = '',
        ?int $ruleid = null
    ) {

        global $USER, $PAGE, $DB;

        if (!is_null($ruleid)) {
            // For some reason $this->rulejson doesn't get passed to the controller.
            // So instead we use the ruleid that we have added to this class.
            // Get the rulesjson and convert into an array for later
            // There is probably an exisiting method for this, but I couldn't find it.
            $this->rulesettings = $DB->get_record('booking_rules', ['id' => $ruleid], 'rulejson');
            if ($this->rulesettings) {
                $this->rulesettings = json_decode($this->rulesettings->rulejson);
                $this->ruleid = $ruleid;
            }
        }

        $user = singleton_service::get_instance_of_user($userid);
        $originallanguage = force_current_language($user->lang);
        $customsubject = format_text($customsubject, FORMAT_HTML, ['noclean' => true]);
        $custommessage = format_text($custommessage, FORMAT_HTML, ['noclean' => true]);

        // Todo: This is a bad idea. We need to find out the correct places where we really need to purge!
        // Purge booking instance settings before sending mails to make sure, we use correct data.
        cache_helper::invalidate_by_event('setbackbookinginstances', [$cmid]);

        // When we call this via webservice, we don't have a context, this throws an error.
        // It's no use passing the context object either.

        // With shortcodes & webservice we might not have a valid context object.
        booking_context_helper::fix_booking_page_context($PAGE, $cmid);

        if (!$bookingid) {
            $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
            $this->bookingid = $booking->id;
        } else {
            $this->bookingid = $bookingid;
        }

        // Settings.
        $this->bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $this->optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

        /** @var renderer $output*/
        $output = $PAGE->get_renderer('mod_booking');

        // For easy access.
        $settings = $this->optionsettings;
        $optionid = $settings->id;

        if (empty($optionid)) {
            debugging(
                'ERROR: Option settings could not be created. Most probably, the option was deleted from DB.',
                DEBUG_DEVELOPER
            );
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
        $this->installmentnr = $installmentnr;
        $this->duedate = $duedate;
        $this->price = $price;
        $this->rulejson = $rulejson;
        $this->params = new stdClass();

        // Apply placeholder to subject.
        $customsubject = placeholders_info::render_text(
            $customsubject,
            $this->optionsettings->cmid,
            $this->optionid,
            $this->userid,
            $this->installmentnr,
            $this->duedate,
            $this->price,
            $this->descriptionparam ?? MOD_BOOKING_DESCRIPTION_WEBSITE,
            $this->rulejson
        );

        // For custom messages only.
        if ($this->messageparam == MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE) {
            $this->customsubject = format_string($customsubject);
            $this->custommessage = $custommessage;
        }

        // Booking_option instance.
        $this->option = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        // Resolve the correct message fieldname.
        $this->messagefieldname = $this->get_message_fieldname();

        // Generate user data.
        if ($userid == $USER->id) {
            $this->user = $USER;
        } else {
            $this->user = singleton_service::get_instance_of_user($userid);
        }

        // We need these for some strings!
        $this->stringparams = new stdClass();
        $this->stringparams->title = $settings->get_title_with_prefix();
        $this->stringparams->participant = $this->user->firstname . " " . $this->user->lastname;
        // Param sessiondescription is only needed for session reminders.
        // It's used as string param {$a->sessionreminder} in the default message string 'sessionremindermailmessage'.
        if ($this->messageparam == MOD_BOOKING_MSGPARAM_SESSIONREMINDER) {
            // Rendered session description.
            $this->stringparams->sessiondescription = get_rendered_eventdescription(
                $this->optionid,
                $this->cmid,
                MOD_BOOKING_DESCRIPTION_CALENDAR
            );
        }

        // Set the correct description param.
        switch ($msgcontrparam) {
            case MOD_BOOKING_MSGCONTRPARAM_SEND_NOW:
            case MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC:
                // For display in e-mails.
                $this->descriptionparam = MOD_BOOKING_DESCRIPTION_MAIL;
                break;
            default:
                // For display on website.
                $this->descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE;
                break;
        }

        // If there are changes, let's render them.
        // We need the {changes} placeholder for change notifications.
        if (!empty($changes)) {
            $data = new bookingoption_changes($changes, $cmid);
            $this->params->changes = $output->render_bookingoption_changes($data);
        }

        // Generate the email body.
        $this->messagebody = $this->get_email_body();

        $this->messagebody = format_text($this->messagebody);

        // For adhoc task mails, we need to prepare data differently.
        if ($this->msgcontrparam == MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC) {
            $this->messagedata = $this->get_message_data_queue_adhoc();
        } else {
            $this->messagedata = $this->get_message_data_send_now();
        }

        // At the end, we set back the original language.
        force_current_language($originallanguage);
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
            'deletedtext', 'bookingchangedtext', 'pollurltext', 'pollurlteacherstext',
        ];

        if ($this->messageparam == MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE) {
            // For custom messages, we already have a message body.
            $text = $this->custommessage;
        } else if (
            isset($this->bookingsettings->mailtemplatessource) && $this->bookingsettings->mailtemplatessource == 1
            && in_array($this->messagefieldname, $mailtemplatesfieldnames)
        ) {
            // Check if global mail templates are enabled and if the field name also has a global mail template.
            // Get the mail template specified in plugin config.
            $text = get_config('booking', 'global' . $this->messagefieldname);
        } else if (
            isset($this->bookingsettings->{$this->messagefieldname})
            && $this->bookingsettings->{$this->messagefieldname} === "0"
        ) {
            /* NOTE: By entering 0 into a mail template, we can turn the specific mail reminder off.
            This is why we need the === check for the exact string of "0". */
            $text = "0";
        } else if (!empty($this->bookingsettings->{$this->messagefieldname})) {
            // If there is an instance-specific template, then use it.
            $text = $this->bookingsettings->{$this->messagefieldname};
        } else {
            // Use default message if none is specified.
            $text = get_string($this->messagefieldname . 'message', 'booking', $this->stringparams);
        }

        // NOTE: The only param that has not yet been migrated is {changes}.
        // So we  still have to keep this.
        foreach ($this->params as $name => $value) {
            if (!is_null($value)) { // Since php 8.1.
                $value = strval($value);
                $text = str_replace('{' . $name . '}', $value, $text);
            }
        }

        // We apply the default placeholders.
        $text = placeholders_info::render_text(
            $text,
            $this->optionsettings->cmid,
            $this->optionid,
            $this->userid,
            $this->installmentnr,
            $this->duedate,
            $this->price,
            $this->descriptionparam ?? MOD_BOOKING_DESCRIPTION_WEBSITE,
            $this->rulejson
        );

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

        if ($this->messageparam == MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE) {
            // For custom messages use the custom subject.
            $messagedata->subject = $this->customsubject;
        } else {
            // Else use the localized lang string for the correspondent message type.
            $messagedata->subject = get_string($this->messagefieldname . 'subject', 'booking', $this->stringparams);
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

        if ($this->messageparam == MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE) {
            // For custom messages use the custom subject.
            $messagedata->subject = $this->customsubject;
        } else {
            // Else use the localized lang string for the correspondent message type.
            $messagedata->subject = get_string($this->messagefieldname . 'subject', 'booking', $this->stringparams);
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

        if ($this->messageparam == MOD_BOOKING_MSGPARAM_CHANGE_NOTIFICATION) {
            $updated = true;
        } else {
            $updated = false;
        }

        // Add attachments if there are any.
        [$attachments, $attachname] = $this->get_attachments($updated);

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
        if (
            $this->messagebody === "0"
            // Make sure, we don't send anything, if booking option is hidden.
            || $this->optionsettings->invisible == 1
        ) {
            $this->msgcontrparam = MOD_BOOKING_MSGCONTRPARAM_DO_NOT_SEND;
        }

        // Only send if we have message data and if the user hasn't been deleted.
        // Also, do not send, if the param MOD_BOOKING_MSGCONTRPARAM_DO_NOT_SEND has been set.
        if (
            $this->msgcontrparam != MOD_BOOKING_MSGCONTRPARAM_DO_NOT_SEND
            && !empty($this->messagedata) && !$this->user->deleted
        ) {
            if ($this->msgcontrparam == MOD_BOOKING_MSGCONTRPARAM_QUEUE_ADHOC) {
                return $this->send_mail_with_adhoc_task();
            } else {
                // If the rule has sendical set then we get the ical attachment.
                // Create it in file storage and put it in the message object.
                if (!empty($this->rulesettings->actiondata) && !empty($this->rulesettings->actiondata->sendical)) {
                    $update = false;
                    if ($this->rulesettings->actiondata->sendicalcreateorcancel == 'cancel') {
                        $update = true;
                    }

                    // Pass the update param - false will create a remove calendar invite.
                    /* Todo: The system still fires an unsubscribe message.
                    I believe this is a hangover of the old non rules booking system. (danbuntu) */
                    [$attachments, $attachname] = $this->get_attachments($update);

                    if (!empty($attachments)) {
                        // Todo: this should probably be a method in the ical class.
                        // Left here to limit to number of changed files.
                        // Store the file correctly in order to be able to attach it.
                        $fs = get_file_storage();
                        $context = context_system::instance(); // Use a suitable context, such as course or module context.
                        $tempfilepath = $attachments['booking.ics'];

                        // Check if the file exists in the temp path.
                        if (file_exists($tempfilepath)) {
                            // Prepare file record in Moodle storage.
                            $filerecord = [
                                    'contextid' => $context->id,
                                    'component' => 'mod_booking', // Change to your component.
                                    'filearea' => 'message_attachments', // A custom file area for attachments.
                                    'itemid' => 0, // Item ID (0 for general use or unique identifier for the message).
                                    'filepath' => '/', // Always use '/' as the root directory.
                                    'filename' => $attachname,
                                    'userid' => $this->messagedata->userto->id,
                            ];

                            // Create or retrieve the file in Moodle's file storage.
                            $storedfile = $fs->create_file_from_pathname($filerecord, $tempfilepath);

                            // Set the file as an attachment.
                            $this->messagedata->attachment = $storedfile;
                            $this->messagedata->attachname = $attachname;
                        } else {
                            // Todo: There is possibly a better way to handle this error nicely - or remove the check entirely.
                            throw new \moodle_exception('Attachment file not found.');
                        }
                    }
                }

                // In all other cases, use message_send.
                if (message_send($this->messagedata)) {
                    if (!empty($this->rulesettings->actiondata) && !empty($this->rulesettings->actiondata->sendical)) {
                        // Tidy up the now not needed file.
                        $storedfile->delete();
                    }

                    // Use an event to log that a message has been sent.
                    $event = \mod_booking\event\message_sent::create([
                        'context' => context_system::instance(),
                        'userid' => $this->messagedata->userto->id,
                        'relateduserid' => $this->messagedata->userfrom->id,
                        'objectid' => $this->optionid ?? 0,
                        'other' => [
                            'messageparam' => $this->messageparam,
                            'subject' => $this->messagedata->subject,
                            'objectid' => $this->optionid ?? 0,
                            'message' => $this->messagedata->fullmessage ?? '',
                            // Store the full html message as this is useful if the message every needs to be replayed or audited.
                            'messagehtml' => $this->messagedata->fullmessagehtml ?? '',
                            'bookingruleid' => $this->ruleid ?? null,
                        ],
                    ]);
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
                $this->messagedata->optionid = $this->optionid;
                $sendtask->set_custom_data($this->messagedata);
                \core\task\manager::queue_adhoc_task($sendtask);
            }

            // If the setting to send a copy to the booking manger has been enabled,
            // then also send a copy to the booking manager.
            // DO NOT send copies of change notifications to booking managers.
            if (
                !empty($bookingsettings->copymail) &&
                $this->messageparam != MOD_BOOKING_MSGPARAM_CHANGE_NOTIFICATION
            ) {
                // Get booking manager from booking instance settings.
                $this->messagedata->userto = $bookingsettings->bookingmanageruser;

                if (
                    $this->messageparam == MOD_BOOKING_MSGPARAM_CONFIRMATION ||
                    $this->messageparam == MOD_BOOKING_MSGPARAM_WAITINGLIST
                ) {
                    $this->messagedata->subject = get_string(
                        $this->messagefieldname . 'subjectbookingmanager',
                        'mod_booking',
                        $this->stringparams
                    );
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
     * @param bool $updated if set to true, it will create an update ical
     * @return array [array $attachments, string $attachname]
     */
    private function get_attachments(bool $updated = false): array {
        $attachments = null;
        $attachname = '';

        if (
            $this->messageparam == MOD_BOOKING_MSGPARAM_CANCELLED_BY_PARTICIPANT
            || $this->messageparam == MOD_BOOKING_MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM
        ) {
            // Check if setting to send a cancel ical is enabled.
            if (get_config('booking', 'icalcancel')) {
                $ical = new ical($this->bookingsettings, $this->optionsettings, $this->user, $this->bookingmanager, false);
                $attachments = $ical->get_attachments(true);
                $attachname = $ical->get_name();
            }
        } else {
            // Generate ical attachments to go with the message. Check if ical attachments enabled.
            if (get_config('booking', 'attachical')) {
                $ical = new ical($this->bookingsettings, $this->optionsettings, $this->user, $this->bookingmanager, $updated);
                $attachments = $ical->get_attachments($updated);
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
     * Helper function to get the fieldname for the message type.
     * @return string the field name
     */
    private function get_message_fieldname() {

        switch ($this->messageparam) {
            case MOD_BOOKING_MSGPARAM_CONFIRMATION:
                $fieldname = 'bookedtext';
                break;
            case MOD_BOOKING_MSGPARAM_WAITINGLIST:
                $fieldname = 'waitingtext';
                break;
            case MOD_BOOKING_MSGPARAM_REMINDER_PARTICIPANT:
                $fieldname = 'notifyemail';
                break;
            case MOD_BOOKING_MSGPARAM_REMINDER_TEACHER:
                $fieldname = 'notifyemailteachers';
                break;
            case MOD_BOOKING_MSGPARAM_STATUS_CHANGED:
                $fieldname = 'statuschangetext';
                break;
            case MOD_BOOKING_MSGPARAM_CANCELLED_BY_PARTICIPANT:
                $fieldname = 'userleave';
                break;
            case MOD_BOOKING_MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM:
                $fieldname = 'deletedtext';
                break;
            case MOD_BOOKING_MSGPARAM_CHANGE_NOTIFICATION:
                $fieldname = 'bookingchangedtext';
                break;
            case MOD_BOOKING_MSGPARAM_POLLURL_PARTICIPANT:
                $fieldname = 'pollurltext';
                break;
            case MOD_BOOKING_MSGPARAM_POLLURL_TEACHER:
                $fieldname = 'pollurlteacherstext';
                break;
            case MOD_BOOKING_MSGPARAM_COMPLETED:
                $fieldname = 'activitycompletiontext';
                break;
            case MOD_BOOKING_MSGPARAM_SESSIONREMINDER:
                $fieldname = 'sessionremindermail';
                break;
            case MOD_BOOKING_MSGPARAM_REPORTREMINDER:
                $fieldname = 'reportreminder';
                break;
            case MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE:
                $fieldname = 'custommessage';
                break;
            default:
                throw new moodle_exception('ERROR: Unknown message parameter!');
        }
        return $fieldname;
    }
}
