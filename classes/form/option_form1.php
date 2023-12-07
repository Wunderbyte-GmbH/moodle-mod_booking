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
 * Option form
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;

use dml_exception;
use coding_exception;
use core_form\dynamic_form;
use mod_booking\output\eventslist;
use context;
use context_module;
use context_system;

defined('MOODLE_INTERNAL') || die();
require_once("$CFG->libdir/formslib.php");

use mod_booking\booking_option;
use mod_booking\customfield\booking_handler;
use mod_booking\singleton_service;
use local_entities\entitiesrelation_handler;
use local_entities\local\entities\entitydate;
use mod_booking\dates;
use mod_booking\option\fields_info;
use moodle_exception;
use moodle_url;
use required_capability_exception;
use stdClass;

class option_form1 extends dynamic_form {

    /** @var bool $formmode 'simple' or 'expert' */
    public $formmode = null;

    public function definition() {
        global $DB, $PAGE, $OUTPUT;

        /* At first get the option form configuration from DB.
        Unfortunately, we need this, because hideIf does not work with
        editors, headers and html elements. */
        $optionformconfig = [];
        if ($optionformconfigrecords = $DB->get_records('booking_optionformconfig')) {
            foreach ($optionformconfigrecords as $optionformconfigrecord) {
                $optionformconfig[$optionformconfigrecord->elementname] = $optionformconfigrecord->active;
            }
        }

        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        // We need context on this.
        $context = context_module::instance($formdata['cmid']);
        $formdata['context'] = $context;
        $optionid = $formdata['optionid'];

        // Get the form mode, which can be 'simple' or 'expert'.
        if (isset($formdata['formmode'])) {
            // Formmode can also be set via custom data.
            // Currently we only need this for the optionformconfig...
            // ...which needs to be set to 'expert', so it shows all checkboxes.
            $this->formmode = $formdata['formmode'];
        } else {
            // Normal case: we get formmode from user preferences.
            $this->formmode = get_user_preferences('optionform_mode');
        }

        if (empty($this->formmode)) {
            // Default: Simple mode.
            $this->formmode = 'simple';
        }

        // We add the formmode to the optionformconfig.
        $optionformconfig['formmode'] = $this->formmode;

        $mform = & $this->_form;

        $mform->addElement('hidden', 'returnurl');
        $mform->setType('returnurl', PARAM_LOCALURL);

        $mform->addElement('hidden', 'scrollpos');
        $mform->setType('scrollpos', PARAM_INT);

        // Add all available fields in the right order.
        fields_info::instance_form_definition($mform, $formdata, $optionformconfig);

        // Hide all elements which have been removed in the option form config.
        // Only do this, if the form mode is set to 'simple'. In expert mode we do not hide anything.
        if ($this->formmode == 'simple' && $cfgelements = $DB->get_records('booking_optionformconfig')) {
            foreach ($cfgelements as $cfgelement) {
                if ($cfgelement->active == 0) {
                    $mform->addElement('hidden', 'cfg_' . $cfgelement->elementname, (int) $cfgelement->active);
                    $mform->setType('cfg_' . $cfgelement->elementname, PARAM_INT);
                    $mform->hideIf($cfgelement->elementname, 'cfg_' . $cfgelement->elementname, 'eq', 0);
                }
            }
        }

        // Buttons.
        $buttonarray = [];
        $buttonarray[] = &$mform->createElement('submit', 'submitbutton',
                get_string('submitandgoback', 'mod_booking'));
        $buttonarray[] = &$mform->createElement("submit", 'submitandadd',
                get_string('submitandadd', 'mod_booking'));
        $buttonarray[] = &$mform->createElement("submit", 'submitandstay',
            get_string('submitandstay', 'mod_booking'));
        $buttonarray[] = &$mform->createElement('cancel');
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->closeHeaderBefore('buttonar');

        $data = new eventslist(
            $optionid,
            ['\mod_booking\event\bookingoption_updated']
        );

        $html = $OUTPUT->render_from_template('mod_booking/eventslist', $data);
        $mform->addElement('static', 'eventslist', '', $html);

        $PAGE->requires->js_call_amd('mod_booking/optionstemplateselect', 'init');
    }

    protected function data_preprocessing(&$defaultvalues) {

        // Custom lang strings.
        if (!isset($defaultvalues['descriptionformat'])) {
            $defaultvalues['descriptionformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['description'])) {
            $defaultvalues['description'] = '';
        }

        if (!isset($defaultvalues['notificationtextformat'])) {
            $defaultvalues['notificationtextformat'] = FORMAT_HTML;
        }

        if (!isset($defaultvalues['notificationtext'])) {
            $defaultvalues['notificationtext'] = '';
        }

        if (!isset($defaultvalues['beforebookedtext'])) {
            $defaultvalues['beforebookedtext'] = '';
        }

        if (!isset($defaultvalues['beforecompletedtext'])) {
            $defaultvalues['beforecompletedtext'] = '';
        }

        if (!isset($defaultvalues['aftercompletedtext'])) {
            $defaultvalues['aftercompletedtext'] = '';
        }
    }

    /**
     * Validation function.
     * @param array $data
     * @param array $files
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        fields_info::validation($data, $files, $errors);

        return $errors;
    }

    /**
     *
     * Show the booking information to edit
     *
     * @param bool $entity
     */
    public function get_customfieldcategories(booking_handler $handler) {
        $categories = $handler->get_categories_with_fields();
        foreach ($categories as $category) {
            $name = $category->get('name');
            // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
            // Mabye use later: $id = $category->get('id'); END.
            $categorynames[$name] = $name;
        }
        if (count($categorynames) == 0) {
            return ['not category yet'];
        }

        return $categorynames;
    }

    /**
     * Helper function to explode strings to array of starttime & endtime.
     *
     * @param [type] $array
     * @return array
     */
    private static function return_timestamps($array):array {

        $returnarray = [];
        foreach ($array as $date) {
            list($startime, $endtime) = explode('-', $date);
            $returnarray[] = [
                'starttime' => $startime,
                'endtime' => $endtime,
            ];
        }
        return $returnarray;
    }

    /**
     * This function creates the entitydate instances in an array under the datestobook key.
     * The entitiesrelation handlers we use to validate and save expects a certain structure.
     *
     * @param stdClass $fromform
     * @return void
     */
    private static function order_all_dates_to_book_in_form(stdClass &$fromform) {
        // dates_handler::add_values_from_post_to_form($fromform);

        // For the form validation, we need to pass the values to book in a special form.
        // We only need those timestamps which are new.
        // But it might be advisable to also check the key stillexistingdates in the future.
        $datestobook = self::return_timestamps(array_merge($fromform->newoptiondates, $fromform->stillexistingdates));

        $fromform->datestobook = [];

        $link = new moodle_url('/mod/booking/view.php', [
            'optionid' => $fromform->optionid,
            'id' => booking_option::get_cmid_from_optionid($fromform->optionid),
            'whichview' => 'showonlyone',
        ]);

        foreach ($datestobook as $date) {

            $fromform->datestobook[] = new entitydate(
                $fromform->optionid ?? 0,
                'mod_booking',
                'optiondate',
                $fromform->text,
                $date['starttime'],
                $date['endtime'],
                1,
                $link);
        }

        // If there are no date to book (no optiondates)...
        // ... we need to take into account the single dates.
        if ((count($fromform->datestobook) < 1)
            && !empty($fromform->coursestarttime
            && !empty($fromform->courseendtime))) {

            $fromform->datestobook[] = new entitydate(
                $fromform->optionid ?? 0,
                'mod_booking',
                'optiondate',
                $fromform->text,
                $fromform->coursestarttime,
                $fromform->courseendtime,
                1,
                $link);
        }
    }

    /**
     * Definition after data.
     * @return void
     * @throws coding_exception
     */
    public function definition_after_data() {

        $mform = $this->_form;
        $formdata = $this->_customdata ?? $this->_ajaxformdata;

        fields_info::definition_after_data($mform, $formdata);
    }

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        $cmid = $this->_ajaxformdata['cmid'];
        return context_module::instance($cmid);
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {

        $context = $this->get_context_for_dynamic_submission();

        if (!has_capability('mod/booking:addeditownoption', $context)
            && !has_capability('mod/booking:updatebooking', $context)) {
                throw new required_capability_exception($context, '', 'cant access edit form', '');
        }
    }


    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {

        $data = (object)$this->_ajaxformdata ?? $this->_customdata;

        fields_info::set_data($data);

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {

        // Get data from form.
        $data = $this->get_data();

        // Pass data to update.
        $context = $this->get_context_for_dynamic_submission();

        booking_option::update($data, $context);

        return $data;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/editoption.php');
    }
}
