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

namespace mod_booking\local\wizard\options\skills;

use core_customfield\category_controller;
use mod_booking\customfield\booking_handler;

/**
 * Validation and write helpers shared by the booking option field skills.
 *
 * The shortname rules live here in one place: a field must not shadow a booking option property
 * (the rule the custom field management page warns about) and a shortname is used once. Both skills
 * run them before the write, so a collision becomes a question and never a write.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_field_validation {
    /**
     * Read a localized string, optionally forcing a specific output language.
     *
     * @param string $identifier
     * @param mixed $a
     * @param string $lang
     * @return string
     */
    public static function str(string $identifier, $a = null, string $lang = ''): string {
        $targetlang = trim($lang);
        if ($targetlang === '') {
            return get_string($identifier, 'booking', $a);
        }
        return get_string_manager()->get_string($identifier, 'booking', $a, $targetlang);
    }

    /**
     * Turn structure error messages into preflight issues.
     *
     * @param array $messages
     * @return array<int,array<string,string>>
     */
    public static function build_issues(array $messages): array {
        $issues = [];
        foreach ($messages as $message) {
            $message = trim((string)$message);
            if ($message === '') {
                continue;
            }
            $issues[] = [
                'code' => 'OPTION_FIELD_PREFLIGHT_BLOCKED',
                'severity' => 'needs_clarification',
                'message' => $message,
            ];
        }
        return $issues;
    }

    /**
     * Issue for a field type this site has not installed, listing the installed ones, or null when fine.
     *
     * @param string $type
     * @param string $lang
     * @return array<string,mixed>|null
     */
    public static function check_type(string $type, string $lang): ?array {
        if (option_field_support::type_is_installed($type)) {
            return null;
        }

        $installed = implode(', ', array_keys(option_field_support::get_installed_types()));
        return [
            'code' => 'OPTION_FIELD_TYPE_UNKNOWN',
            'severity' => 'needs_clarification',
            'user_question' => self::str(
                'agent_booking_optionfield_type_unknown',
                (object)['type' => $type, 'installed' => $installed],
                $lang
            ),
            'remedy_options' => ['CHOOSE_INSTALLED_TYPE'],
        ];
    }

    /**
     * Issue when this shortname may not be used, or null when it is free.
     *
     * Two rules, both of which the management page only reports after the fact:
     * the shortname must not shadow a booking option property, and it must not be taken already.
     *
     * @param string $shortname
     * @param string $lang
     * @param int $ignorefieldid field id that may keep its own shortname (update case)
     * @return array<string,mixed>|null
     */
    public static function check_shortname_free(string $shortname, string $lang, int $ignorefieldid = 0): ?array {
        if (booking_handler::is_reserved_shortname($shortname)) {
            return [
                'code' => 'OPTION_FIELD_SHORTNAME_RESERVED',
                'severity' => 'needs_clarification',
                'user_question' => self::str('agent_booking_optionfield_shortname_reserved', $shortname, $lang),
                'remedy_options' => ['CHOOSE_DIFFERENT_SHORTNAME'],
            ];
        }

        $existing = option_field_support::get_field_by_shortname($shortname);
        if ($existing !== null && (int)$existing->id !== $ignorefieldid) {
            return [
                'code' => 'OPTION_FIELD_SHORTNAME_TAKEN',
                'severity' => 'needs_clarification',
                'user_question' => self::str('agent_booking_optionfield_shortname_taken', $shortname, $lang),
                'remedy_options' => ['CHOOSE_DIFFERENT_SHORTNAME', 'UPDATE_EXISTING_FIELD'],
            ];
        }

        return null;
    }

    /**
     * Resolve the field category to write into: the named one, an existing one, or a new one.
     *
     * Only categories that really belong to the booking option fields are considered, read from the
     * database. The handler's category list also carries the shared core_customfield categories and
     * can be stale, and a field written into either of those would not be a booking option field.
     *
     * @param booking_handler $handler
     * @param string $categoryname empty = first existing category
     * @return category_controller
     */
    public static function resolve_category(booking_handler $handler, string $categoryname): category_controller {
        global $DB;

        $own = $DB->get_records(
            'customfield_category',
            ['component' => 'mod_booking', 'area' => 'booking'],
            'sortorder ASC, id ASC',
            'id, name'
        );

        if ($categoryname !== '') {
            foreach ($own as $category) {
                if (strcasecmp((string)$category->name, $categoryname) === 0) {
                    return category_controller::create((int)$category->id);
                }
            }
            // Load the freshly created category directly: the handler's category cache is already
            // populated at this point, so re-reading it would be stale.
            return category_controller::create($handler->create_category($categoryname));
        }

        if (!empty($own)) {
            $first = reset($own);
            return category_controller::create((int)$first->id);
        }

        return category_controller::create($handler->create_category());
    }

    /**
     * Build the configdata of a field from the generic inputs plus any type-specific pass-through.
     *
     * @param array $input
     * @param array $current existing configdata to build on (update case)
     * @return array<string,mixed>
     */
    public static function build_configdata(array $input, array $current = []): array {
        $configdata = $current;

        $configdata['required'] = !empty($input['required']) ? 1 : 0;
        $configdata['uniquevalues'] = !empty($input['uniquevalues']) ? 1 : 0;
        $configdata['locked'] = $configdata['locked'] ?? 0;
        $configdata['visibility'] = $configdata['visibility'] ?? 2;
        if (array_key_exists('defaultvalue', $input)) {
            $configdata['defaultvalue'] = (string)$input['defaultvalue'];
        } else {
            $configdata['defaultvalue'] = $configdata['defaultvalue'] ?? '';
        }

        $options = option_field_support::normalize_options($input['options'] ?? []);
        if (!empty($options)) {
            // Core's select field stores its choices as one value per line.
            $configdata['options'] = implode("\n", $options);
        }

        // Type-specific settings the caller passed through, e.g. displaysize for a text field.
        foreach ((array)($input['configdata'] ?? []) as $key => $value) {
            $key = trim((string)$key);
            if ($key === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $configdata[$key] = $value;
        }

        return $configdata;
    }
}
