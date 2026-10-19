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
use mod_booking\bo_availability\bo_info;
use mod_booking\bo_availability\conditions\selectusers;
use mod_booking\event\selectusers_user_removed;
use mod_booking_generator;
use stdClass;

/**
 * Tests "Remove users from the list once they have booked": the site setting makes it available, the option decides.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\conditions\selectusers::remove_booked_user
 * @covers \mod_booking\bo_availability\conditions\selectusers::is_available
 * @covers \mod_booking\bo_availability\conditions\selectusers::get_condition_object_for_json
 * @covers \mod_booking\bo_availability\conditions\selectusers::set_defaults
 * @covers \mod_booking\event\selectusers_user_removed
 */
final class condition_selectusers_removeafterbooking_test extends advanced_testcase {
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
     * A booked user leaves the list, stays booked, and an emptied list blocks everyone else.
     *
     * @return void
     */
    public function test_booked_user_is_removed_and_empty_list_blocks(): void {
        $this->setAdminUser();
        [$users, $option, $cmid] = $this->create_restricted_option(3, true);
        [$user1, $user2, $user3] = $users;

        // User 1 books. No event sink here: a sink keeps the observer that removes the user from running.
        $this->book($cmid, $option->id, $user1);
        $this->assertSame($this->ids($user2), $this->allowed_userids($option->id));

        // User 1 stays booked and user 2 can still book, user 3 still cannot.
        $this->assertContains($this->blocking_condition($option->id, $user1->id), MOD_BOOKING_BO_COND_BOOKED_STATES);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $this->blocking_condition($option->id, $user2->id));
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_SELECTUSERS, $this->blocking_condition($option->id, $user3->id));

        // User 2 books: the list is empty now, the restriction stays and blocks user 3.
        $this->book($cmid, $option->id, $user2);
        $this->assertSame([], $this->allowed_userids($option->id));
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_SELECTUSERS, $this->blocking_condition($option->id, $user3->id));
        $this->assertContains($this->blocking_condition($option->id, $user2->id), MOD_BOOKING_BO_COND_BOOKED_STATES);

        // A booking that lands on the waiting list does not touch the list.
        $this->assertFalse(selectusers::remove_booked_user((int) $option->id, (int) $user3->id));
    }

    /**
     * The removal triggers the selectusers_user_removed event for the removed user.
     *
     * @return void
     */
    public function test_removal_triggers_event(): void {
        $this->setAdminUser();
        [$users, $option, $cmid] = $this->create_restricted_option(3, true);

        $sink = $this->redirectEvents();
        $this->assertTrue(selectusers::remove_booked_user((int) $option->id, (int) $users[0]->id));
        $removedevents = $this->removed_events($sink->get_events(), (int) $option->id);
        $sink->close();

        $this->assertCount(1, $removedevents);
        $this->assertSame((int) $users[0]->id, (int) $removedevents[0]->relateduserid);
        $this->assertSame($cmid, (int) $removedevents[0]->other['cmid']);
        $this->assertSame($this->ids($users[1]), $this->allowed_userids($option->id));

        // A user who is not on the list is not removed again and triggers nothing.
        $sink = $this->redirectEvents();
        $this->assertFalse(selectusers::remove_booked_user((int) $option->id, (int) $users[0]->id));
        $this->assertSame([], $this->removed_events($sink->get_events(), (int) $option->id));
        $sink->close();
    }

    /**
     * An option that did not tick the setting keeps its list, although the site allows the setting.
     *
     * @return void
     */
    public function test_option_without_flag_keeps_list(): void {
        $this->setAdminUser();
        [$users, $option, $cmid] = $this->create_restricted_option(3, false);

        $this->book($cmid, $option->id, $users[0]);
        $this->assertSame($this->ids($users[0], $users[1]), $this->allowed_userids($option->id));
        $this->assertFalse(selectusers::remove_booked_user((int) $option->id, (int) $users[0]->id));
    }

    /**
     * Only a real booking removes the user: a user put on the waiting list stays on the list.
     *
     * @return void
     */
    public function test_waiting_list_booking_keeps_user_on_list(): void {
        $this->setAdminUser();
        [$users, $option, $cmid] = $this->create_restricted_option(3, true, ['maxanswers' => 1, 'maxoverbooking' => 1]);

        $this->book($cmid, $option->id, $users[0]);
        $this->assertSame($this->ids($users[1]), $this->allowed_userids($option->id));

        // User 2 ends up on the waiting list.
        $this->book($cmid, $option->id, $users[1]);
        $answers = singleton_service::get_instance_of_booking_answers(
            singleton_service::get_instance_of_booking_option_settings($option->id)
        );
        $this->assertArrayHasKey($users[1]->id, $answers->get_usersonwaitinglist());
        $this->assertSame($this->ids($users[1]), $this->allowed_userids($option->id));
    }

    /**
     * With the site setting off nothing is removed, but an emptied list of a flagged option still blocks.
     *
     * @return void
     */
    public function test_site_setting_off_stops_removal_but_never_opens_an_option(): void {
        $this->setAdminUser();
        [$users, $option, $cmid] = $this->create_restricted_option(3, true);

        // Site setting off: booking leaves the list untouched.
        set_config('selectusersremoveafterbooking', 0, 'booking');
        $this->book($cmid, $option->id, $users[0]);
        $this->assertSame($this->ids($users[0], $users[1]), $this->allowed_userids($option->id));

        // Site setting on again: the next booking removes the user.
        set_config('selectusersremoveafterbooking', 1, 'booking');
        $this->book($cmid, $option->id, $users[1]);
        $this->assertSame($this->ids($users[0]), $this->allowed_userids($option->id));
        selectusers::remove_booked_user((int) $option->id, (int) $users[0]->id);
        $this->assertSame([], $this->allowed_userids($option->id));

        // The list is empty and the site setting is switched off: the option stays closed.
        set_config('selectusersremoveafterbooking', 0, 'booking');
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_SELECTUSERS, $this->blocking_condition($option->id, $users[2]->id));
    }

    /**
     * Saves that do not submit the setting, like imports or web services, keep it.
     *
     * @return void
     */
    public function test_flag_survives_a_save_without_the_form_value(): void {
        $this->setAdminUser();
        [$users, $option, $cmid, $record] = $this->create_restricted_option(3, true);

        $update = clone $record;
        $update->id = $option->id;
        $update->cmid = $cmid;
        unset($update->importing, $update->bo_cond_selectusers_removeafterbooking);
        $update->bo_cond_selectusers_userids = [$users[0]->id, $users[1]->id];
        booking_option::update($update);

        $this->assertTrue(selectusers::stored_removeafterbooking((int) $option->id));
        $this->book($cmid, $option->id, $users[0]);
        $this->assertSame($this->ids($users[1]), $this->allowed_userids($option->id));
    }

    /**
     * The form keeps the restriction with an empty list only for an option that uses the setting.
     *
     * @return void
     */
    public function test_form_round_trip_keeps_empty_list_with_flag(): void {
        $condition = selectusers::instance();
        $fromform = (object) [
            'bo_cond_selectusers_restrict' => 1,
            'bo_cond_selectusers_userids' => [],
            'bo_cond_selectusers_removeafterbooking' => 1,
        ];

        // Saving: restriction on, nobody selected, setting ticked -> condition kept with an empty list.
        $json = $condition->get_condition_object_for_json($fromform);
        $this->assertSame(MOD_BOOKING_BO_COND_JSON_SELECTUSERS, $json->id);
        $this->assertSame([], $json->userids);
        $this->assertSame(1, $json->removeafterbooking);

        // Loading: the empty list with the setting ticks the restriction and the setting.
        $defaults = new stdClass();
        $condition->set_defaults($defaults, $json);
        $this->assertSame("1", $defaults->bo_cond_selectusers_restrict);
        $this->assertSame([], $defaults->bo_cond_selectusers_userids);
        $this->assertSame("1", $defaults->bo_cond_selectusers_removeafterbooking);

        // Without the setting an empty selection drops the condition, as before.
        $fromform->bo_cond_selectusers_removeafterbooking = 0;
        $this->assertEquals(new stdClass(), $condition->get_condition_object_for_json($fromform));
        $defaults = new stdClass();
        $condition->set_defaults($defaults, (object) ['userids' => ['5']]);
        $this->assertSame("0", $defaults->bo_cond_selectusers_removeafterbooking);
        $defaults = new stdClass();
        $condition->set_defaults($defaults, (object) ['userids' => []]);
        $this->assertObjectNotHasProperty('bo_cond_selectusers_restrict', $defaults);
    }

    /**
     * The ids of the given users as integers, in the given order.
     *
     * @param stdClass ...$users
     * @return array
     */
    private function ids(stdClass ...$users): array {
        return array_map(fn($user) => (int) $user->id, $users);
    }

    /**
     * Books the user as the admin would from the booking option.
     *
     * @param int $cmid
     * @param int $optionid
     * @param stdClass $user
     * @return void
     */
    private function book(int $cmid, int $optionid, stdClass $user): void {
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        booking_option::purge_cache_for_option($optionid);
    }

    /**
     * The user ids currently on the "select users" list of the option, as stored.
     *
     * @param int $optionid
     * @return array
     */
    private function allowed_userids(int $optionid): array {
        global $DB;
        $conditions = json_decode($DB->get_field('booking_options', 'availability', ['id' => $optionid]));
        foreach ($conditions as $condition) {
            if ((int) $condition->id === MOD_BOOKING_BO_COND_JSON_SELECTUSERS) {
                return array_map('intval', $condition->userids);
            }
        }
        $this->fail('The select users condition is gone.');
    }

    /**
     * The id of the condition that currently decides the booking button, as the user sees it.
     *
     * The check runs as the user: an admin may book for others, which skips conditions like this one.
     *
     * @param int $optionid
     * @param int $userid
     * @return int
     */
    private function blocking_condition(int $optionid, int $userid): int {
        global $USER;
        $previoususer = $USER;
        $this->setUser($userid);
        singleton_service::destroy_booking_option_singleton($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $boinfo = new bo_info($settings);
        [$id] = $boinfo->is_available($settings->id, $userid, false);
        $this->setUser($previoususer);
        return (int) $id;
    }

    /**
     * The selectusers_user_removed events for the option.
     *
     * @param array $events
     * @param int $optionid
     * @return array
     */
    private function removed_events(array $events, int $optionid): array {
        return array_values(array_filter($events, function ($event) use ($optionid) {
            return $event instanceof selectusers_user_removed && (int) $event->objectid === $optionid;
        }));
    }

    /**
     * Creates users and an option only they may book.
     *
     * @param int $usercount
     * @param bool $removeafterbooking
     * @param array $extra further option fields
     * @return array users, option, cmid
     */
    private function create_restricted_option(int $usercount, bool $removeafterbooking, array $extra = []): array {
        $course = $this->getDataGenerator()->create_course();
        $users = [];
        for ($i = 0; $i < $usercount; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $users[] = $user;
        }
        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Test booking remove after booking',
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
            'sendmail' => 0,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Restricted option';
        $record->description = 'Description';
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->importing = 1;
        $record->optiondateid_0 = '0';
        $record->daystonotify_0 = '0';
        $record->coursestarttime_0 = strtotime('20 June 2050');
        $record->courseendtime_0 = strtotime('20 July 2050');
        $record->bo_cond_selectusers_restrict = 1;
        // All but the last user may book.
        $record->bo_cond_selectusers_userids = array_map(fn($user) => $user->id, array_slice($users, 0, -1));
        set_config('selectusersremoveafterbooking', 1, 'booking');
        $record->bo_cond_selectusers_removeafterbooking = $removeafterbooking ? 1 : 0;
        foreach ($extra as $key => $value) {
            $record->{$key} = $value;
        }
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_booking_option_singleton($option->id);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        return [$users, $option, (int) $settings->cmid, $record];
    }
}
