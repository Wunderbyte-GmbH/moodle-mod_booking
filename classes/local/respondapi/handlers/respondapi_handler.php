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

use mod_booking\local\respondapi\providers\interfaces\respondapi_provider_interface;
use mod_booking\local\respondapi\providers\marmaraapi_provider;
use mod_booking\singleton_service;
use MoodleQuickForm;


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
        $this->provider = $provider ?? new marmaraapi_provider();
    }

    /**
     * Create a new keyword in the external API.
     *
     * @param string $name The keyword name.
     * @param string|null $comment Optional description.
     * @return int|null Keyword ID.
     */
    public function get_new_keyword(string $name, ?string $comment = null): ?int {
        return $this->provider->sync_keyword($name, null, $comment);
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
     *
     *
     * @param string $query
     * @return array{list: array, warnings: string|array{list: object[], warnings: string}}
     */
    public function get_parent_kewords(string $query) {
        $records = [
            ['id' => 1, 'name' => 'keyword 1'],
            ['id' => 2, 'name' => 'keyword 2'],
            ['id' => 3, 'name' => 'keyword 3'],
            ['id' => 4, 'name' => 'keyword 4'],
            ['id' => 5, 'name' => 'keyword 5'],
        ];

        // $records = $this->provider->get_keywords();

        $records = json_decode(json_encode($records));

        $list = [];

        $count = 0;

        foreach ($records as $record) {
            $keyword = (object)[
                    'id' => $record->id,
                    'name' => $record->name,
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
    public function add_to_mform(MoodleQuickForm &$mform) {
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
        $list = [];
        // We need to preload list to not only have the id, but the rendered values.
        if ($this->optionid !== 0) {
            $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
            if ($settings && !empty($settings->marmaraparentkeyword)) {
                $details = [
                    'id' => $settings->marmaraparentkeyword->id,
                    'name' => $settings->marmaraparentkeyword->name,
                ];
                $list[$settings->marmaraparentkeyword->id] =
                    $OUTPUT->render_from_template(
                        'mod_booking/respondapi/parentkeyword',
                        $details
                    );
            }
        }


        $options = [
            'tags' => false,
            'multiple' => false,
            'noselectionstring' => '',
            'ajax' => 'mod_booking/parentkeyword_selector',
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT;
                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }
                $details = [
                    'id' => 11 ?? 0,
                    'name' => 'parent 11' ?? '',
                ];
                return $OUTPUT->render_from_template(
                    'mod_booking/respondapi/parentkeyword',
                    $details
                );
            },
        ];
        /* Important note: Currently, all users can be added as teachers for optiondates.
        In the future, there might be a user profile field defining users which are allowed
        to be added as substitute teachers. */
        $mform->addElement(
            'autocomplete',
            'teachersforoption',
            get_string('marmara:selectparentkeyword', 'mod_booking'),
            $list,
            $options
        );

        $mform->addHelpButton('teachersforoption', 'teachersforoption', 'mod_booking');
    }
}
