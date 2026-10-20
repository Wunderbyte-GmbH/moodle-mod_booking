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

namespace mod_booking;

use advanced_testcase;
use mod_booking\event\bookingoption_updated;
use mod_booking\event\selectusers_user_added;
use mod_booking\option\fields\availability;
use mod_booking_generator;
use stdClass;

/**
 * Tests that changes of availability conditions are tracked per condition.
 *
 * The availability JSON of an option holds one object per condition. A change notice has to name
 * the condition that was added, removed or changed and show its old and new settings, while a mere
 * re-serialisation of the same conditions must not count as a change.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\option\fields\availability::check_for_changes
 * @covers \mod_booking\option\fields\availability::get_changes_description
 * @covers \mod_booking\option\fields\availability::trigger_selectusers_user_added_events
 * @covers \mod_booking\event\selectusers_user_added
 */
final class availability_changes_tracking_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * Tests tear down.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Key order, list order and numeric strings are not changes.
     *
     * @return void
     */
    public function test_reserialised_conditions_are_not_a_change(): void {
        $this->setAdminUser();
        [$option, $update, $users] = $this->create_option_restricted_to($this->create_users(2));

        $stored = singleton_service::get_instance_of_booking_option_settings($option->id)->availability;
        $this->assertNotEmpty($stored);

        // Same conditions, but with the keys in another order, the ids as integers and the users swapped.
        $conditions = json_decode($stored, true);
        $reordered = [];
        foreach ($conditions as $condition) {
            $condition['userids'] = array_reverse(array_map('intval', $condition['userids']));
            krsort($condition);
            $reordered[] = $condition;
        }
        $update->availability = json_encode($reordered);

        $field = new availability();
        $this->assertSame([], $field->check_for_changes($update, $field));
    }

    /**
     * Adding, changing and removing the "select users" condition is reported with old and new settings.
     *
     * @return void
     */
    public function test_select_users_condition_changes_are_reported(): void {
        $this->setAdminUser();
        $users = $this->create_users(3);
        [$option, $update] = $this->create_option_restricted_to([]);
        $field = new availability();

        // Added.
        $update->availability = $this->selectusers_json([$users[0]->id, $users[1]->id]);
        $changes = $field->check_for_changes($update, $field);
        $this->assertCount(1, $changes['changes']);
        $entry = $changes['changes'][0]['changes'];
        $this->assertSame('availability', $entry['fieldname']);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_SELECTUSERS, $entry['conditionid']);
        $this->assertSame('selectusers', $entry['conditionname']);
        $this->assertSame([], $entry['oldvalue']);
        $this->assertEqualsCanonicalizing([$users[0]->id, $users[1]->id], $entry['newvalue']['userids']);

        // Changed.
        $this->save_availability($option->id, $update->availability);
        $update->availability = $this->selectusers_json([$users[0]->id, $users[2]->id]);
        $changes = $field->check_for_changes($update, $field);
        $this->assertCount(1, $changes['changes']);
        $entry = $changes['changes'][0]['changes'];
        $this->assertEqualsCanonicalizing([$users[0]->id, $users[1]->id], $entry['oldvalue']['userids']);
        $this->assertEqualsCanonicalizing([$users[0]->id, $users[2]->id], $entry['newvalue']['userids']);

        // Removed.
        $this->save_availability($option->id, $update->availability);
        $update->availability = '[]';
        $changes = $field->check_for_changes($update, $field);
        $this->assertCount(1, $changes['changes']);
        $entry = $changes['changes'][0]['changes'];
        $this->assertEqualsCanonicalizing([$users[0]->id, $users[2]->id], $entry['oldvalue']['userids']);
        $this->assertSame([], $entry['newvalue']);
    }

    /**
     * The change notice names the condition and counts the users without naming them.
     *
     * @return void
     */
    public function test_changes_description_names_condition_and_counts_users(): void {
        $this->setAdminUser();
        $users = $this->create_users(2);
        $field = new availability();

        $old = json_decode($this->selectusers_json([$users[0]->id]), true)[0];
        $new = json_decode($this->selectusers_json([$users[0]->id, $users[1]->id]), true)[0];

        $description = $field->get_changes_description([
            'fieldname' => 'availability',
            'conditionid' => MOD_BOOKING_BO_COND_JSON_SELECTUSERS,
            'conditionname' => 'selectusers',
            'oldvalue' => $old,
            'newvalue' => $new,
        ]);

        $this->assertSame(get_string('availability', 'mod_booking'), $description['fieldname']);
        $label = get_string('bocondselectusers', 'mod_booking');
        $this->assertStringStartsWith($label . ': ', $description['oldvalue']);
        $this->assertStringStartsWith($label . ': ', $description['newvalue']);
        $userslabel = get_string('bocondselectusersuserids', 'mod_booking');
        $this->assertStringContainsString($userslabel . ': 1', $description['oldvalue']);
        $this->assertStringContainsString($userslabel . ': 2', $description['newvalue']);
        $this->assertStringContainsString(get_string('bocondbookingtime', 'mod_booking'), $description['newvalue']);
        // The notice must not disclose who is allowed to book.
        foreach ($users as $user) {
            $this->assertStringNotContainsString(fullname($user), $description['oldvalue']);
            $this->assertStringNotContainsString(fullname($user), $description['newvalue']);
            $this->assertStringNotContainsString('(ID:' . $user->id . ')', $description['newvalue']);
        }

        // A removed condition has no new value, an added one no old value.
        $removed = $field->get_changes_description(['fieldname' => 'availability', 'oldvalue' => $old, 'newvalue' => []]);
        $this->assertSame('', $removed['newvalue']);
        $this->assertStringContainsString($userslabel . ': 1', $removed['oldvalue']);

        // Objects restored from the log store are handled like arrays.
        $restored = $field->get_changes_description([
            'fieldname' => 'availability',
            'oldvalue' => null,
            'newvalue' => (object)$new,
        ]);
        $this->assertStringContainsString($userslabel . ': 2', $restored['newvalue']);
    }

    /**
     * Saving the option with other users records exactly one availability change in the event.
     *
     * @return void
     */
    public function test_update_records_select_users_change_in_event(): void {
        $this->setAdminUser();
        $users = $this->create_users(2);
        [$option, $update] = $this->create_option_restricted_to([$users[0]]);

        $update->bo_cond_selectusers_restrict = 1;
        $update->bo_cond_selectusers_userids = [$users[1]->id];

        $sink = $this->redirectEvents();
        booking_option::update($update);
        $events = $sink->get_events();
        $sink->close();

        $updateevents = array_values(array_filter($events, function ($event) use ($option) {
            return $event instanceof bookingoption_updated && (int) $event->objectid === (int) $option->id;
        }));
        $this->assertCount(1, $updateevents, 'Exactly one option updated event is expected.');

        $data = reset($updateevents)->get_data();
        $availabilitychanges = array_values(array_filter($data['other']['changes'], function ($change) {
            return ($change['fieldname'] ?? '') === 'availability';
        }));
        $this->assertCount(1, $availabilitychanges);
        $this->assertSame('selectusers', $availabilitychanges[0]['conditionname']);
        $this->assertEquals([$users[0]->id], $availabilitychanges[0]['oldvalue']['userids']);
        $this->assertEquals([$users[1]->id], $availabilitychanges[0]['newvalue']['userids']);

        // The rendered event description shows the condition but names no user.
        $description = reset($updateevents)->get_description();
        $this->assertStringContainsString(get_string('bocondselectusers', 'mod_booking'), $description);
        $this->assertStringNotContainsString(fullname($users[0]), $description);
        $this->assertStringNotContainsString(fullname($users[1]), $description);
    }

    /**
     * Each user newly allowed to book gets a selectusers_user_added event; nobody else does.
     *
     * @return void
     */
    public function test_update_triggers_event_only_for_newly_added_users(): void {
        $this->setAdminUser();
        $users = $this->create_users(3);
        [$option, $update] = $this->create_option_restricted_to([$users[0]]);

        // User 0 stays, user 1 and user 2 are added.
        $update->bo_cond_selectusers_restrict = 1;
        $update->bo_cond_selectusers_userids = [$users[0]->id, $users[1]->id, $users[2]->id];

        $sink = $this->redirectEvents();
        booking_option::update($update);
        $events = $this->added_events($sink->get_events(), (int) $option->id);
        $sink->close();

        $this->assertEqualsCanonicalizing(
            [$users[1]->id, $users[2]->id],
            array_map(fn($event) => (int) $event->relateduserid, $events)
        );
        $event = reset($events);
        $this->assertSame((int) $update->cmid, (int) $event->other['cmid']);
        $this->assertStringContainsString("'{$option->id}'", $event->get_description());
        $this->assertStringContainsString('optionid=' . $option->id, $event->get_url()->out(false));

        // Removing a user and saving again adds nobody: no event.
        $update->bo_cond_selectusers_userids = [$users[0]->id, $users[1]->id];
        $sink = $this->redirectEvents();
        booking_option::update($update);
        $this->assertSame([], $this->added_events($sink->get_events(), (int) $option->id));
        $sink->close();

        // Saving the same list again: no event either.
        $sink = $this->redirectEvents();
        booking_option::update($update);
        $this->assertSame([], $this->added_events($sink->get_events(), (int) $option->id));
        $sink->close();
    }

    /**
     * Adding a user through the easy "select users" field triggers the event as well.
     *
     * @return void
     */
    public function test_easy_field_triggers_event_for_added_user(): void {
        $this->setAdminUser();
        $users = $this->create_users(2);
        [$option, $update] = $this->create_option_restricted_to([$users[0]]);

        $changes = [
            'mod_booking\\option\\fields\\easy_availability_selectusers' => [
                'changes' => [
                    [
                        'changes' => [
                            'fieldname' => 'availability',
                            'conditionid' => MOD_BOOKING_BO_COND_JSON_SELECTUSERS,
                            'oldvalue' => ['userids' => [(string) $users[0]->id]],
                            'newvalue' => ['userids' => [(string) $users[0]->id, (string) $users[1]->id]],
                        ],
                    ],
                ],
            ],
            // A single-entry change of another field must be skipped without error.
            'mod_booking\\option\\fields\\text' => [
                'changes' => ['fieldname' => 'text', 'oldvalue' => 'a', 'newvalue' => 'b'],
            ],
        ];

        $sink = $this->redirectEvents();
        $added = availability::trigger_selectusers_user_added_events($changes, $update, (object) ['id' => $option->id]);
        $events = $this->added_events($sink->get_events(), (int) $option->id);
        $sink->close();

        $this->assertSame([(int) $users[1]->id], $added);
        $this->assertCount(1, $events);
        $this->assertSame((int) $users[1]->id, (int) reset($events)->relateduserid);
    }

    /**
     * The selectusers_user_added events for the given option.
     *
     * @param array $events
     * @param int $optionid
     * @return array
     */
    private function added_events(array $events, int $optionid): array {
        return array_values(array_filter($events, function ($event) use ($optionid) {
            return $event instanceof selectusers_user_added && (int) $event->objectid === $optionid;
        }));
    }

    /**
     * The JSON the form produces for a "select users" condition restricted to the given users.
     *
     * @param array $userids
     * @return string
     */
    private function selectusers_json(array $userids): string {
        return json_encode([
            [
                'id' => MOD_BOOKING_BO_COND_JSON_SELECTUSERS,
                'name' => 'selectusers',
                'class' => 'mod_booking\\bo_availability\\conditions\\selectusers',
                'userids' => array_map('strval', $userids),
                'overrides' => [MOD_BOOKING_BO_COND_BOOKING_TIME],
                'overrideoperator' => 'OR',
            ],
        ]);
    }

    /**
     * Writes an availability JSON directly to the option and clears its cached settings.
     *
     * @param int $optionid
     * @param string $availability
     * @return void
     */
    private function save_availability(int $optionid, string $availability): void {
        global $DB;
        $DB->set_field('booking_options', 'availability', $availability, ['id' => $optionid]);
        singleton_service::destroy_booking_option_singleton($optionid);
        booking_option::purge_cache_for_option($optionid);
    }

    /**
     * Creates the given number of users.
     *
     * @param int $count
     * @return array
     */
    private function create_users(int $count): array {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $users[] = $this->getDataGenerator()->create_user();
        }
        return $users;
    }

    /**
     * Creates a booking option, restricted to the given users if there are any.
     *
     * @param array $users
     * @return array the created option, a prefilled update record for it and the users
     */
    private function create_option_restricted_to(array $users): array {

        $course = $this->getDataGenerator()->create_course();
        $bdata = [
            'name' => 'Test booking availability changes',
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'course' => $course->id,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Option with restricted users';
        $record->description = 'Description';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->optiondateid_0 = '0';
        $record->daystonotify_0 = '0';
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');
        if (!empty($users)) {
            $record->bo_cond_selectusers_restrict = 1;
            $record->bo_cond_selectusers_userids = array_map(fn($user) => $user->id, $users);
        }

        $option = $plugingenerator->create_option($record);

        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $update = clone $record;
        $update->id = $option->id;
        $update->cmid = $settings->cmid;
        unset($update->importing);

        return [$option, $update, $users];
    }
}
