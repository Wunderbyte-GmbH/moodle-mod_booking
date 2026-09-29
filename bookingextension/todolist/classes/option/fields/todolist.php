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

namespace bookingextension_todolist\option\fields;

use bookingextension_todolist\local\todolist_helper;
use context_module;
use mod_booking\booking_option;
use mod_booking\booking_option_settings;
use mod_booking\option\field_base;
use MoodleQuickForm;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/bookingextension/todolist/lib.php');

/**
 * Option form field integration for todo list.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class todolist extends field_base {
    /** @var string Subplugin frankenstyle component. */
    public static $subplugin = 'bookingextension_todolist';

    /** @var int Field sort id. */
    public static $id = MOD_BOOKING_OPTION_FIELD_TODOLIST;

    /** @var int Save in post phase to guarantee option id. */
    public static $save = MOD_BOOKING_EXECUTION_POSTSAVE;

    /** @var string Header section in option form. */
    public static $header = MOD_BOOKING_HEADER_TODOLIST;

    /** @var string Header icon. */
    public static $headericon = '<i class="fa fa-list-check" aria-hidden="true"></i>&nbsp;';

    /** @var array Field category. */
    public static $fieldcategories = [MOD_BOOKING_OPTION_FIELD_STANDARD];

    /** @var array Alternative import identifiers. */
    public static $alternativeimportidentifiers = [];

    /** @var array Incompatible field ids. */
    public static $incompatiblefields = [];

    /**
     * Prepare data persisted in booking option JSON.
     *
     * @param stdClass $formdata
     * @param stdClass $newoption
     * @param int $updateparam
     * @param mixed $returnvalue
     * @return array
     */
    public static function prepare_save_field(
        stdClass &$formdata,
        stdClass &$newoption,
        int $updateparam,
        $returnvalue = null
    ): array {
        if (get_config(self::$subplugin, 'enableglobally') == 0) {
            return [];
        }
        booking_option::add_data_to_json($newoption, 'enable_todolist', !empty($formdata->enable_todolist) ? 1 : 0);
        return [];
    }

    /**
     * Add elements to option form.
     *
     * @param MoodleQuickForm $mform
     * @param array $formdata
     * @param array $optionformconfig
     * @param array $fieldstoinstanciate
     * @param bool $applyheader
     * @return void
     */
    public static function instance_form_definition(
        MoodleQuickForm &$mform,
        array &$formdata,
        array $optionformconfig,
        $fieldstoinstanciate = [],
        $applyheader = true
    ): void {

        if (get_config(self::$subplugin, 'enableglobally') == 0) {
            return;
        }

        if (!empty($formdata['cmid'])) {
            $context = context_module::instance((int)$formdata['cmid']);
            if (!has_capability('bookingextension/todolist:edittodolist', $context)) {
                return;
            }
        }

        if ($applyheader) {
            if (!$mform->elementExists(self::$header)) {
                $mform->addElement(
                    'header',
                    self::$header,
                    self::$headericon . get_string(self::$header, self::$subplugin)
                );
            }
        }

        $mform->addElement(
            'advcheckbox',
            'enable_todolist',
            get_string('enable_todolist', 'bookingextension_todolist'),
            get_string('enable_todolist_desc', 'bookingextension_todolist')
        );

        $mform->addElement(
            'textarea',
            'todolist_items',
            get_string('todolist_items', 'bookingextension_todolist'),
            ['rows' => 8, 'cols' => 50]
        );
        $mform->setType('todolist_items', PARAM_TEXT);
        $mform->addHelpButton('todolist_items', 'todolist_items', 'bookingextension_todolist');
        $mform->hideIf('todolist_items', 'enable_todolist', 'neq', 1);

        $optionid = (int)($formdata['id'] ?? $formdata['optionid'] ?? 0);
        if ($optionid > 0 && todolist_helper::has_completed_items($optionid)) {
            $mform->addElement(
                'advcheckbox',
                'todolist_reset_completed_confirmation',
                get_string('todolist_reset_completed_confirmation', 'bookingextension_todolist')
            );
            $mform->hideIf('todolist_reset_completed_confirmation', 'enable_todolist', 'neq', 1);
        }
    }

    /**
     * Set form defaults from stored data.
     *
     * @param stdClass $data
     * @param booking_option_settings $settings
     * @return void
     */
    public static function set_data(stdClass &$data, booking_option_settings $settings): void {

        if (get_config(self::$subplugin, 'enableglobally') == 0) {
            return;
        }

        if (!empty($data->importing)) {
            // Import path: caller supplies enable_todolist (0/1) and todolist_items
            // (newline-separated item texts) directly in $data.  Both fields are optional;
            // when absent the stored values are used as fall-back so that a partial import
            // does not accidentally wipe an existing todo list.
            $optionid = (int)($data->id ?? $settings->id ?? 0);
            $json = (string)($settings->json ?? '');
            $jsonobject = json_decode($json);

            if (!isset($data->enable_todolist)) {
                $data->enable_todolist = (int)($jsonobject->enable_todolist ?? 0);
            }

            if (!isset($data->todolist_items)) {
                // Fall back to whatever is already stored.
                $items = todolist_helper::get_items_for_option($optionid);
                $lines = [];
                foreach ($items as $item) {
                    $lines[] = (string)$item->text;
                }
                $data->todolist_items = implode(PHP_EOL, $lines);
            }

            $data->todolist_reset_completed_confirmation = 1;
        } else {
            $optionid = (int)($data->id ?? $settings->id ?? 0);
            $json = (string)($settings->json ?? '');
            $jsonobject = json_decode($json);

            $data->enable_todolist = (int)($jsonobject->enable_todolist ?? 0);

            $items = todolist_helper::get_items_for_option($optionid);
            $lines = [];
            foreach ($items as $item) {
                $lines[] = (string)$item->text;
            }
            $data->todolist_reset_completed_confirmation = 0;
            $data->todolist_items = implode(PHP_EOL, $lines);
        }
    }

    /**
     * Validation for todo list editor.
     *
     * @param array $data
     * @param array $files
     * @param array $errors
     * @return array
     */
    public static function validation(array $data, array $files, array &$errors): array {
        if (!empty($data['cmid'])) {
            $context = context_module::instance((int)$data['cmid']);
            if (!has_capability('bookingextension/todolist:edittodolist', $context)) {
                return $errors;
            }
        }

        $optionid = (int)($data['id'] ?? 0);
        if ($optionid <= 0 || empty($data['enable_todolist'])) {
            return $errors;
        }

        $newlines = todolist_helper::parse_lines((string)($data['todolist_items'] ?? ''));
        $oldrecords = todolist_helper::get_items_for_option($optionid);
        $oldlines = todolist_helper::texts_from_records($oldrecords);

        if ($newlines !== $oldlines && todolist_helper::has_completed_items($optionid)) {
            if (empty($data['todolist_reset_completed_confirmation'])) {
                $errors['todolist_reset_completed_confirmation'] = get_string(
                    'todolist_reset_completed_confirmation',
                    'bookingextension_todolist'
                );
            }
        }

        return $errors;
    }

    /**
     * Persist todo list records after option save.
     *
     * @param stdClass $formdata
     * @param stdClass $option
     * @return array
     */
    public static function save_data(stdClass &$formdata, stdClass &$option): array {
        global $USER;

        if (get_config(self::$subplugin, 'enableglobally') == 0) {
            return [];
        }

        if (!empty($formdata->cmid)) {
            $context = context_module::instance((int)$formdata->cmid);
            if (!has_capability('bookingextension/todolist:edittodolist', $context)) {
                return [];
            }
        }

        $optionid = (int)$option->id;
        if ($optionid <= 0) {
            return [];
        }

        if (empty($formdata->enable_todolist)) {
            todolist_helper::refresh_option_todolist_status($optionid);
            return [];
        }

        $lines = todolist_helper::parse_lines((string)($formdata->todolist_items ?? ''));
        $existing = todolist_helper::texts_from_records(todolist_helper::get_items_for_option($optionid));

        if ($lines !== $existing) {
            todolist_helper::replace_items($optionid, $lines, (int)$USER->id);
        } else {
            todolist_helper::refresh_option_todolist_status($optionid);
        }
        return [];
    }
}
