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
 * The certifcate class handles logic related to issueing certificates.
 *
 * @package mod_booking
 * @author Magdalena Holczik
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use core_competency\competency;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\dates_handler;
use mod_booking\placeholders\placeholders\customfields;
use mod_booking\singleton_service;
use mod_booking\customfield\booking_handler;
use tool_certificate\template;
use tool_certificate\certificate as toolCertificate;
use stdClass;

/**
 * Certificate class for logic related to issueing certificates.
 */
class certificateclass {
    /**
     * Issue certificate.
     *
     * @param int $optionid
     * @param int $userid
     * @param int $timebooked
     *
     * @return int
     *
     */
    public static function issue_certificate(int $optionid, int $userid, int $timebooked = 0): int {
        global $DB;
        $id = 0;
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        if (
            !class_exists('tool_certificate\certificate')
            || !get_config('booking', 'certificateon')
        ) {
            return $id;
        }
        // Get certificate id.
        $certificateid = booking_option::get_value_of_json_by_key($optionid, 'certificate') ?? 0;

        if (empty($certificateid)) {
            return $id;
        }

        $template = template::instance($certificateid);

        // Certificate expiry date key.
        $expirydatetype = booking_option::get_value_of_json_by_key($optionid, 'expirydatetype') ?? 0;
        $expirydateabsolute = booking_option::get_value_of_json_by_key($optionid, 'expirydateabsolute') ?? 0;
        $expirydaterelative = booking_option::get_value_of_json_by_key($optionid, 'expirydaterelative') ?? 0;
        $certificateexpirydate = toolCertificate::calculate_expirydate($expirydatetype, $expirydateabsolute, $expirydaterelative);
        if (!empty($expirydatetype) && $certificateexpirydate < time()) {
            return $id;
        }
        // Create Certificate.
        $customfielddata = [];
        $customfields = booking_handler::get_customfields();
        foreach ($customfields as $customfield) {
            if (!in_array($customfield->type, ['text', 'textarea', 'textformat'])) {
                continue;
            }
            $placeholder = '{' . $customfield->shortname . '}';
            $params = [];
            $value = customfields::return_value(
                $settings->cmid,
                $settings->id,
                $userid,
                $placeholder,
                $params,
                $customfield->shortname
            );
            if (empty($value)) {
                $value = " ";
            }
            $customfielddata['cf' . $customfield->shortname] = $value;
        }
        $bookingoptionfields = [
            'bookingoptionid' => $settings->id,
            'bookingoptionname' => $settings->get_title_with_prefix(),
            'bookingoptiondescription' => clean_text(
                $settings->description,
                $format = FORMAT_HTML,
                $options = ['strip_tags' => true]
            ),
            'location' => $settings->location,
            'institution' => $settings->institution,
            'teachers' => self::return_teachers_for_certificate($settings->teachers),
            'sessions' => self::return_sessions_for_certificate($settings->sessions),
            'duration' => self::return_duration_for_certificate($settings),
            'timeawarded' => self::return_timeawarded_for_certificate($settings, $userid, $timebooked),
            'competencies' => self::return_competencies_for_certificate($settings->competencies ?? ''),
        ];

        $data = array_merge(
            $bookingoptionfields,
            $customfielddata
        );
        singleton_service::set_temp_values_for_certificates($settings->id, $userid);
        // Issue the certificate.
        $id = $template->issue_certificate(
            $userid,
            $certificateexpirydate,
            $data,
            'tool_certificate',
            empty($settings->courseid) ? null : $settings->courseid
        );
        // Get the issue and create the PDF.
        $issue = $DB->get_record('tool_certificate_issues', ['id' => $id]);
        $pdf = $template->create_issue_file($issue, false);
        singleton_service::unset_temp_values_for_certificates();

        // Get required options data for the event.
        $requiredoptionsdata = self::get_required_options_data($settings, $userid);

        // Trigger certificate issued event.
        $event = \mod_booking\event\certificate_issued::create([
            'context' => \context_module::instance($settings->cmid),
            'objectid' => $id,
            'relateduserid' => $userid,
            'other' => [
                'bookingoption_id' => $settings->id,
                'bookingoption_name' => $settings->get_title_with_prefix(),
                'required_options' => $requiredoptionsdata,
            ],
        ]);
        $event->trigger();

        return $id;
    }
    /**
     * Get required options data for the event.
     * Fetches all required booking options that need to be completed for certificate issuance.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     *
     * @return array Array of required options with their details
     *
     */
    private static function get_required_options_data(booking_option_settings $settings, int $userid): array {
        $requiredoptions = booking_option::get_value_of_json_by_key(
            $settings->id,
            'certificaterequiresotheroptions'
        ) ?? [];

        if (empty($requiredoptions)) {
            return [];
        }
        $ba1 = singleton_service::get_instance_of_booking_answers($settings);
        $iscompleted = $ba1->is_activity_completed($userid);
        $requiredoptionsdata = [];
        $requiredoptionsdata[$settings->id] = [
                'optionid' => $settings->id,
                'optionname' => $settings->get_title_with_prefix(),
                'completed' => $iscompleted,
        ];
        foreach ($requiredoptions as $requiredoptionid) {
            if (empty($requiredoptionid)) {
                continue;
            }

            $settingsotheroption = singleton_service::get_instance_of_booking_option_settings($requiredoptionid);
            $ba = singleton_service::get_instance_of_booking_answers($settingsotheroption);
            $iscompleted = $ba->is_activity_completed($userid);

            $requiredoptionsdata[$requiredoptionid] = [
                'optionid' => $requiredoptionid,
                'optionname' => $settingsotheroption->get_title_with_prefix(),
                'completed' => $iscompleted,
            ];
        }

        return $requiredoptionsdata;
    }

    /**
     * [Description for return_competency_for_certificate]
     *
     * @param string $competencies
     *
     * @return string
     *
     */
    private static function return_competencies_for_certificate(string $competencies) {

        if (empty($competencies)) {
            return '';
        }

        $competenciesarray = explode(',', $competencies);
        $collected = [];
        foreach ($competenciesarray as $competencid) {
            $competency = competency::get_record(['id' => (int) $competencid]);
            $collected[] = $competency->get('shortname');
        }
        $returnstring = implode(', ', $collected);
        return $returnstring;
    }
    /**
     * Helper function to return Teachers for certificate.
     *
     * @param array $teachers
     *
     * @return string
     *
     */
    private static function return_teachers_for_certificate(array $teachers) {
        $certificateteachers = [];
        foreach ($teachers as $teacher) {
            $certificateteachers[] = "$teacher->firstname $teacher->lastname";
        }
        return implode("<br />", $certificateteachers);
    }

    /**
     * Helper function to return Duration for certificate.
     *
     * @param object $settings
     *
     * @return string
     *
     */
    private static function return_duration_for_certificate(object $settings) {
        if (!empty($settings->sessions)) {
            $duration = 0;
            foreach ($settings->sessions as $session) {
                $duration += ($session->courseendtime - $session->coursestarttime);
            }
        } else if (
            !empty($settings->courseendtime)
            && !empty($settings->coursestarttime)
            && $settings->courseendtime > $settings->coursestarttime
        ) {
            $duration = $settings->courseendtime - $settings->coursestarttime;
        } else {
            return '';
        }
        $hours = (string)floor($duration / 3600);
        $minutes = (string)floor(($duration % 3600) / 60);
        $a = new stdClass();
        $a->hours = $hours;
        $a->minutes = $minutes;
        return get_string('durationforcertificate', 'mod_booking', $a);
    }

    /**
     * Helper function to return Sessions for certificate.
     *
     * @param array $sessions
     *
     * @return string
     *
     */
    private static function return_sessions_for_certificate(array $sessions) {
        $dates = "";
        foreach ($sessions as $session) {
            $dates .= dates_handler::prettify_optiondates_start_end(
                $session->coursestarttime,
                $session->courseendtime,
                current_language(),
                false
            ) . "<br />";
        }
        return $dates;
    }

    /**
     * Helper function to return the time the certificate was awarded
     * @param booking_option_settings $settings
     * @param int $userid
     * @param int $timebooked
     *
     * @return string
     *
     */
    private static function return_timeawarded_for_certificate(
        booking_option_settings $settings,
        int $userid,
        int $timebooked
    ) {
        if (empty($timebooked)) {
            $ba = singleton_service::get_instance_of_booking_answers($settings);
            $users = $ba->get_usersonlist();
            if (!$answer = $users[$userid] ?? false) {
                return '';
            }
            $timebooked = $answer->timebooked ?? $answer->timemodified ?? time();
        }

        // The time awarded is currently the time modified. We might change that at one point.
        return userdate($timebooked, get_string('strftimedaydate'));
    }

    /**
     * Check if all required options are completed for certificate issuance.
     * If a certificate does not require other options, it will return true.
     * If there are required options, it checks if the user has completed them all.
     * There is no check if the current option is required in another option, if so,
     * the other option will use this check on completion.
     *
     * @param booking_option_settings $settings
     * @param int $userid
     *
     * @return bool
     *
     */
    public static function all_required_options_fulfilled(booking_option_settings $settings, int $userid): bool {
        $requiredoptions = booking_option::get_value_of_json_by_key(
            $settings->id,
            'certificaterequiresotheroptions'
        ) ?? [];

        if (empty($requiredoptions)) {
            return true;
        }

        foreach ($requiredoptions as $requiredoptionid) {
            if (empty($requiredoptionid)) {
                continue;
            }
            $settingsotheroption = singleton_service::get_instance_of_booking_option_settings($requiredoptionid);
            $ba = singleton_service::get_instance_of_booking_answers($settingsotheroption);
            if (!$ba->is_activity_completed($userid)) {
                return false;
            }
        }
        return true;
    }
}
