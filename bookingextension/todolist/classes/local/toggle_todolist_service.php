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

namespace bookingextension_todolist\local;

use bookingextension_todolist\event\todolist_completed;
use bookingextension_todolist\event\todolist_item_checked;
use bookingextension_todolist\event\todolist_item_unchecked;
use context_module;
use mod_booking\booking_option;

/**
 * Service for toggling todo list items.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toggle_todolist_service {
    /**
     * Toggle one todo list item and return response payload for external API.
     *
     * @param int $itemid
     * @param int $optionid
     * @param bool $checked
     * @param context_module $context
     * @param int $userid
     * @param int $bookingid
     * @param int $cmid
     * @return array
     */
    public static function toggle_item(
        int $itemid,
        int $optionid,
        bool $checked,
        context_module $context,
        int $userid,
        int $bookingid,
        int $cmid
    ): array {
        global $DB;

        $record = $DB->get_record('bookingextension_todolist_item', [
            'id' => $itemid,
            'optionid' => $optionid,
        ], '*', MUST_EXIST);

        $wascompleted = todolist_helper::todolist_completed($optionid);

        $record->status = $checked ? 1 : 0;
        $record->completed_by = $checked ? $userid : null;
        $record->completed_at = $checked ? time() : null;
        $DB->update_record('bookingextension_todolist_item', $record);

        todolist_helper::refresh_option_todolist_status($optionid);
        $iscompleted = todolist_helper::todolist_completed($optionid);

        self::insert_history($optionid, $bookingid, $userid, [
            'component' => 'bookingextension_todolist',
            'action' => 'toggle_todolist_item',
            'itemid' => $itemid,
            'itemtext' => (string)$record->text,
            'checked' => (int)$checked,
        ]);

        self::trigger_toggle_event($checked, $context, $itemid, $optionid, $userid, (string)$record->text);

        if (!$wascompleted && $iscompleted) {
            self::insert_history($optionid, $bookingid, $userid, [
                'component' => 'bookingextension_todolist',
                'action' => 'todolist_completed',
            ]);

            $completedevent = todolist_completed::create([
                'context' => $context,
                'objectid' => $optionid,
                'relateduserid' => $userid,
            ]);
            $completedevent->trigger();
        }

        [$notification, $notificationtype] = self::build_notification($checked, $wascompleted, $iscompleted);

        return [
            'status' => 1,
            'notification' => $notification,
            'notificationtype' => $notificationtype,
            'context' => todolist_helper::get_template_context($optionid, true, $cmid),
        ];
    }

    /**
     * Write a booking history record for todo list actions.
     *
     * @param int $optionid
     * @param int $bookingid
     * @param int $userid
     * @param array $payload
     * @return void
     */
    private static function insert_history(int $optionid, int $bookingid, int $userid, array $payload): void {
        booking_option::booking_history_insert(
            MOD_BOOKING_STATUSPARAM_COMPLETION_CHANGED,
            0,
            $optionid,
            $bookingid,
            $userid,
            $payload
        );
    }

    /**
     * Trigger the corresponding toggle event.
     *
     * @param bool $checked
     * @param context_module $context
     * @param int $itemid
     * @param int $optionid
     * @param int $userid
     * @param string $itemtext
     * @return void
     */
    private static function trigger_toggle_event(
        bool $checked,
        context_module $context,
        int $itemid,
        int $optionid,
        int $userid,
        string $itemtext
    ): void {
        if ($checked) {
            $event = todolist_item_checked::create([
                'context' => $context,
                'objectid' => $itemid,
                'relateduserid' => $userid,
                'other' => [
                    'optionid' => $optionid,
                    'checked' => 1,
                    'itemtext' => $itemtext,
                ],
            ]);
            $event->trigger();
            return;
        }

        $event = todolist_item_unchecked::create([
            'context' => $context,
            'objectid' => $itemid,
            'relateduserid' => $userid,
            'other' => [
                'optionid' => $optionid,
                'itemtext' => $itemtext,
            ],
        ]);
        $event->trigger();
    }

    /**
     * Build user notification message and type for the toggle response.
     *
     * @param bool $checked
     * @param bool $wascompleted
     * @param bool $iscompleted
     * @return array
     */
    private static function build_notification(bool $checked, bool $wascompleted, bool $iscompleted): array {
        if ($checked) {
            if (!$wascompleted && $iscompleted) {
                return [
                    get_string('notification_todolist_completed', 'bookingextension_todolist'),
                    'success',
                ];
            }

            return [
                get_string('notification_item_checked', 'bookingextension_todolist'),
                'success',
            ];
        }

        return [
            get_string('notification_item_unchecked', 'bookingextension_todolist'),
            'info',
        ];
    }
}
