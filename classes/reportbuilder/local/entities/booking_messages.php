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

namespace mod_booking\reportbuilder\local\entities;

use core\lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\select;
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use stdClass;

/**
 * Booking message entity for Report Builder.
 *
 * A sent message is a \mod_booking\event\message_sent entry in {logstore_standard_log}. The message details
 * (type, subject, body, booking rule) live in the JSON "other" column of the log entry, so the text columns
 * decode that JSON in PHP, while the type and rule filters use DB specific JSON SQL (postgres and mysql only).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_messages extends base {
    /** @var string Event name of the log entries this entity describes. */
    public const EVENTNAME = '\mod_booking\event\message_sent';

    /** @var array<int, string>|null Rule display names indexed by rule id. */
    private static ?array $rulenames = null;

    /**
     * Database tables that this entity uses.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['logstore_standard_log'];
    }

    /**
     * The default title for this entity.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('entitybookingmessage', 'mod_booking');
    }

    /**
     * Initialise the entity — register all columns, filters and conditions.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter);
            $this->add_condition($filter);
        }
        return $this;
    }

    /**
     * Whether the DB family supports the JSON SQL needed for the message type and rule filters.
     *
     * @return bool
     */
    public static function json_filters_supported(): bool {
        global $DB;
        return in_array($DB->get_dbfamily(), ['postgres', 'mysql']);
    }

    /**
     * Message types (MOD_BOOKING_MSGPARAM_* constants) mapped to their readable labels.
     *
     * @return array<int, string>
     */
    public static function get_message_type_labels(): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/booking/lib.php');

        return [
            MOD_BOOKING_MSGPARAM_CONFIRMATION => get_string('messagetype:confirmation', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_WAITINGLIST => get_string('messagetype:waitinglist', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_REMINDER_PARTICIPANT => get_string('messagetype:reminderparticipant', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_REMINDER_TEACHER => get_string('messagetype:reminderteacher', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_STATUS_CHANGED => get_string('messagetype:statuschanged', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_CANCELLED_BY_PARTICIPANT => get_string('messagetype:cancelledbyparticipant', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_CANCELLED_BY_TEACHER_OR_SYSTEM =>
                get_string('messagetype:cancelledbyteacherorsystem', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_CHANGE_NOTIFICATION => get_string('messagetype:changenotification', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_POLLURL_PARTICIPANT => get_string('messagetype:pollurlparticipant', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_POLLURL_TEACHER => get_string('messagetype:pollurlteacher', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_COMPLETED => get_string('messagetype:completed', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_SESSIONREMINDER => get_string('messagetype:sessionreminder', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_REPORTREMINDER => get_string('messagetype:reportreminder', 'mod_booking'),
            MOD_BOOKING_MSGPARAM_CUSTOM_MESSAGE => get_string('messagetype:custommessage', 'mod_booking'),
        ];
    }

    /**
     * Display names of all booking rules indexed by rule id (the "name" of the rule JSON, falling back to the type).
     *
     * @return array<int, string>
     */
    public static function get_rule_names(): array {
        global $DB;

        if (self::$rulenames === null) {
            self::$rulenames = [];
            $rules = $DB->get_records('booking_rules', null, 'id ASC', 'id, rulename, rulejson');
            foreach ($rules as $rule) {
                $json = empty($rule->rulejson) ? null : json_decode($rule->rulejson);
                $name = trim((string) ($json->name ?? ''));
                self::$rulenames[(int) $rule->id] = $name !== '' ? $name : (string) $rule->rulename;
            }
        }
        return self::$rulenames;
    }

    /**
     * Reset the request-level caches (used by unit tests).
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$rulenames = null;
    }

    /**
     * Returns list of all available columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $log = $this->get_table_alias('logstore_standard_log');
        $columns = [];

        // Time the message was sent.
        $columns[] = (new column(
            'timecreated',
            new lang_string('timesent', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$log}.timecreated")
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']);

        // Message type (messageparam in the event data).
        $columns[] = (new column(
            'messagetype',
            new lang_string('messagetype', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$log}.other")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                $other = self::decode_other($value);
                if (!isset($other->messageparam)) {
                    return '';
                }
                $labels = self::get_message_type_labels();
                return $labels[(int) $other->messageparam] ?? get_string('messagetype:unknown', 'mod_booking');
            });

        // Subject.
        $columns[] = (new column(
            'subject',
            new lang_string('messagesubject', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$log}.other")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                $other = self::decode_other($value);
                return format_string((string) ($other->subject ?? ''));
            });

        // Message body (HTML version if stored, plain text otherwise).
        $columns[] = (new column(
            'message',
            new lang_string('messagebody', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_LONGTEXT)
            ->add_field("{$log}.other")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                $other = self::decode_other($value);
                if (!empty($other->messagehtml)) {
                    return format_text((string) $other->messagehtml, FORMAT_HTML);
                }
                return format_text((string) ($other->message ?? ''), FORMAT_PLAIN);
            });

        // Booking rule that sent the message (empty for automatic mails not sent by a rule).
        $columns[] = (new column(
            'bookingrule',
            new lang_string('bookingrule', 'mod_booking'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$log}.other")
            ->set_is_sortable(false)
            ->add_callback(static function ($value): string {
                $other = self::decode_other($value);
                $ruleid = (int) ($other->bookingruleid ?? 0);
                if ($ruleid <= 0) {
                    return '';
                }
                return format_string(self::get_rule_names()[$ruleid] ?? '#' . $ruleid);
            });

        return $columns;
    }

    /**
     * Returns list of all available filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $log = $this->get_table_alias('logstore_standard_log');
        $filters = [];

        // Time sent.
        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('timesent', 'mod_booking'),
            $this->get_entity_name(),
            "{$log}.timecreated"
        ))
            ->add_joins($this->get_joins());

        if (self::json_filters_supported()) {
            // Message type.
            $filters[] = (new filter(
                select::class,
                'messagetype',
                new lang_string('messagetype', 'mod_booking'),
                $this->get_entity_name(),
                self::get_json_int_sql($log, 'messageparam')
            ))
                ->add_joins($this->get_joins())
                ->set_options_callback([self::class, 'get_message_type_labels']);

            // Booking rule.
            $filters[] = (new filter(
                select::class,
                'bookingrule',
                new lang_string('bookingrule', 'mod_booking'),
                $this->get_entity_name(),
                self::get_json_int_sql($log, 'bookingruleid')
            ))
                ->add_joins($this->get_joins())
                ->set_options_callback([self::class, 'get_rule_names']);
        }

        return $filters;
    }

    /**
     * Decode the JSON "other" column of a log entry.
     *
     * @param mixed $value
     * @return stdClass
     */
    private static function decode_other($value): stdClass {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value);
            if ($decoded instanceof stdClass) {
                return $decoded;
            }
        }
        return new stdClass();
    }

    /**
     * SQL extracting an integer key from the JSON "other" column (postgres and mysql only).
     *
     * The extraction is wrapped in CASE so the JSON functions are only evaluated on rows of this plugin's
     * message events, whatever order the DB evaluates the WHERE clause in.
     *
     * @param string $log Alias of the logstore_standard_log table
     * @param string $key JSON key holding an integer
     * @return string
     */
    private static function get_json_int_sql(string $log, string $key): string {
        global $DB;

        $guard = "{$log}.component = 'mod_booking' AND {$log}.target = 'message'";
        switch ($DB->get_dbfamily()) {
            case 'postgres':
                return "CASE WHEN {$guard}
                             THEN CASE WHEN ({$log}.other::jsonb ->> '{$key}') ~ '^[0-9]+$'
                                       THEN ({$log}.other::jsonb ->> '{$key}')::int END
                        END";
            case 'mysql':
                return "CASE WHEN {$guard}
                             THEN CAST(JSON_UNQUOTE(JSON_EXTRACT({$log}.other, '$.{$key}')) AS UNSIGNED)
                        END";
            default:
                return 'NULL';
        }
    }
}
