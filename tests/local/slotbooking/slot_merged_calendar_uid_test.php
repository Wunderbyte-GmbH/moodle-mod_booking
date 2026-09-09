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
 * Guards the slot identity a merged multi-option calendar depends on.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\slotbooking\slot_dto;
use mod_booking\price;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Three booking options merged into one calendar must stay tellable apart.
 *
 * The sidebar calendar ([bookingoptionview optionid="A,B,C"]) merges every option's picker slots
 * into one list. Options that share their opening hours then produce the very same "start:end"
 * key, so that key alone cannot identify a slot: picking one lane's 10:00 slot selected the 10:00
 * slot of every merged option, the day badge counted them all, and the "only one option at a
 * time" guard could never see a switch because every key resolved to the same option. The
 * option-scoped uid is what the JS keys its selection on instead (see slotCalendarPicker's
 * prepareData and condition/slotBooking.js's slotsByUid).
 *
 * @covers \mod_booking\local\slotbooking\slot_dto::build_picker_slots
 */
final class slot_merged_calendar_uid_test extends booking_advanced_testcase {
    /** @var int Number of options merged into the calendar. */
    private const OPTION_COUNT = 3;

    /**
     * Setup.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Every slot carries a uid that is unique across the merged options, while the wire key is not.
     *
     * @return void
     */
    public function test_merged_options_produce_unique_uids_for_colliding_keys(): void {

        [$optionids, $userid] = $this->create_identical_slot_options();

        $merged = $this->merge_picker_slots($optionids, $userid);
        $this->assertNotEmpty($merged, 'The fixture must produce bookable slots.');

        $keys = array_column($merged, 'key');
        $uids = array_column($merged, 'uid');

        /* The premise of the whole bug: identical opening hours mean the time-only key really does
        collide, so it cannot be what the calendar identifies a slot by. */
        $this->assertCount(
            (int)(count($merged) / self::OPTION_COUNT),
            array_unique($keys),
            'Options sharing their opening hours must share their "start:end" keys - '
                . 'otherwise this test no longer covers the case it was written for.'
        );

        // What the merged calendar actually relies on.
        $this->assertCount(
            count($merged),
            array_unique($uids),
            'Every merged slot needs its own uid, or picking one lane selects the same time in all.'
        );
    }

    /**
     * A uid resolves back to exactly the option the slot belongs to.
     *
     * This is what enforceSingleOptionSelection() needs: without it the "you can only book slots
     * of one option at a time" notice never fires and the wrong option gets booked.
     *
     * @return void
     */
    public function test_uid_resolves_back_to_its_own_option(): void {

        [$optionids, $userid] = $this->create_identical_slot_options();

        $byuid = [];
        foreach ($this->merge_picker_slots($optionids, $userid) as $slot) {
            $byuid[(string)$slot['uid']] = $slot;
        }

        foreach ($optionids as $optionid) {
            $ownuids = array_filter(
                $byuid,
                static fn(array $slot): bool => (int)$slot['optionid'] === $optionid
            );
            $this->assertNotEmpty($ownuids, "Option $optionid contributed no slots to the calendar.");

            foreach ($ownuids as $uid => $slot) {
                // The composite the JS builds when it converts a submitted wire key back to a uid
                // (selectionKeysToUids: `${activeOptionId}:${key}`).
                $this->assertSame(
                    $optionid . ':' . $slot['key'],
                    $uid,
                    'The uid must be the option id followed by the wire key, so the JS can convert '
                        . 'between the two without a lookup table.'
                );
            }
        }
    }

    /**
     * The wire key stays free of the option id, because that is what the server parses.
     *
     * save_slot_selection() takes the optionid as its own parameter and reads the selection as
     * "start:end" pairs, so widening the key would break the booking call itself.
     *
     * @return void
     */
    public function test_wire_key_stays_a_bare_start_end_pair(): void {

        [$optionids, $userid] = $this->create_identical_slot_options();

        foreach ($this->merge_picker_slots($optionids, $userid) as $slot) {
            $this->assertSame(
                $slot['start'] . ':' . $slot['end'],
                (string)$slot['key'],
                'The submitted key must stay the bare timestamp pair.'
            );
            // Strip the uid the way the renderers do to get back to a wire key.
            $this->assertSame(
                (string)$slot['key'],
                implode(':', array_slice(explode(':', (string)$slot['uid']), -2)),
                'Stripping the option id off a uid must yield exactly the wire key.'
            );
        }
    }

    /**
     * Looking a merged slot up by its uid yields that option's OWN price and examiners.
     *
     * The selected-slots summary and the examiner picker used to read slot_dto data out of a map
     * keyed by the time-only wire key. Building that map over a merged calendar keeps only the
     * option merged LAST under any given time, so a slot picked in the first option was described
     * with the last one's price and offered the last one's examiners - and that examiner choice
     * was then submitted. Only a uid-keyed lookup survives the merge.
     *
     * @return void
     */
    public function test_uid_lookup_returns_the_slots_own_price_and_examiners(): void {

        [$optionids, $userid, $expected] = $this->create_distinct_slot_options();

        $merged = $this->merge_picker_slots($optionids, $userid);
        $this->assertNotEmpty($merged);

        // The two maps the frontend can build over the merged list.
        $bywirekey = [];
        $byuid = [];
        foreach ($merged as $slot) {
            $bywirekey[(string)$slot['key']] = $slot;
            $byuid[(string)$slot['uid']] = $slot;
        }

        // A time every option offers - the collision the merged calendar has to survive.
        $sharedkey = (string)$merged[0]['key'];
        $sharedslots = array_values(array_filter(
            $merged,
            static fn(array $slot): bool => (string)$slot['key'] === $sharedkey
        ));
        $this->assertCount(
            self::OPTION_COUNT,
            $sharedslots,
            'Every option must offer this time, or the collision is not being covered.'
        );

        foreach ($sharedslots as $slot) {
            $optionid = (int)$slot['optionid'];
            $viauid = $byuid[(string)$slot['uid']];

            // What the fix guarantees: the uid resolves to this option's own data.
            $this->assertSame($optionid, (int)$viauid['optionid']);
            $this->assertEqualsWithDelta(
                $expected[$optionid]['price'],
                (float)$viauid['price'],
                0.001,
                'The uid lookup must return the price configured for THIS option.'
            );
            $this->assertSame(
                $expected[$optionid]['examiners'],
                $this->examiner_names($viauid),
                'The uid lookup must return the examiners of THIS option.'
            );
        }

        /* And the counter-example that makes the assertions above meaningful: the wire-key map
        collapses all of them onto one single option, so at least one slot is described by data
        that is not its own. */
        $mismatched = array_filter(
            $sharedslots,
            static fn(array $slot): bool => (int)$bywirekey[(string)$slot['key']]['optionid'] !== (int)$slot['optionid']
        );
        $this->assertCount(
            self::OPTION_COUNT - 1,
            $mismatched,
            'A wire-key map must collapse the merged options - that is why the uid exists.'
        );
    }

    /**
     * The examiner names a slot offers, sorted so the comparison does not depend on order.
     *
     * @param array $slot picker slot DTO
     * @return array<int, string>
     */
    private function examiner_names(array $slot): array {

        $names = array_map(
            static fn(array $teacher): string => (string)($teacher['fullname'] ?? ''),
            $slot['teachers'] ?? []
        );
        sort($names);
        return $names;
    }

    /**
     * Merge every option's picker slots the way the sidebar calendar does.
     *
     * @param array $optionids
     * @param int $userid
     * @return array the merged slot DTOs
     */
    private function merge_picker_slots(array $optionids, int $userid): array {

        // Mirrors slotbooking_form.php: the primary option's slots plus every additional one's.
        $merged = [];
        foreach ($optionids as $optionid) {
            $merged = array_merge($merged, slot_dto::build_picker_slots($optionid, $userid));
        }
        return $merged;
    }

    /**
     * Create three slot options that share their opening hours, as the T10 scenario does.
     *
     * @return array{0:array<int, int>, 1:int} the option ids and an enrolled student
     */
    private function create_identical_slot_options(): array {

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $optionids = [];
        foreach (range(1, self::OPTION_COUNT) as $index) {
            $record = [
                'bookingid' => $booking->id,
                'text' => 'Merged room ' . $index,
                'course' => $course->id,
                'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
                'maxanswers' => 20,
                'slot_enabled' => 1,
                // All merged options must share the slot type, otherwise the shortcode refuses to
                // merge them at all (shortcode:slotbookingtypemismatch).
                'slot_type' => 'fixed',
                'slot_booking_view_mode' => 'calendar',
                'slot_duration_minutes' => 60,
                'slot_interval_minutes' => 60,
                // Identical hours on purpose - this is what makes the keys collide.
                'slot_opening_time' => '09:00',
                'slot_closing_time' => '13:00',
                'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
                'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
                'slot_max_participants_per_slot' => 3,
                'slot_max_slots_per_user' => 2,
                'slot_add_examiners' => 0,
                'slot_teachers_required' => 0,
            ];
            for ($day = 1; $day <= 7; $day++) {
                $record['slot_day_' . $day] = 1;
            }

            $option = $plugingenerator->create_option((object)$record);
            $optionids[] = (int)$option->id;
        }

        singleton_service::destroy_instance();

        return [$optionids, (int)$student->id];
    }

    /**
     * Three merged options that share their hours but differ in price and examiners.
     *
     * Identical rooms would hide the bug this guards: every value would match by accident no
     * matter which option it was read from.
     *
     * @return array{0:array<int,int>, 1:int, 2:array<int, array{price:float, examiners:array}>}
     */
    private function create_distinct_slot_options(): array {

        global $DB;

        $course = self::getDataGenerator()->create_course();
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = $plugingenerator->create_instance(['course' => $course->id]);
        $student = self::getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        /* A fresh test site has no price categories at all, and without one there is nothing for
        price::add_price() to attach a price to - every slot would come back at 0.00 and the
        per-option price this test is about could never differ. */
        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'Default',
            'identifier' => 'default',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
        ]);

        $rooms = [
            ['price' => 10.0, 'examiners' => [['Anna', 'Ahorn'], ['Andreas', 'Alt']]],
            ['price' => 20.0, 'examiners' => [['Berta', 'Birke'], ['Bernd', 'Bach']]],
            ['price' => 30.0, 'examiners' => [['Clara', 'Cedar'], ['Carl', 'Cluster']]],
        ];

        $optionids = [];
        $expected = [];
        foreach ($rooms as $index => $room) {
            $pool = [];
            $names = [];
            foreach ($room['examiners'] as [$firstname, $lastname]) {
                $teacher = self::getDataGenerator()->create_user([
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                ]);
                $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
                $pool[] = (int)$teacher->id;
                $names[] = fullname($teacher);
            }
            sort($names);

            $record = [
                'bookingid' => $booking->id,
                'text' => 'Distinct room ' . ($index + 1),
                'course' => $course->id,
                'optiontype' => MOD_BOOKING_OPTIONTYPE_SLOTBOOKING,
                'maxanswers' => 20,
                'useprice' => 1,
                'slot_enabled' => 1,
                'slot_type' => 'fixed',
                'slot_booking_view_mode' => 'calendar',
                'slot_duration_minutes' => 60,
                'slot_interval_minutes' => 60,
                // Identical hours on purpose - this is what makes the wire keys collide.
                'slot_opening_time' => '09:00',
                'slot_closing_time' => '13:00',
                'slot_valid_from' => strtotime('2050-01-07 00:00:00 UTC'),
                'slot_valid_until' => strtotime('2050-01-10 23:59:59 UTC'),
                'slot_max_participants_per_slot' => 3,
                'slot_max_slots_per_user' => 2,
                'slot_add_examiners' => 1,
                'slot_teacher_pool' => $pool,
                'slot_teachers_required' => 1,
            ];
            for ($day = 1; $day <= 7; $day++) {
                $record['slot_day_' . $day] = 1;
            }

            $option = $plugingenerator->create_option((object)$record);
            $optionid = (int)$option->id;
            $optionids[] = $optionid;

            /* Price categories are site-wide, so their defaults cannot differ per option - give
            this option its own price for every category instead. */
            foreach ($DB->get_records('booking_pricecategories', ['disabled' => 0]) as $category) {
                price::add_price('option', $optionid, $category->identifier, (string)$room['price'], 'EUR', false);
            }

            $expected[$optionid] = ['price' => $room['price'], 'examiners' => $names];
        }

        singleton_service::destroy_instance();

        return [$optionids, (int)$student->id, $expected];
    }
}
