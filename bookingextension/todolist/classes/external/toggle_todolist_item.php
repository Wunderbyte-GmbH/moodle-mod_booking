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

namespace bookingextension_todolist\external;

use bookingextension_todolist\local\toggle_todolist_service;
use context_module;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API for toggling todo list items.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toggle_todolist_item extends external_api {
    /**
     * External function parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'itemid' => new external_value(PARAM_INT, 'Todo list item id'),
            'optionid' => new external_value(PARAM_INT, 'Booking option id'),
            'checked' => new external_value(PARAM_BOOL, 'Checked state'),
        ]);
    }

    /**
     * Toggle item status and return refreshed template context.
     *
     * @param int $itemid
     * @param int $optionid
     * @param bool $checked
     * @return array
     */
    public static function execute(int $itemid, int $optionid, bool $checked): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'itemid' => $itemid,
            'optionid' => $optionid,
            'checked' => $checked,
        ]);

        require_sesskey();

        $settings = singleton_service::get_instance_of_booking_option_settings((int)$params['optionid']);
        $context = context_module::instance((int)$settings->cmid);
        self::validate_context($context);

        require_login(null, false, null);

        if (!has_capability('bookingextension/todolist:checktodolist', $context)) {
            throw new \required_capability_exception($context, 'bookingextension/todolist:checktodolist', 'nopermissions', '');
        }

        return toggle_todolist_service::toggle_item(
            (int)$params['itemid'],
            (int)$params['optionid'],
            (bool)$params['checked'],
            $context,
            (int)$USER->id,
            (int)$settings->bookingid,
            (int)$settings->cmid
        );
    }

    /**
     * External return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, 'Status flag'),
            'notification' => new external_value(PARAM_TEXT, 'Notification message to display', VALUE_OPTIONAL, ''),
            'notificationtype' => new external_value(
                PARAM_ALPHA,
                'Notification type (success, info, warning, error)',
                VALUE_OPTIONAL,
                ''
            ),
            'context' => new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'optionid' => new external_value(PARAM_INT, 'Booking option id'),
                'cancheck' => new external_value(PARAM_BOOL, 'Whether user can check items'),
                'readonly' => new external_value(PARAM_BOOL, 'Whether list is readonly'),
                'hasitems' => new external_value(PARAM_BOOL, 'Whether list has items'),
                'sesskey' => new external_value(PARAM_RAW, 'Current sesskey'),
                'items' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Item id'),
                    'text' => new external_value(PARAM_TEXT, 'Item text'),
                    'checked' => new external_value(PARAM_BOOL, 'Checked state'),
                    'checkedattr' => new external_value(PARAM_RAW, 'Checked attribute helper'),
                    'readonly' => new external_value(PARAM_BOOL, 'Readonly state for this item'),
                ])),
            ]),
        ]);
    }
}
