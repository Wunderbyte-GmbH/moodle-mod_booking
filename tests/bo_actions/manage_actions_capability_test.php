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
use context_system;
use mod_booking\bo_actions\action_types\executerestscript;
use mod_booking\bo_actions\actions_info;
use mod_booking\utils\wb_payment;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->libdir . '/formslib.php');

/**
 * Booking actions can send booking data to an address chosen by the person who configures them.
 * They are only shown to and saved for users with the capability to manage booking actions,
 * which no role has by default. Saving a booking option without this capability never changes
 * the actions of the option, whatever the submitted data contains.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\option\fields\actions
 * @covers \mod_booking\bo_actions\actions_info
 * @covers \mod_booking\bo_actions\action_types\executerestscript
 */
final class manage_actions_capability_test extends advanced_testcase {
    /** @var string The capability to manage booking actions. */
    private const CAPABILITY = 'mod/booking:managebookingactions';

    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /** @var stdClass The record the booking option was created with. */
    private stdClass $record;

    /** @var int Id of the booking option. */
    private int $optionid;

    /** @var int Course module id of the booking instance. */
    private int $cmid;

    /**
     * Tests set up: an option with one action and two users who may edit the option.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        wb_payment::override_pro_version_for_tests(true);
        set_config('showboactions', 1, 'booking');

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $this->cmid = (int)$booking->cmid;
        $context = context_module::instance($this->cmid);

        foreach (['editor' => [], 'actionmanager' => [self::CAPABILITY]] as $name => $extracapabilities) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'editingteacher');
            $roleid = $this->getDataGenerator()->create_role();
            foreach (array_merge(['mod/booking:updatebooking'], $extracapabilities) as $capability) {
                assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
            }
            role_assign($roleid, $this->users[$name]->id, $context->id);
        }

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $this->record = (object)[
            'bookingid' => $booking->id,
            'text' => 'Option with an action',
            'courseid' => $course->id,
            'maxanswers' => 5,
            'json' => $this->json_with_action('https://intended.example/hook'),
        ];
        $option = $plugingenerator->create_option(clone $this->record);
        $this->optionid = (int)$option->id;

        singleton_service::destroy_instance();
        $this->assertSame(['https://intended.example/hook'], $this->stored_targets($this->optionid));
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        wb_payment::override_pro_version_for_tests(null);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * The json of an option with one action that calls an external script.
     *
     * @param string $target address of the script
     * @return string
     */
    private function json_with_action(string $target): string {
        return json_encode([
            'boactions' => [
                '1' => [
                    'id' => 1,
                    'action_type' => 'executerestscript',
                    'boactionname' => 'Call the script',
                    'rest_script' => $target,
                ],
            ],
        ]);
    }

    /**
     * The addresses the stored actions of an option call.
     *
     * @param int $optionid
     * @return string[]
     */
    private function stored_targets(int $optionid): array {
        singleton_service::destroy_instance();
        $actions = (array)(booking_option::get_value_of_json_by_key($optionid, 'boactions') ?? []);
        return array_values(array_map(fn($action) => (string)$action->rest_script, $actions));
    }

    /**
     * Save the option as a user, with actions in the submitted data.
     *
     * @param string $username
     * @param array $submitted keys of the submitted data that carry actions
     * @return void
     */
    private function save_option_as(string $username, array $submitted): void {
        $this->setUser($this->users[$username]);
        singleton_service::destroy_instance();

        $record = clone $this->record;
        $record->id = $this->optionid;
        $record->cmid = $this->cmid;
        $record->text = 'Option with an action, saved again';
        unset($record->json);
        foreach ($submitted as $key => $value) {
            $record->{$key} = $value;
        }
        booking_option::update($record);
    }

    /**
     * No role gets the capability by default.
     */
    public function test_no_role_has_the_capability_by_default(): void {
        $this->assertNotEmpty(get_capability_info(self::CAPABILITY));
        $this->assertSame([], get_roles_with_capability(self::CAPABILITY, CAP_ALLOW, context_system::instance()));
    }

    /**
     * Without the capability the stored actions stay, whatever key of the submitted data carries others.
     *
     * @dataProvider submitted_actions_provider
     * @param string $key
     * @param bool $wholejson whether the key carries the whole json of the option
     */
    public function test_actions_are_not_changed_without_the_capability(string $key, bool $wholejson): void {
        $json = $this->json_with_action('https://attacker.example/collect');
        $value = $wholejson ? $json : json_encode(json_decode($json)->boactions);

        $this->save_option_as('editor', [$key => $value]);

        $this->assertSame(['https://intended.example/hook'], $this->stored_targets($this->optionid));
        // The rest of the option was saved.
        $settings = singleton_service::get_instance_of_booking_option_settings($this->optionid);
        $this->assertSame('Option with an action, saved again', $settings->text);
    }

    /**
     * With the capability the submitted actions are saved.
     *
     * @dataProvider submitted_actions_provider
     * @param string $key
     * @param bool $wholejson whether the key carries the whole json of the option
     */
    public function test_actions_are_saved_with_the_capability(string $key, bool $wholejson): void {
        $json = $this->json_with_action('https://changed.example/hook');
        $value = $wholejson ? $json : json_encode(json_decode($json)->boactions);

        $this->save_option_as('actionmanager', [$key => $value]);

        $this->assertSame(['https://changed.example/hook'], $this->stored_targets($this->optionid));
    }

    /**
     * The keys of the submitted data that can carry actions.
     *
     * @return array
     */
    public static function submitted_actions_provider(): array {
        return [
            'the field with the actions' => ['boactionsjson', false],
            'the field with the whole json of the option' => ['json', true],
        ];
    }

    /**
     * A new option of a user without the capability has no actions.
     */
    public function test_new_option_gets_no_actions_without_the_capability(): void {
        $this->setUser($this->users['editor']);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = clone $this->record;
        $record->text = 'New option';
        $record->json = $this->json_with_action('https://attacker.example/collect');
        $option = $plugingenerator->create_option($record);

        $this->assertSame([], $this->stored_targets((int)$option->id));
    }

    /**
     * The actions are only part of the option form of users with the capability.
     */
    public function test_actions_are_only_in_the_form_of_users_with_the_capability(): void {
        global $PAGE;

        $PAGE->set_url(new \moodle_url('/mod/booking/editoptions.php'));
        $PAGE->set_context(context_module::instance($this->cmid));

        foreach (['editor' => false, 'actionmanager' => true] as $username => $expected) {
            $this->setUser($this->users[$username]);
            $mform = new \MoodleQuickForm('optionform', 'post', '');
            $formdata = ['id' => $this->optionid, 'cmid' => $this->cmid];
            actions_info::add_actions_to_mform($mform, $formdata);
            $this->assertSame($expected, $mform->elementExists('boactionsjson'), "User $username");
        }
    }

    /**
     * The request to the external script has time limits and follows no redirects.
     */
    public function test_request_to_the_script_is_limited(): void {
        $options = executerestscript::curl_options(true);

        $this->assertFalse($options['CURLOPT_FOLLOWLOCATION']);
        $this->assertGreaterThan(0, $options['CURLOPT_TIMEOUT']);
        $this->assertGreaterThan(0, $options['CURLOPT_CONNECTTIMEOUT']);
        $this->assertGreaterThanOrEqual($options['CURLOPT_CONNECTTIMEOUT'], $options['CURLOPT_TIMEOUT']);
    }
}
