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
 * Modal dynamic form to configure and download the sign-in sheet in report2.php.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use context;
use context_module;
use core_form\dynamic_form;
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\singleton_service;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Modal dynamic form to configure and download the sign-in sheet in report2.php.
 *
 * This is the modern replacement for the inline JS form on report.php
 * (see mod_booking\output\signin_downloadform). It offers exactly the same
 * settings and hands the download over to the existing endpoint on report.php
 * (action=downloadsigninsheet / downloadsigninsheethtml), so the generated
 * sign-in sheet is identical on both pages.
 *
 * The form opens with the effective settings of the booking option (option
 * JSON, falling back to instance / plugin settings, see signinsheet_config)
 * and persists the submitted settings in the option JSON, so the quick
 * download button can reuse them without configuring each time.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_signinsheet_download extends dynamic_form {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $submitdata = $this->_ajaxformdata;

        $cmid = (int)($submitdata['cmid'] ?? 0);
        $optionid = (int)($submitdata['optionid'] ?? 0);

        $mform->addElement('hidden', 'cmid', $cmid);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'optionid', $optionid);
        $mform->setType('optionid', PARAM_INT);

        $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $htmlmode = signinsheet_config::is_htmlmode();

        // Effective settings for this option (option JSON -> instance -> plugin config).
        $config = signinsheet_config::for_option($optionid);
        if ($htmlmode && (int)$config['pdfsessions'] === -1) {
            // The choice "Add date manually" has no effect in HTML template mode and is not offered there.
            $config['pdfsessions'] = -2;
        }

        // The same session lists as in the old form on report.php.
        $sessionsdatetime = [];
        $sessionsdateonly = [];
        if (!empty($optionsettings->sessions)) {
            foreach ($optionsettings->sessions as $session) {
                $sessionsdatetime[(int)$session->id] =
                    userdate($session->coursestarttime, get_string('strftimedatetime', 'langconfig')) . ' - ' .
                    userdate($session->courseendtime, get_string('strftimedatetime', 'langconfig'));
                $sessionsdateonly[(int)$session->id] =
                    userdate($session->coursestarttime, get_string('strftimedate', 'langconfig'));
            }
        }

        $mform->addElement('select', 'orientation', get_string('pdforientation', 'mod_booking'), [
            'P' => get_string('pdfportrait', 'mod_booking'),
            'L' => get_string('pdflandscape', 'mod_booking'),
        ]);
        $mform->setDefault('orientation', $config['orientation']);

        $mform->addElement('select', 'orderby', get_string('sortby', 'mod_booking'), [
            'lastname' => get_string('sortbylastname', 'grades'),
            'firstname' => get_string('sortbyfirstname', 'grades'),
        ]);
        $mform->setDefault('orderby', $config['orderby']);

        if (!$htmlmode) {
            $emptyrowsoptions = array_combine(range(0, 10), range(0, 10)) + [20 => 20, 40 => 40, 80 => 80];
            $mform->addElement(
                'select',
                'addemptyrows',
                get_string('signinaddemptyrows', 'mod_booking'),
                $emptyrowsoptions
            );
            $mform->setDefault('addemptyrows', (int)$config['addemptyrows']);
        }

        $mform->addElement('select', 'pdftitle', get_string('choosepdftitle', 'mod_booking'), [
            1 => format_string($bookingsettings->name) . ': ' . format_string($optionsettings->get_title_with_prefix()),
            2 => format_string($optionsettings->get_title_with_prefix()),
            3 => format_string($bookingsettings->name),
        ]);
        $mform->setDefault('pdftitle', (int)$config['pdftitle']);

        $mform->addElement(
            'select',
            'pdfsessions',
            get_string('signinonesession', 'mod_booking'),
            signinsheet_config::pdfsessions_choices($sessionsdatetime)
        );
        $mform->setDefault('pdfsessions', (int)$config['pdfsessions']);

        if (!empty($optionsettings->teachers)) {
            $mform->addElement('advcheckbox', 'includeteachers', get_string('includeteachers', 'mod_booking'));
            $mform->setDefault('includeteachers', empty($config['includeteachers']) ? 0 : 1);
        }

        $extrasessioncolsoptions = [
            -1 => get_string('none'),
            0 => get_string('all'),
        ] + $sessionsdateonly;
        $mform->addElement(
            'select',
            'signinextrasessioncols',
            get_string('signinextrasessioncols', 'mod_booking'),
            $extrasessioncolsoptions
        );
        $mform->setDefault('signinextrasessioncols', (int)$config['signinextrasessioncols']);

        if ($htmlmode) {
            $mform->addElement('select', 'saveasformat', get_string('signinformat', 'mod_booking'), [
                'pdf' => 'PDF',
                'word' => 'Word',
            ]);
            $mform->setDefault('saveasformat', $config['saveasformat']);
        }
    }

    /**
     * Check access for dynamic submission.
     *
     * Mirrors the access check for the sign-in sheet on report.php.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        $context = $this->get_context_for_dynamic_submission();
        $optionid = (int)($this->_ajaxformdata['optionid'] ?? 0);

        $isteacher = booking_check_if_teacher($optionid);
        if (!($isteacher || has_capability('mod/booking:viewreports', $context))) {
            require_capability('mod/booking:readresponses', $context);
        }
    }

    /**
     * Set data for dynamic submission.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $this->set_data([
            'cmid' => (int)($this->_ajaxformdata['cmid'] ?? 0),
            'optionid' => (int)($this->_ajaxformdata['optionid'] ?? 0),
        ]);
    }

    /**
     * Process dynamic submission.
     *
     * Persists the submitted settings in the JSON of the booking option (so
     * the quick download button and the next opening of this modal reuse
     * them) and returns the download URL of the existing sign-in sheet
     * endpoint on report.php, which the caller (amd/src/signinsheetmodal.js)
     * then navigates to, triggering the file download.
     *
     * @return array
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();
        $cmid = (int)$data->cmid;
        $optionid = (int)$data->optionid;

        // Merge the submitted settings over the currently effective ones, so
        // fields the current mode does not show (e.g. addemptyrows in HTML
        // template mode) keep their values.
        $config = signinsheet_config::for_option($optionid);
        foreach (array_keys($config) as $key) {
            if (isset($data->$key)) {
                $config[$key] = $data->$key;
            }
        }

        signinsheet_config::save_for_option($optionid, $config);

        $url = signinsheet_config::download_url($cmid, $optionid, $config);

        return [
            'success' => 1,
            'downloadurl' => $url->out(false),
        ];
    }

    /**
     * Get context for dynamic submission.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = (int)($this->_ajaxformdata['cmid'] ?? 0);

        return context_module::instance($cmid);
    }

    /**
     * Get page URL for dynamic submission.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/report2.php', [
            'optionid' => (int)($this->_ajaxformdata['optionid'] ?? 0),
        ]);
    }
}
