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

namespace mod_booking\form;

use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->libdir/formslib.php");

use context;
use context_system;
use core_form\dynamic_form;
use moodle_url;
use stdClass;

/**
 * Dynamic select users form.
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @package   mod_booking
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dynamicdeputyselect extends dynamic_form {
    /**
     * {@inheritdoc}
     * @see moodleform::definition()
     */
    public function definition() {

        $mform = $this->_form;

        $options = [
            'multiple' => true,
            'noselectionstring' => '',
            'ajax' => 'mod_booking/form_users_selector',
        ];
        $mform->addElement('autocomplete', 'deputies', get_string('selectdeputy', 'booking'), [], $options);
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     *
     * This method can return scalar values or arrays that can be json-encoded, they will be passed to the caller JS.
     *
     * Submission data can be accessed as: $this->get_data()
     *
     * @return mixed
     */
    public function process_dynamic_submission() {

        $data = $this->get_data();
        $this->update_user_field($data);
        return $data;
    }


    /**
     * Load in existing data as form defaults
     *
     * Can be overridden to retrieve existing values from db by entity id and also
     * to preprocess editor and filemanager elements
     *
     * Example:
     *     $this->set_data(get_entity($this->_ajaxformdata['cmid']));
     */
    public function set_data_for_dynamic_submission(): void {
        global $USER;

        $data = new stdClass();
        if (empty($data->userid)) {
            $userid = $data->userid ?? $USER->id;
        }
        $user = singleton_service::get_instance_of_user($userid, true);
        $deputyfield = get_config('bookingextension_confirmation_supervisor', 'deputy');
        if ($deputyfield) {
            $existingdeputies = $user->profile[$deputyfield] ?? false;
        }

        if ($existingdeputies) {
            $data->deputies = [];
            $userids = explode(",", $existingdeputies);
            foreach ($userids as $uid) {
                $existingdeputy = singleton_service::get_instance_of_user($uid);
                $data->deputies[$existingdeputy->id] =
                    "$existingdeputy->firstname $existingdeputy->lastname (ID: $existingdeputy->id) $existingdeputy->email";
            }
        }
        $this->set_data($data);
    }

    /**
     * Updates a Moodle user field (standard or custom) safely.
     *
     * @param mixed $value The value to assign.
     * @return bool True on success, false on failure.
     */
    private function update_user_field($value) {
        global $DB, $USER;

        $field = get_config('bookingextension_confirmation_supervisor', 'deputy');

        $user = singleton_service::get_instance_of_user($USER->id, true);

        if (isset($user->profile[$field])) {
            foreach ($value->deputies as $i => $string) {
                // This is a little hacky as the data we set is set as string not int, we fetch the id from the string.
                if (
                    str_contains($string, "(ID:")
                    && preg_match('/\(ID:\s*(\d+)\)/', $string, $matches)
                ) {
                    $value->deputies[$i] = $matches[1];
                }
            }
            $deputies = implode(',', $value->deputies);
            profile_save_custom_fields($user->id, [$field => $deputies]);
            $this->enrol_deputies($user->profile[$field], $value->deputies);
            singleton_service::unset_instance_of_user($user->id);
        } else {
            // For the moment we only support custom user profile fields.
            return false;
        }

        return true;
    }

    /**
     * Make sure current deputies are enroled in supervisor role
     * and unenrol them from role on deletion if this was their only supervisor task.
     *
     * @param string $formerdeputiesstring
     * @param array $newdeputies
     *
     * @return void
     *
     */
    private function enrol_deputies(string $formerdeputiesstring, array $newdeputies) {
        global $DB;

        $supervisorroleid = get_config('local_taskflow', 'supervisorrole');
        if (!empty($formerdeputiesstring)) {
            $formerdeputies = explode(',', $formerdeputiesstring);

            // Find deleted values (in old but not in new).
            $deleted = array_diff($formerdeputies, $newdeputies);
            $added = array_diff($newdeputies, $formerdeputies);
        } else {
            $added = $newdeputies;
            $deleted = [];
        }

        // Assign the new deputies to the role of supervisor.
        $systemcontext = context_system::instance();
        foreach ($added as $newid) {
            role_assign($supervisorroleid, $newid, $systemcontext->id);
        }

        // For deleted deputies check if they are in the role of supervisor or deputy for any other user.
        $supervisorfield = get_config('bookingextension_confirmation_supervisor', 'supervisor');
        $deputyfield = get_config('bookingextension_confirmation_supervisor', 'deputy');

        $sql = "SELECT u.id,
                u.username,
                u.firstname,
                u.lastname
            FROM {user} u
            LEFT JOIN {user_info_data} d1
                ON d1.userid = u.id
                AND d1.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = :sv LIMIT 1)
            LEFT JOIN {user_info_data} d2
                ON d2.userid = u.id
                AND d2.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = :dp LIMIT 1)
            WHERE (d1.data LIKE :id1
                OR d2.data LIKE :id2);";

        $params = [
            'sv' => $supervisorfield,
            'dp' => $deputyfield,
        ];

        foreach ($deleted as $deleteid) {
            $string = "%$deleteid%";
            $params['id1'] = $string;
            $params['id2'] = $string;
            $users = $DB->get_records_sql($sql, $params);
            if ($users) {
                continue;
            }
            role_unassign($supervisorroleid, $deleteid, $systemcontext->id);
        }
    }

    /**
     * Returns form context
     *
     * If context depends on the form data, it is available in $this->_ajaxformdata or
     * by calling $this->optional_param()
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {

        return context_system::instance();
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * This is used in the form elements sensitive to the page url, such as Atto autosave in 'editor'
     *
     * If the form has arguments (such as 'id' of the element being edited), the URL should
     * also have respective argument.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {

        // We don't need it, as we only use it in modal.
        return new moodle_url('/');
    }

    /**
     * Validate form.
     *
     * @param stdClass $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {

        $errors = [];
        return $errors;
    }

    /**
     * Check access for dynamic submission.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('mod/booking:assigndeputies', context_system::instance());
    }

    /**
     * Returns the text to be shown as an extra description for the scope.
     *
     * This function returns an array of objects. Each object contains the properties:
     *  - 'text'
     *  - 'class'
     *  - 'link'
     *
     * Example:
     * [
     *     {
     *         'text': 'any text',
     *         'class': 'any class',
     *         'link': 'a valid link or an empty string'
     *     }
     * ]
     *
     * @return array Array of objects containing 'text', 'class', and 'link' properties.
     */
    public static function get_display_deputies_data(): array {
        // As we are listing a list of answers to be confirmed,
        // here we return a list of persons who are selected as deputies of the logged-in approver.
        global $USER;

        // Fetch deputies if the confirmation_supervisor booking extension if available.
        $classname = "\\bookingextension_confirmation_supervisor\\local\\confirmbooking";

        if (
            class_exists($classname)
            && get_config('bookingextension_confirmation_supervisor', 'confirmationsupervisorenabled')
        ) {
            $deputies = $classname::get_deputies($USER);
            if (empty($deputies)) {
                return [];
            }

            $texts = [];
            // First line as a description.
            $text = new stdClass();
            $text->text = get_string('deputiesalreadyset', 'mod_booking');
            $text->class = '';
            $text->link = '';
            $texts[] = $text;
            // Attach each user as a text object.
            foreach ($deputies as $deputyuserid) {
                $deputyuserid = (int)$deputyuserid;
                $deputy = singleton_service::get_instance_of_user($deputyuserid);
                $text = new stdClass();
                $text->text = "{$deputy->firstname} {$deputy->lastname}";
                $text->class = 'link-primary';
                $text->link = new moodle_url('/user/profile.php', ['id' => $deputy->id]);
                $texts[] = $text;
            }
            if (!empty($texts)) {
                $texts[count($texts) - 1]->last = true;
            }
            return $texts;
        }

        return [];
    }
}
