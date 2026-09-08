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
 * Shared base class for the capability tests in tests/capabilities.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\tests;

use context;
use context_module;
use context_system;
use mod_booking\booking_bookit;
use mod_booking\booking_option_settings;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

/**
 * Setup helpers shared by the capability tests: one booking instance with one
 * option, users whose only role carries exactly the capabilities under test,
 * and an assertion for the documented defaults of db/access.php.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class capability_testcase extends booking_advanced_testcase {
    /** @var stdClass the course holding the booking instance */
    protected $course;

    /** @var stdClass the booking instance */
    protected $booking;

    /** @var booking_option_settings the option of the instance */
    protected $settings;

    /**
     * Creates a course, a booking instance and one booking option.
     *
     * @param array $bookingsettings overrides for the booking instance
     * @param array $optionsettings overrides for the booking option
     * @return booking_option_settings
     */
    protected function create_option(array $bookingsettings = [], array $optionsettings = []): booking_option_settings {
        $this->setAdminUser();

        $this->course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->booking = $this->getDataGenerator()->create_module('booking', $bookingsettings + [
            'name' => 'Capability test booking',
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
            'course' => $this->course->id,
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)($optionsettings + [
            'importing' => 1,
            'bookingid' => $this->booking->id,
            'text' => 'Capability test option',
            'maxanswers' => 5,
            'useprice' => 0,
        ]));

        $this->settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return $this->settings;
    }

    /**
     * Creates one more option in the same booking instance.
     *
     * @param string $text name of the option
     * @return booking_option_settings
     */
    protected function add_option(string $text = 'Second capability test option'): booking_option_settings {
        $this->setAdminUser();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'importing' => 1,
            'bookingid' => $this->booking->id,
            'text' => $text,
            'maxanswers' => 5,
            'useprice' => 0,
        ]);

        return singleton_service::get_instance_of_booking_option_settings($option->id);
    }

    /**
     * Makes the user a teacher of the option - this is what "own option"
     * means for booking_check_if_teacher().
     *
     * @param int $optionid
     * @param int $userid
     * @return void
     */
    protected function make_teacher_of(int $optionid, int $userid): void {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $DB->insert_record('booking_teachers', (object)[
            'bookingid' => (int)$settings->bookingid,
            'optionid' => $optionid,
            'userid' => $userid,
        ]);
        \mod_booking\booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Books the given number of newly created students into the option.
     *
     * @param int $count
     * @return stdClass[] the booked users
     */
    protected function book_students(int $count = 2): array {
        $this->setAdminUser();
        $students = [];
        for ($i = 0; $i < $count; $i++) {
            $student = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
            // Bookit has to be called twice: the first call only opens the booking.
            booking_bookit::bookit('option', (int)$this->settings->id, (int)$student->id);
            booking_bookit::bookit('option', (int)$this->settings->id, (int)$student->id);
            $students[] = $student;
        }
        return $students;
    }

    /**
     * Creates a user whose only role carries exactly the given capabilities,
     * assigns it in the given context and logs the user in.
     *
     * @param array $capabilities capability names to ALLOW, empty for a user without any capability
     * @param context|null $context assignment context, defaults to the module context of the option
     * @param array $prohibited capability names to PROHIBIT - needed for capabilities every
     *                          authenticated user holds through the "user" archetype
     * @return stdClass the created user
     */
    protected function user_with(array $capabilities, ?context $context = null, array $prohibited = []): stdClass {
        $context = $context ?? context_module::instance((int)$this->settings->cmid);

        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, context_system::instance()->id, true);
        }
        foreach ($prohibited as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, context_system::instance()->id, true);
        }

        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        return $user;
    }

    /**
     * Runs the access check of a dynamic form without building the form
     * itself and returns the exception it threw, or null when access was
     * granted.
     *
     * @param string $formclass
     * @param array $ajaxformdata the data the form resolves its context from
     * @return \Throwable|null
     */
    protected function run_form_access_check(string $formclass, array $ajaxformdata): ?\Throwable {
        $reflection = new \ReflectionClass($formclass);
        $form = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('_ajaxformdata');
        $property->setAccessible(true);
        $property->setValue($form, $ajaxformdata);

        $method = $reflection->getMethod('check_access_for_dynamic_submission');
        $method->setAccessible(true);

        try {
            $method->invoke($form);
            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /**
     * Runs the callback and returns the exception it threw, or null when it
     * ran through. Used for gates which check a capability first and only
     * then touch data - the capability exception is what is asserted, not the
     * data error that follows once the gate is open.
     *
     * @param callable $callback
     * @return \Throwable|null
     */
    protected function capture_exception(callable $callback): ?\Throwable {
        try {
            $callback();
            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /**
     * Asserts that the given exception is the capability exception of the
     * given capability.
     *
     * @param \Throwable|null $exception
     * @param string $capability
     * @return void
     */
    protected function assert_blocked_by_capability(?\Throwable $exception, string $capability): void {
        $this->assertInstanceOf(
            \required_capability_exception::class,
            $exception,
            "Access should have been blocked by $capability."
        );
        $this->assertStringContainsString(
            get_capability_string($capability),
            $exception->getMessage(),
            "The capability exception should name $capability."
        );
    }

    /**
     * Asserts that the capability gate was passed: either nothing was thrown
     * or the failure that followed is not a capability problem any more.
     *
     * @param \Throwable|null $exception
     * @return void
     */
    protected function assert_capability_gate_passed(?\Throwable $exception): void {
        $this->assertNotInstanceOf(
            \required_capability_exception::class,
            $exception,
            'The capability gate should have been passed.'
        );
    }

    /**
     * Asserts the context level and the archetype defaults a capability is
     * defined with in db/access.php.
     *
     * @param string $capability
     * @param int $contextlevel expected CONTEXT_* constant
     * @param array $archetypes expected archetypes with CAP_ALLOW
     * @return void
     */
    protected function assert_capability_default(string $capability, int $contextlevel, array $archetypes): void {
        global $CFG;

        $capabilities = [];
        require($CFG->dirroot . '/mod/booking/db/access.php');

        $this->assertArrayHasKey($capability, $capabilities, "$capability must be defined in db/access.php.");
        $this->assertEquals(
            $contextlevel,
            $capabilities[$capability]['contextlevel'],
            "$capability must be defined on the documented context level."
        );

        $actual = array_keys($capabilities[$capability]['archetypes'] ?? []);
        sort($actual);
        sort($archetypes);
        $this->assertSame($archetypes, $actual, "$capability must have the documented archetype defaults.");
    }
}
