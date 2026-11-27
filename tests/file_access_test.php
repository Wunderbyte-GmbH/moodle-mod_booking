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
use context_module;
use mod_booking\local\file_access;

/**
 * Every file area of the plugin has an explicit audience: images and logos are public, attachments
 * need a login and the right to view the booking instance, and unknown areas are not delivered.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\file_access
 */
final class file_access_test extends advanced_testcase {
    /** @var context_module Context of the booking instance. */
    private context_module $context;

    /** @var \stdClass[] Users of the test by name. */
    private array $users = [];

    /**
     * Tests set up: a booking instance, a participant and a user who may not view the instance.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $this->context = context_module::instance($booking->cmid);

        foreach (['participant', 'noview'] as $name) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'student');
        }
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:view', CAP_PROHIBIT, $roleid, $this->context->id, true);
        role_assign($roleid, $this->users['noview']->id, $this->context->id);
    }

    /**
     * Who gets a file area: not logged in, as guest, as participant, without the right to view.
     *
     * @param string $filearea
     * @return bool[]
     */
    private function audience(string $filearea): array {
        $result = [];

        $this->setUser(null);
        $result['nologin'] = file_access::can_access_area($filearea, $this->context);
        $this->setGuestUser();
        $result['guest'] = file_access::can_access_area($filearea, $this->context);
        foreach ($this->users as $name => $user) {
            $this->setUser($user);
            $result[$name] = file_access::can_access_area($filearea, $this->context);
        }
        return $result;
    }

    /**
     * Images and logos are public.
     *
     * @dataProvider public_area_provider
     * @param string $filearea
     */
    public function test_images_and_logos_are_public(string $filearea): void {
        $everybody = ['nologin' => true, 'guest' => true, 'participant' => true, 'noview' => true];
        $this->assertSame($everybody, $this->audience($filearea));
    }

    /**
     * The public file areas.
     *
     * @return array
     */
    public static function public_area_provider(): array {
        return [
            ['bookingoptionimage'],
            ['bookingimages'],
            ['mod_booking_signinlogo'],
            ['signinlogoheader'],
            ['signinlogofooter'],
        ];
    }

    /**
     * Attachments of the booking instance need a login and the right to view the instance.
     */
    public function test_attachments_of_the_instance_need_login_and_view(): void {
        $this->assertSame(
            ['nologin' => false, 'guest' => false, 'participant' => true, 'noview' => false],
            $this->audience('myfilemanager')
        );
    }

    /**
     * Attachments of booking options follow the setting of the site.
     */
    public function test_attachments_of_options_follow_the_setting(): void {
        $everybody = ['nologin' => true, 'guest' => true, 'participant' => true, 'noview' => true];
        $viewers = ['nologin' => false, 'guest' => false, 'participant' => true, 'noview' => false];

        set_config('optionattachmentspublic', 0, 'booking');
        $this->assertSame($viewers, $this->audience('myfilemanageroption'));

        set_config('optionattachmentspublic', 1, 'booking');
        $this->assertSame($everybody, $this->audience('myfilemanageroption'));

        // A site that was upgraded and has not saved the setting yet keeps the behaviour it had.
        unset_config('optionattachmentspublic', 'booking');
        $this->assertSame($everybody, $this->audience('myfilemanageroption'));
    }

    /**
     * A new installation delivers attachments of options to users who may view the instance.
     */
    public function test_new_installation_keeps_option_attachments_private(): void {
        $this->assertSame('0', get_config('booking', 'optionattachmentspublic'));
    }

    /**
     * Areas without a rule are not delivered.
     *
     * @dataProvider unknown_area_provider
     * @param string $filearea
     */
    public function test_areas_without_a_rule_are_refused(string $filearea): void {
        $nobody = ['nologin' => false, 'guest' => false, 'participant' => false, 'noview' => false];
        $this->assertSame($nobody, $this->audience($filearea));
    }

    /**
     * File areas without a rule.
     *
     * @return array
     */
    public static function unknown_area_provider(): array {
        return [
            'unused area of the old list' => ['templatefile'],
            'area that does not exist' => ['somethingelse'],
        ];
    }
}
