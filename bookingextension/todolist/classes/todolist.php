<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Class todolist.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_todolist;

use admin_setting_configcheckbox;
use admin_setting_heading;
use admin_settingpage;
use context_module;
use mod_booking\plugininfo\bookingextension;
use mod_booking\plugininfo\bookingextension_interface;
use bookingextension_todolist\local\todolist_helper;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/bookingextension/todolist/lib.php');

/**
 * Skeleton booking extension implementation.
 */
class todolist extends bookingextension implements bookingextension_interface {
    /**
     * Get the plugin name.
     *
     * @return string
     */
    public function get_plugin_name(): string {
        return get_string('pluginname', 'bookingextension_todolist');
    }

    /**
     * Check if this extension adds option fields.
     *
     * @return bool
     */
    public function contains_option_fields(): bool {
        return self::is_globally_enabled();
    }

    /**
     * Return metadata for added option fields.
     *
     * @return array
     */
    public function get_option_fields_info_array(): array {
        return [
            'todolist' => [
                'name' => 'todolist',
                'class' => 'bookingextension_todolist\\option\\fields\\todolist',
                'id' => MOD_BOOKING_OPTION_FIELD_TODOLIST,
            ],
        ];
    }

    /**
     * Returns event keys that are allowed for booking rules.
     *
     * @return array
     */
    public static function get_allowedruleeventkeys(): array {
        return [
            'todolist_item_checked',
            'todolist_item_unchecked',
            'todolist_completed',
        ];
    }

    /**
     * Return history description for todolist-specific booking history entries.
     *
     * @param \stdClass $values booking history row values
     * @param array $info decoded json payload
     * @return string
     */
    public static function get_booking_history_description(\stdClass $values, array $info): string {
        if (($info['component'] ?? '') !== 'bookingextension_todolist') {
            return '';
        }

        try {
            $action = (string)($info['action'] ?? '');
            if ($action === 'toggle_todolist_item') {
                $itemid = (int)($info['itemid'] ?? 0);
                $checked = !empty($info['checked']);
                $eventclass = $checked
                    ? '\\bookingextension_todolist\\event\\todolist_item_checked'
                    : '\\bookingextension_todolist\\event\\todolist_item_unchecked';
                if (class_exists($eventclass)) {
                    $event = $eventclass::create([
                        'context' => context_module::instance((int)$values->cmid),
                        'objectid' => $itemid,
                        'relateduserid' => (int)($values->usermodified ?? 0),
                        'other' => [
                            'optionid' => (int)($values->optionid ?? 0),
                            'itemtext' => self::resolve_history_item_text($itemid, $info),
                            'checked' => (int)$checked,
                        ],
                    ]);
                    return $event->get_description();
                }
            }

            if ($action === 'todolist_completed') {
                $eventclass = '\\bookingextension_todolist\\event\\todolist_completed';
                if (class_exists($eventclass)) {
                    $event = $eventclass::create([
                        'context' => context_module::instance((int)$values->cmid),
                        'objectid' => (int)($values->optionid ?? 0),
                        'relateduserid' => (int)($values->usermodified ?? 0),
                    ]);
                    return $event->get_description();
                }
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    /**
     * Resolve the todo item text for booking history descriptions.
     *
     * @param int $itemid
     * @param array $info
     * @return string
     */
    private static function resolve_history_item_text(int $itemid, array $info): string {
        global $DB;

        $itemtext = trim((string)($info['itemtext'] ?? ''));
        if ($itemtext !== '') {
            return $itemtext;
        }

        if ($itemid <= 0) {
            return '';
        }

        $record = $DB->get_record('bookingextension_todolist_item', ['id' => $itemid], 'text', IGNORE_MISSING);
        return trim((string)($record->text ?? ''));
    }

    /**
     * Load plugin settings in site administration.
     *
     * @param \part_of_admin_tree $adminroot
     * @param string $parentnodename
     * @param bool $hassiteconfig
     * @return void
     */
    public function load_settings(\part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig): void {
        $todolistsettings = new admin_settingpage(
            'bookingextension_todolist_settings',
            get_string('pluginname', 'bookingextension_todolist'),
            'moodle/site:config'
        );

        $todolistsettings->add(
            new admin_setting_heading(
                'bookingextension_todolist',
                get_string('todolist:heading', 'bookingextension_todolist'),
                get_string('todolist:heading_desc', 'bookingextension_todolist')
            )
        );

        $todolistsettings->add(
            new admin_setting_configcheckbox(
                'bookingextension_todolist/enableglobally',
                get_string('todolist:enableglobally', 'bookingextension_todolist'),
                get_string('todolist:enableglobally_desc', 'bookingextension_todolist'),
                1
            )
        );

        $adminroot->add('modbookingfolder', $todolistsettings);
    }

    /**
     * Adds settings singleton data.
     *
     * @param int $optionid
     * @return object
     */
    public static function load_data_for_settings_singleton(int $optionid): object {
        return (object)[
            'items' => todolist_helper::get_items_for_option($optionid),
            'enabled' => 0,
        ];
    }

    /**
     * Adds data to optionview description template.
     *
     * @param object $settings
     * @return array
     */
    public static function set_template_data_for_optionview(object $settings): array {
        global $OUTPUT, $PAGE;

        if (!self::is_globally_enabled()) {
            return [];
        }

        $enabled = self::get_enabled_from_json((string)($settings->json ?? ''));
        if (empty($enabled)) {
            return [];
        }

        if (empty($settings->cmid)) {
            return [];
        }

        $context = context_module::instance((int)$settings->cmid);
        if (!has_capability('bookingextension/todolist:viewtodolist', $context)) {
            return [];
        }

        $cancheck = has_capability('bookingextension/todolist:checktodolist', $context);
        $templatecontext = todolist_helper::get_template_context((int)$settings->id, $cancheck, (int)$settings->cmid);
        $html = $OUTPUT->render_from_template('bookingextension_todolist/todolist', $templatecontext);

        if ($cancheck) {
            $PAGE->requires->js_call_amd('bookingextension_todolist/todolist', 'init');
        }

        return [[
            'key' => 'bookingextension_todolist',
            'value' => $html,
            'label' => 'todolist',
            'description' => get_string('todolist', 'bookingextension_todolist'),
        ]];
    }

    /**
     * Returns the enable flag from booking option JSON.
     *
     * @param string $json
     * @return int
     */
    private static function get_enabled_from_json(string $json): int {
        if ($json === '') {
            return 0;
        }

        $jsonobject = json_decode($json);
        if (!is_object($jsonobject) || !isset($jsonobject->enable_todolist)) {
            return 0;
        }

        return (int)$jsonobject->enable_todolist;
    }

    /**
     * Returns whether the extension is globally enabled in site settings.
     *
     * @return bool
     */
    private static function is_globally_enabled(): bool {
        $enabled = get_config('bookingextension_todolist', 'enableglobally');
        if ($enabled === false || $enabled === null) {
            return true;
        }

        return !empty($enabled);
    }
}
