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
 * Modal dynamic form to send a custom message to selected booked users.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use cache_helper;
use coding_exception;
use context;
use context_module;
use context_system;
use context_user;
use core_form\dynamic_form;
use Exception;
use mod_booking\event\custom_bulk_message_sent;
use mod_booking\event\custom_message_sent;
use mod_booking\message_controller;
use mod_booking\singleton_service;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

/**
 * Modal dynamic form to send a custom message to selected booked users in report2.php.
 *
 * The recipient autocomplete lists all users booked on the current option.
 * If checkedids are passed, their users are preselected; otherwise the recipient
 * selection opens empty and users can be added manually before sending.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_send_custom_message extends dynamic_form {
    /**
     * Get all booked users for a booking option as autocomplete options.
     *
     * @param int $optionid Booking option ID.
     * @return array<int, string>
     */
    private function get_possible_recipients_for_custom_message(int $optionid): array {
        global $DB;

        if (empty($optionid)) {
            return [];
        }

        $records = $DB->get_records_sql(
            "SELECT DISTINCT u.id, u.firstname, u.lastname
               FROM {booking_answers} ba
               JOIN {user} u ON u.id = ba.userid
              WHERE ba.optionid = :optionid
                AND ba.waitinglist = :statusbooked
              ORDER BY u.lastname ASC, u.firstname ASC",
            [
                'optionid' => $optionid,
                'statusbooked' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]
        );

        $options = [];
        foreach ($records as $record) {
            $name = trim($record->firstname . ' ' . $record->lastname);
            $options[(int)$record->id] = $name . ' (' . (int)$record->id . ')';
        }

        return $options;
    }

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $submitdata = $this->_ajaxformdata;

        $mform->addElement('hidden', 'cmid', $submitdata['cmid'] ?? 0);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $submitdata['optionid'] ?? 0);
        $mform->setType('optionid', PARAM_INT);

        $mform->addElement('hidden', 'checkedids', $submitdata['checkedids'] ?? '');
        $mform->setType('checkedids', PARAM_TEXT);

        $possiblerecipients = $this->get_possible_recipients_for_custom_message((int)($submitdata['optionid'] ?? 0));
        $autocompleteoptions = [
            'multiple' => true,
            'tags' => false,
        ];
        $mform->addElement(
            'autocomplete',
            'selecteduserids',
            get_string('custommessagerecipients', 'mod_booking'),
            $possiblerecipients,
            $autocompleteoptions
        );
        $mform->setType('selecteduserids', PARAM_INT);
        $mform->addRule('selecteduserids', null, 'required', null, 'client');
        $mform->addHelpButton('selecteduserids', 'custommessagerecipients', 'mod_booking');

        $mform->addElement(
            'text',
            'subject',
            get_string('subject'),
            ['size' => '64']
        );
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', null, 'required', null, 'client');

        $mform->addElement('editor', 'message', get_string('message', 'message'));
        $mform->setType('message', PARAM_RAW);
        $mform->addRule('message', null, 'required', null, 'client');

        $mform->addElement(
            'filepicker',
            'attachment',
            get_string('custommessageattachment', 'mod_booking'),
            null,
            ['maxbytes' => get_max_upload_file_size(), 'accepted_types' => '*']
        );
        $mform->addHelpButton('attachment', 'custommessageattachment', 'mod_booking');
    }

    /**
     * Validation.
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = [];

        if (empty($data['selecteduserids']) || !is_array($data['selecteduserids'])) {
            $errors['selecteduserids'] = get_string('required');
        }

        return $errors;
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:communicate', $this->get_context_for_dynamic_submission());
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $data = (object) $this->_ajaxformdata;
        if (empty($data->selecteduserids) || !is_array($data->selecteduserids)) {
            $rawids = array_filter(array_map('intval', explode(',', $data->checkedids ?? '')));
            $data->selecteduserids = [];

            if (!empty($rawids)) {
                global $DB;
                [$insql, $inparams] = $DB->get_in_or_equal($rawids, SQL_PARAMS_NAMED);
                $selected = $DB->get_fieldset_select('booking_answers', 'userid', "id $insql", $inparams);
                $data->selecteduserids = array_values(array_unique(array_map('intval', $selected)));
            }
        }
        // Initialise the filepicker with a fresh draft item so the upload widget renders correctly.
        $data->attachment = file_get_unused_draft_itemid();
        $this->set_data($data);
    }

    /**
     * Process dynamic submission and send messages to selected recipients.
     * @return stdClass|null
     */
    public function process_dynamic_submission() {
        global $DB, $USER;

        $data = $this->get_data();

        $cmid = (int) $data->cmid;
        $optionid = (int) $data->optionid;
        $subject = $data->subject ?? '';
        $messagetext = $data->message['text'] ?? '';

        $alloweduserids = array_keys($this->get_possible_recipients_for_custom_message($optionid));

        $selecteduserids = [];
        if (!empty($data->selecteduserids) && is_array($data->selecteduserids)) {
            $selecteduserids = array_values(array_filter(array_map('intval', $data->selecteduserids)));
        }

        $userids = empty($selecteduserids)
            ? $alloweduserids
            : array_values(array_intersect($alloweduserids, $selecteduserids));

        if (empty($userids)) {
            return $data;
        }

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookingid = $settings->bookingid;

        // Read the uploaded draft file (if any) into a temp path once before sending.
        $tempfilepath = '';
        $attachmentfilename = '';
        $draftitemid = (int)($data->attachment ?? 0);
        if (!empty($draftitemid)) {
            $fs = get_file_storage();
            $usercontext = context_user::instance($USER->id);
            $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
            if (!empty($draftfiles)) {
                $uploadedfile = reset($draftfiles);
                $attachmentfilename = $uploadedfile->get_filename();
                $tempdir = make_request_directory();
                $tempfilepath = $tempdir . DIRECTORY_SEPARATOR . $attachmentfilename;
                $uploadedfile->copy_content_to($tempfilepath);
                $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);
            }
        }

        foreach ($userids as $currentuserid) {
            try {
                $messagecontroller = new message_controller(
                    MOD_BOOKING_MSGCONTRPARAM_SEND_NOW,
                    MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE,
                    $cmid,
                    $optionid,
                    $currentuserid,
                    $bookingid,
                    null,
                    null,
                    $subject,
                    $messagetext
                );
                if (!empty($tempfilepath)) {
                    $messagecontroller->set_custom_attachment($tempfilepath, $attachmentfilename);
                }
                $messagecontroller->send_or_queue();
            } catch (Exception $e) {
                continue;
            }

            $event = custom_message_sent::create([
                'context' => context_system::instance(),
                'objectid' => $optionid,
                'userid' => $USER->id,
                'relateduserid' => $currentuserid,
                'other' => [
                    'cmid' => $cmid,
                    'optionid' => $optionid,
                    'bookingid' => $bookingid,
                    'subject' => $subject,
                    'message' => $messagetext,
                    'objectid' => $optionid,
                ],
            ]);
            $event->trigger();
            cache_helper::purge_by_event('setbackeventlogtable');
        }

        // Fire bulk event if at least 75% of booked users and at least 3 users.
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $bookedusers = $answers->get_usersonlist();
        if (!empty($userids) && !empty($bookedusers)) {
            $countselected = count($userids);
            $countbooked = count($bookedusers);
            if ($countselected >= 3 && ($countselected / $countbooked) >= 0.75) {
                $event = custom_bulk_message_sent::create([
                    'context' => context_system::instance(),
                    'objectid' => $optionid,
                    'userid' => $USER->id,
                    'relateduserid' => 0,
                    'other' => [
                        'cmid' => $cmid,
                        'optionid' => $optionid,
                        'bookingid' => $bookingid,
                        'subject' => $subject,
                        'message' => $messagetext,
                        'objectid' => $optionid,
                    ],
                ]);
                $event->trigger();
                cache_helper::purge_by_event('setbackeventlogtable');
            }
        }

        // Build success feedback message with user full names.
        $userobjects = $DB->get_records_list('user', 'id', $userids, '', 'id, firstname, lastname');
        $namelist = implode(', ', array_map(
            fn($u) => trim($u->firstname . ' ' . $u->lastname),
            $userobjects
        ));
        $data->message = get_string('custommessagessentto', 'mod_booking', $namelist);
        $data->success = 1;

        return $data;
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = (int) ($this->_ajaxformdata['cmid'] ?? 0);

        if (empty($cmid)) {
            return context_system::instance();
        }

        return context_module::instance($cmid);
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/report2.php', [
            'optionid' => $this->_ajaxformdata['optionid'] ?? 0,
        ]);
    }
}
