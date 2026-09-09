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

namespace mod_booking\reportbuilder\datasource;

use core_reportbuilder\datasource;
use core_reportbuilder\local\entities\user;
use core_reportbuilder\local\helpers\database;
use lang_string;
use mod_booking\reportbuilder\local\entities\booking_messages;
use mod_booking\reportbuilder\local\entities\booking_options;

/**
 * Booking messages datasource for Report Builder.
 *
 * Lists every message mod_booking has sent (booking rule mails as well as confirmations, reminders and
 * other automatic mails). Sent messages are only recorded as \mod_booking\event\message_sent events in
 * the standard log store, so that table is the main table and the log store must be enabled.
 *
 * Entities: the message (time, type, subject, body, booking rule), the recipient (user), the sender
 * (second user entity) and the booking option the message belongs to.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_messages_datasource extends datasource {
    /** @var string Entity name of the sender user entity. */
    public const SENDER_ENTITY = 'sender';

    /**
     * Return user-friendly datasource name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('datasource:bookingmessages', 'mod_booking');
    }

    /**
     * Initialise the datasource, define entities, joins and base conditions.
     */
    protected function initialise(): void {
        // Main entity: the message_sent log entries.
        $messageentity = new booking_messages();
        $log = $messageentity->get_table_alias('logstore_standard_log');
        $this->add_entity($messageentity);
        $this->set_main_table('logstore_standard_log', $log);

        $eventparam = database::generate_param_name();
        $this->add_base_condition_sql(
            "{$log}.eventname = :{$eventparam}",
            [$eventparam => booking_messages::EVENTNAME]
        );

        // Recipient (core user entity, default entity name "user").
        $recipiententity = (new user())
            ->set_entity_title(new lang_string('entity:recipient', 'mod_booking'));
        $u = $recipiententity->get_table_alias('user');
        $this->add_entity($recipiententity
            ->add_join("LEFT JOIN {user} {$u} ON {$u}.id = {$log}.relateduserid"));

        // Sender (second user entity).
        $senderentity = (new user())
            ->set_entity_name(self::SENDER_ENTITY)
            ->set_entity_title(new lang_string('entity:sender', 'mod_booking'));
        $s = $senderentity->get_table_alias('user');
        $this->add_entity($senderentity
            ->add_join("LEFT JOIN {user} {$s} ON {$s}.id = {$log}.userid"));

        // Booking option the message belongs to (objectid of the event, may be 0).
        $optionentity = new booking_options();
        $bo = $optionentity->get_table_alias('booking_options');
        $this->add_entity($optionentity
            ->add_join("LEFT JOIN {booking_options} {$bo} ON {$bo}.id = {$log}.objectid"));

        // Expose all columns, filters and conditions from every entity.
        $this->add_all_from_entities();
    }

    /**
     * Default columns shown when a new report is created from this datasource.
     *
     * @return array
     */
    public function get_default_columns(): array {
        return [
            'booking_messages:timecreated',
            'user:fullname',
            'booking_options:text',
            'booking_messages:messagetype',
            'booking_messages:subject',
            'booking_messages:bookingrule',
        ];
    }

    /**
     * Default column sorting.
     *
     * @return array
     */
    public function get_default_column_sorting(): array {
        return [
            'booking_messages:timecreated' => SORT_DESC,
        ];
    }

    /**
     * Default filters shown in the filter bar.
     *
     * @return array
     */
    public function get_default_filters(): array {
        $filters = [
            'booking_messages:timecreated',
            'user:fullname',
        ];
        if (booking_messages::json_filters_supported()) {
            $filters[] = 'booking_messages:bookingrule';
            $filters[] = 'booking_messages:messagetype';
        }
        return $filters;
    }

    /**
     * Default conditions (always-applied admin conditions).
     *
     * @return array
     */
    public function get_default_conditions(): array {
        return [];
    }
}
