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
 * Price class.
 *
 * @package   mod_booking
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author    Mahdi Poustini
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\local\respondapi\handlers;

use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\local\respondapi\entities\person;
use mod_booking\local\respondapi\providers\interfaces\respondapi_provider_interface;
use mod_booking\local\respondapi\providers\marmaraapi_provider;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;


/**
 * Class respondapi_handler
 *
 * Handles business logic and flow control for external response API operations.
 */
class respondapi_handler {
    /** @var int $optionid */
    public int $optionid = 0;

    /** @var respondapi_provider_interface */
    private respondapi_provider_interface $provider;

    /**
     * Constructor.
     *
     * @param respondapi_provider_interface|null $provider Optional. If not provided, Marmara will be used.
     */
    public function __construct(int $optionid = 0, ?respondapi_provider_interface $provider = null) {
        $this->optionid = $optionid;
        $this->provider = $provider ?? new marmaraapi_provider();
    }

    /**
     * Create a new keyword in the external API.
     *
     * @param string $name The keyword name.
     * @param string|null $comment Optional description.
     * @return int|null Keyword ID.
     */
    public function get_new_keyword(int $parentkyword, string $name, ?string $comment = null): ?int {
        return $this->provider->sync_keyword($name, null, $comment, $parentkyword);
    }

    /**
     * Update an existing keyword in the external API.
     *
     * @param int $id Existing keyword ID.
     * @param string $name Updated keyword name.
     * @param string|null $comment Updated description.
     * @return int|null Keyword ID.
     */
    public function update_keyword(int $id, string $name, ?string $comment = null): ?int {
        return $this->provider->sync_keyword($name, $id, $comment);
    }

    /**
     * Adds a new person to the external system and assigns them to the configured keyword (criteria).
     *
     * Uses the current booking option's `marmaracriteriaid` to determine which keyword to assign.
     * If the external API returns a valid person ID, the operation is considered successful.
     * If not, the user should be queued for later retry using an adhoc task (not yet implemented).
     *
     * @param int $userid The Moodle user ID to sync and assign.
     * @return void
     */
    public function add_person(int $userid): void {
        // Call import person API.
        global $SITE;
        $enablemarmarasync = booking_option::get_value_of_json_by_key($this->optionid, 'enablemarmarasync') ?? 0;
        if (!$enablemarmarasync) {
            return;
        }
        $criteriaid = booking_option::get_value_of_json_by_key($this->optionid, 'marmaracriteriaid');
        $user = singleton_service::get_instance_of_user($userid);

        $source = $SITE->fullname;
        $person = new person($user->firstname, $user->lastname, $user->email);
        $personid = $this->provider->sync_person($source, $person->to_array(), [$criteriaid]);
        // If $personid is an integer, the operation was successful.
        // Otherwise, the sync failed and the user should be queued for retry.
        if (!is_int($personid)) {
            // TODO: MDL-0 If failed add it to Adhock task to be sent later.
            return;
        }
    }

    /**
     * Removes a person from the keyword (criteria) in the external system.
     *
     * Sends a request to the external API to unassign the person from the booking option's keyword
     * (retrieved via `marmaracriteriaid`). If the API call succeeds, the operation is complete.
     * If it fails, the user should be queued for retry using an adhoc task (not yet implemented).
     *
     * @param int $userid The Moodle user ID to unassign from the keyword.
     * @return void
     */
    public function remove_person(int $userid): void {
        // Call import person API.
        global $SITE;
        $enablemarmarasync = booking_option::get_value_of_json_by_key($this->optionid, 'enablemarmarasync') ?? 0;
        if (!$enablemarmarasync) {
            return;
        }
        $criteriaid = booking_option::get_value_of_json_by_key($this->optionid, 'marmaracriteriaid');
        $user = singleton_service::get_instance_of_user($userid);

        $source = $SITE->fullname;
        $person = new person($user->firstname, $user->lastname, $user->email);
        $personid = $this->provider->sync_person($source, $person->to_array(), [], [$criteriaid]);
        // If $personid is an integer, the operation was successful.
        // Otherwise, the sync failed and the user should be queued for retry.
        if (!is_int($personid)) {
            // TODO: MDL-0 If failed add it to Adhock task to be sent later.
            return;
        }
    }

    /**
     * Retrieves a list of parent keywords from the external system matching a given query,
     * and formats them for use in Moodle autocomplete selectors.
     *
     * The method always includes the configured root keyword (from plugin settings),
     * and appends all matching results returned from the external API.
     * Each keyword is encoded and returned in a format compatible with the frontend selector,
     * using a composite ID string: <id>-<base64_encoded_name>.
     *
     * If more than 100 results are found, a warning is returned and the list is empty
     * to avoid overloading the UI.
     *
     * @param string $query The user-entered search string for filtering keywords.
     * @return array Associative array with:
     *     - 'warnings' (string): A warning message if too many results are returned.
     *     - 'list' (array): A list of formatted keyword objects (id and name).
     */
    public function get_parent_kewords(string $query) {
        $records = $this->provider->get_keywords($query);

        // Push always root category.
        $rootkeywordid = get_config('booking', 'marmara_keywordparentid');
        $rootkeywordname = get_config('booking', 'marmara_keywordparentname');
        $rootkeyworditem = [
            'id' => $rootkeywordid,
            'name' => base64_encode($rootkeywordname),
        ];
        $rootcategory = (object)[
            'id' => implode('-', $rootkeyworditem),
            'name' => "(ID: $rootkeywordid) $rootkeywordname",
        ];

        $list = [$rootkeywordid => $rootcategory];

        $count = 0;

        foreach ($records as $record) {
            $keyworditem = [
                $record->id,
                base64_encode($record->name),
            ];

            $keyword = (object)[
                'id' => implode('-', $keyworditem),
                'name' => "(ID: $record->id) $record->name",
            ];

            $count++;
            $list[$record->id] = $keyword;
        }

        return [
                'warnings' => count($list) > 100 ? get_string('toomanyuserstoshow', 'core', '> 100') : '',
                'list' => count($list) > 100 ? [] : $list,
        ];
    }

    /**
     * Add form fields to be passed on mform.
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function instance_form_definition(MoodleQuickForm &$mform) {
        global $OUTPUT;

        // Checkbox to enable sync.
        $mform->addElement('advcheckbox', 'enablemarmarasync', get_string('marmara:sync', 'mod_booking'));
        $mform->setType('enablemarmarasync', PARAM_INT);
        $mform->setDefault('enablemarmarasync', get_config('booking', 'marmara_defaultsync'));

        // The criteria ID field is shown only when sync is enabled and ID is not empty.
        $mform->addElement('text', 'marmaracriteriaid', get_string('marmara:keywordid', 'mod_booking'));
        $mform->setType('marmaracriteriaid', PARAM_INT);
        $mform->freeze('marmaracriteriaid'); // Always disabled.
        $mform->hideIf('marmaracriteriaid', 'enablemarmarasync', 'noteq', 1);
        $mform->addHelpButton('marmaracriteriaid', 'marmara:keywordid', 'mod_booking');

        $mform->addElement('static', 'marmaracriteriaidinfo', '', get_string('marmara:keywordidinfo', 'mod_booking'));
        $mform->hideIf('marmaracriteriaidinfo', 'enablemarmarasync', 'noteq', 1);
        $mform->hideIf('marmaracriteriaidinfo', 'marmaracriteriaid', 'noteq', '');

        // Parent keyword.
        $list = [-1];
        // We need to preload list to not only have the id, but the rendered values.
        if ($this->optionid !== 0) {
            $parentkeyword = booking_option::get_value_of_json_by_key($this->optionid, 'selectparentkeyword') ?? 0;
            $parendkeywordname = $this->extract_name_from_id($parentkeyword);
            $list[$parentkeyword] = $parendkeywordname;
        } else {
            $parentkeyword = $this->create_parentkeyword_from_config();
        }

        $thisclass = $this;
        $options = [
            'tags' => false,
            'multiple' => false,
            'noselectionstring' => '',
            'ajax' => 'mod_booking/parentkeyword_selector',
            'valuehtmlcallback' => function ($value) use ($thisclass) {
                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }
                return get_string('marmara:keyworddisplay', 'mod_booking', (object)[
                    'id' => $thisclass->extract_idnumber_from_id($value),
                    'name' => $thisclass->extract_name_from_id($value),
                ]);
            },
        ];


        $mform->addElement(
            'autocomplete',
            'selectparentkeywordid',
            get_string('marmara:selectparentkeyword', 'mod_booking'),
            $list,
            $options
        );
        $mform->setDefault('selectparentkeywordid', $parentkeyword ?? '');

        $mform->addHelpButton('selectparentkeywordid', 'marmara:selectparentkeyword', 'mod_booking');
    }

    /**
     * This function interprets the value from the form and, if useful...
     * ... relays it to the new option class for saving or updating.
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param ?mixed $returnvalue
     * @return array
     */
    public function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {
        // Check if checkbox is ticked.
        // Check if id is empty.
        // If it's ticked AND id is empty, contact marmara server.
        // Save the return value.
        if (!get_config('booking', 'marmara_enabled')) {
            return [];
        }

        // Save enablemarmarasync flag into JSON.
        booking_option::add_data_to_json($newoption, 'enablemarmarasync', $formdata->enablemarmarasync ?? 0);

        // If syncing is enabled but ID is missing, call API to fetch it.
        if (
            empty($formdata->marmaracriteriaid)
            && !empty($formdata->enablemarmarasync)
            && !empty($formdata->selectparentkeywordid)
        ) {
            $settings = singleton_service::get_instance_of_booking_option_settings($formdata->id);
            $newkeywordname = $settings->get_title_with_prefix() ?: $newoption->text;
            $newkeyworddescription = $formdata->description['text'];
            $newkeywordparentid = $this->extract_idnumber_from_id($formdata->selectparentkeywordid);
            $fetchedcriteriaid = $this->get_new_keyword(
                $newkeywordparentid,
                $newkeywordname,
                $newkeyworddescription,
            );

            if (!empty($fetchedcriteriaid)) {
                booking_option::add_data_to_json($newoption, 'marmaracriteriaid', $fetchedcriteriaid);
            }

            if (!empty($formdata->selectparentkeywordid)) {
                booking_option::add_data_to_json($newoption, 'selectparentkeyword', $formdata->selectparentkeywordid);
            }

            return ['changes' => ['marmaracriteriaid' => $fetchedcriteriaid]];
        }

        if (empty($formdata->enablemarmarasync)) {
            booking_option::remove_key_from_json($newoption, 'marmaracriteriaid');
            booking_option::remove_key_from_json($newoption, 'selectparentkeyword');
        }

        return [];
    }

    /**
     * Function to set the Data for the form.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     *
     * @return void
     *
     */
    public function set_data(stdClass &$data, booking_option_settings $settings) {
        global $OUTPUT;
        $rootparentkeyword = get_config('booking', 'marmara_keywordparentid');
        // In the data object, there might be a json value.
        // With the value for the checkbox? checked or not.
        // And the kriteria ID (if it's there).
        if (!empty($settings->id)) {
            // Load sync status from JSON (default to 0 if not found).
            $enablemarmarasync = booking_option::get_value_of_json_by_key($settings->id, 'enablemarmarasync') ?? 0;
            $data->enablemarmarasync = (int)$enablemarmarasync;

            // Load criteria ID from JSON.
            $currentvalue = booking_option::get_value_of_json_by_key($settings->id, 'marmaracriteriaid') ?? null;

            $data->marmaracriteriaid = $currentvalue;

            // Load parent keyword ID from JSON.
            $selectparentkeywordid = booking_option::get_value_of_json_by_key($settings->id, 'selectparentkeyword') ?? null;
            if (empty($selectparentkeywordid)) {
                $details = [
                    'id' => $rootparentkeyword ?? 0,
                    'name' => 'Root Keyword' ?? '',
                ];
                $data->selectparentkeyword = $OUTPUT->render_from_template(
                    'mod_booking/respondapi/parentkeyword',
                    $details
                );
            } else {
                $selectparentkeywordid = json_decode($selectparentkeywordid);
                $details = [
                    'id' => $selectparentkeywordid->id ?? 0,
                    'name' => $selectparentkeywordid->name ?? '',
                ];
                return $OUTPUT->render_from_template(
                    'mod_booking/respondapi/parentkeyword',
                    $details
                );
            }
        }
    }

    /**
     * Once all changes are collected, also those triggered in save data, this is a possible hook for the fields.
     *
     * @param array $changes
     * @param object $data
     * @param object $newoption
     * @param object $originaloption
     *
     * @return void
     *
     */
    public function changes_collected_action(
        array $changes,
        object $data,
        object $newoption,
        object $originaloption
    ) {
       // Get last text and description. $data contains always last updated text and description.
        $text = $data->text;
        $description = $data->description['text'];

        $enablemarmarasync = booking_option::get_value_of_json_by_key($data->optionid, 'enablemarmarasync') ?? 0;

       // Check if option name or description is changed, then call API to update keyword name.
        if (
            (array_key_exists(\mod_booking\option\fields\text::class, $changes) ||
            array_key_exists(\mod_booking\option\fields\description::class, $changes)) &&
            $enablemarmarasync
        ) {
            // CAll API to update the name & description.
            $this->update_keyword($data->marmaracriteriaid, $text, $description);
        }
    }


    /**
     * We take the parentkeyword and extract the name from it.
     *
     * The format is: <parentid>-<base64_encoded_name>.
     * This encoded format allows the ID and the name to be stored and passed together
     * in a single string, which can later be decoded when needed (e.g., for display or lookup).
     *
     * Example output: "89262-QmFzaWMgS2V5d29yZA=="
     *
     * @param string $parentkeyword
     *
     * @return string
     *
     */
    private function extract_name_from_id(string $parentkeyword): string {
        if (empty($parentkeyword)) {
            $parentkeyword = $this->create_parentkeyword_from_config();
            $parendkeywordname = get_config('booking', 'marmara_keywordparentname') ?? '';
        } else {
            [$parentkeywordid, $parendkeywordnameencoded] = explode('-', $parentkeyword);
            $parendkeywordname = base64_decode($parendkeywordnameencoded);
        }
        return $parendkeywordname;
    }

    /**
     * Create the parentkeyword from config
     *
     * @return string
     *
     */
    private function create_parentkeyword_from_config(): string {
        $pkarray = [
            get_config('booking', 'marmara_keywordparentid') ?? 0,
            base64_encode(get_config('booking', 'marmara_keywordparentname') ?? ''),
        ];
        return implode('-', $pkarray);
    }

    /**
     * We take the parentkeyword and extract the id number from it.
     *
     * The format is: <parentid>-<base64_encoded_name>.
     * This encoded format allows the ID and the name to be stored and passed together
     * in a single string, which can later be decoded when needed (e.g., for display or lookup).
     *
     * Example output: "89262-QmFzaWMgS2V5d29yZA=="
     *
     * @return string The combined parent keyword string from config values.
     */
    public function extract_idnumber_from_id(string $parentkeyword): int {
        if (empty($parentkeyword)) {
            $parendkeywordidnumber = get_config('booking', 'marmara_keywordparentid') ?? '';
        } else {
            [$parendkeywordidnumber, $parendkeywordnameencoded] = explode('-', $parentkeyword);
        }
        return (int)$parendkeywordidnumber;
    }
}
