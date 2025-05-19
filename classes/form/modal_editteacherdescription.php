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
 * Moodle form for dynamic holidays.
 *
 * @package   mod_booking
 * @copyright 2021 Wunderbyte GmbH {@link http://www.wunderbyte.at}
 * @author    Bernhard Fischer
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\form;
use context_user;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use cache_helper;
use context;
use context_system;
use core_form\dynamic_form;
use html_writer;
use moodle_url;
use stdClass;

/**
 * Add holidays form.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Bernhard Fischer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class modal_editteacherdescription extends dynamic_form {

    /**
     * Get context for dynamic submission.
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Check access for dynamic submission.
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:editteacherdescription', context_system::instance());
    }

    /**
     * Set data for dynamic submission.
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB, $CFG;

        $formdata = $this->_ajaxformdata;

        $data = (object)$formdata;

        $record = $DB->get_record('user', ['id' => $data->teacherid]);

        $data->description = $record->description;
        $data->descriptionformat = $record->descriptionformat;

        $context = context_user::instance($formdata['teacherid'], MUST_EXIST);

        $editoroptions = [
            'maxfiles'   => EDITOR_UNLIMITED_FILES,
            'maxbytes'   => $CFG->maxbytes,
            'trusttext'  => false,
            'forcehttps' => false,
            'context'    => $context,
        ];

        $data = file_prepare_standard_editor(
            $data,
            'description',
            $editoroptions,
            $context,
            'user',
            'profile',
            0
        );

        $this->set_data($data);
    }

    /**
     * Process dynamic submission.
     * @return stdClass|null
     */
    public function process_dynamic_submission(): stdClass {
        global $DB;

        $data = $this->get_data();

        $context = context_user::instance($data->teacherid, MUST_EXIST);

        $data = file_postupdate_standard_editor(
            $data,
            'description',
            [
                'maxfiles' => EDITOR_UNLIMITED_FILES,
                'subdirs' => true,
                'context' => $context,
            ],
            $context,
            'mod_booking',
            'description',
            $data->teacherid,
        );

        $user = $DB->get_record('user', ['id' => $data->teacherid], '*', MUST_EXIST);
        $user->description = $data->description;
        $DB->update_record('user', $user);

        // We need to purge booking option settings caches.
        cache_helper::purge_by_event('setbackoptionsettings');

        return $data;
    }

    /**
     * Form definition.
     * @return void
     */
    public function definition(): void {
        global $DB;

        $mform = $this->_form;

        $mform->addElement('hidden', 'teacherid');

        $mform->addElement('editor', 'description_editor', get_string('teacherdescription', 'mod_booking'), ['rows' => 10]);
        $mform->setType('description', PARAM_CLEANHTML);
    }

    /**
     * Server-side form validation.
     * @param array $data
     * @param array $files
     * @return array $errors
     */
    public function validation($data, $files): array {
        $errors = [];

        return $errors;
    }

    /**
     * Get page URL for dynamic submission.
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/teacher.php');
    }
}
