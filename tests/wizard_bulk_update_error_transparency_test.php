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
 * Tests for bulk/single option mutation error transparency (per-option postconditions).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_module;
use mod_booking\local\wizard\booking\booking_skill_mutation_execute_service;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\skill_catalog_discovery;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Error transparency of the mutation execute service (WP3C/WP3E).
 *
 * A bulk update that fails verification for some options must surface WHICH option failed on
 * WHICH field — structured (failed_postconditions with optionid, issue_codes, persisted_fields)
 * and in the user-facing detail — instead of the bare generic "Bulk update completed. Status:
 * error" string that used to swallow everything. A single update with a failed postcondition
 * must name the fields that DID save (persisted_fields, "Saved: …" detail lead).
 *
 * The failing fixture follows the existing verification tests: a headerimage_token that resolves
 * to nothing makes exactly that one field fail its postcondition (POSTCOND_HEADERIMAGE_MISSING)
 * while all other requested fields persist normally.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\booking\booking_skill_mutation_execute_service
 */
final class wizard_bulk_update_error_transparency_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        $this->skip_without_agent_extension();
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * Bulk update where one field fails verification: the result must carry per-option
     * failed_postconditions (each with its optionid), aggregated issue_codes, per-option
     * persisted_fields, and a detail that names the failed options and the failed field.
     */
    public function test_bulk_failure_reports_per_option_postconditions(): void {
        global $USER;
        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options();

        $service = new booking_skill_mutation_execute_service($this->attachment_token_service());
        $result = $service->execute(
            bulk_update_options_skill::TASK_NAME,
            [
                'optionids' => [$optionid1, $optionid2],
                'maxanswers' => 7,
                'headerimage_token' => 'tok_does_not_exist',
            ],
            $cmid,
            (int)$USER->id,
            $this->skill_support()
        );

        $this->assertEquals('error', $result['status'] ?? '');

        // Structured per-option failures, each carrying its optionid.
        $failures = (array)($result['failed_postconditions'] ?? []);
        $this->assertNotEmpty($failures, 'Bulk failure must expose structured failed_postconditions.');
        $failedoptionids = [];
        foreach ($failures as $failure) {
            $this->assertArrayHasKey('optionid', $failure, 'Every bulk failure entry must carry its optionid.');
            $failedoptionids[] = (int)$failure['optionid'];
            $this->assertNotEmpty($failure['message'] ?? '', 'Every bulk failure entry must carry the verifier message.');
        }
        $this->assertContains($optionid1, $failedoptionids);
        $this->assertContains($optionid2, $failedoptionids);

        // Aggregated deterministic issue codes.
        $issuecodes = (array)($result['issue_codes'] ?? []);
        $this->assertContains('POSTCONDITION_FAILED', $issuecodes);
        $this->assertContains('POSTCONDITION_FAILED_OPTION_MUTATION', $issuecodes);
        $this->assertContains('POSTCOND_HEADERIMAGE_MISSING', $issuecodes);

        // Detail names the failed option (with a real link) and the failed field.
        $detail = (string)($result['detail'] ?? '');
        $this->assertStringContainsString('#' . $optionid1, $detail);
        $this->assertStringContainsString('#' . $optionid2, $detail);
        $this->assertStringContainsString('header image', $detail, 'Detail must name the failed field.');
        $this->assertStringContainsString('/mod/booking/view.php', $detail, 'Option mentions must carry moodle_url links.');

        // The field that DID save is reported per option as persisted.
        $persisted = (array)($result['persisted_fields'] ?? []);
        $this->assertCount(2, $persisted);
        foreach ($persisted as $entry) {
            $this->assertContains((int)($entry['optionid'] ?? 0), [$optionid1, $optionid2]);
            $this->assertContains('maxanswers', (array)($entry['fields'] ?? []));
            $this->assertNotContains('headerimage_token', (array)($entry['fields'] ?? []));
        }

        // The uncapped full account stays in observation_full.
        $observation = (string)($result['observation_full'] ?? '');
        $this->assertStringContainsString('BULK VERIFICATION', $observation);
        $this->assertStringContainsString('Option ' . $optionid1, $observation);
        $this->assertStringContainsString('Option ' . $optionid2, $observation);

        // The saved field really landed despite the error status.
        singleton_service::destroy_booking_option_singleton($optionid1);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid1);
        $this->assertEquals(7, (int)$settings->maxanswers);
    }

    /**
     * Skill level (thread-audit regression): the usermessage of a failed bulk update must NOT be
     * the bare generic "Bulk update completed. Status: error" string — the service detail with the
     * per-option failure information has to survive.
     */
    public function test_bulk_failure_usermessage_keeps_service_detail(): void {
        global $USER;
        [$cmid, $optionid1] = $this->create_booking_with_two_options();
        $contextid = (int)context_module::instance($cmid)->id;

        $result = (new bulk_update_options_skill())->execute(
            [
                'optionids' => [$optionid1],
                'maxanswers' => 5,
                'headerimage_token' => 'tok_does_not_exist',
            ],
            $contextid,
            (int)$USER->id
        );

        $this->assertEquals('error', $result['status'] ?? '');
        $usermessage = (string)($result['usermessage'] ?? '');
        $generic = get_string('agent_booking_bulk_update_completed', 'booking', 'error');
        $this->assertNotEquals($generic, $usermessage, 'The generic string alone swallows the failure information.');
        $this->assertStringContainsString('#' . $optionid1, $usermessage, 'Failed option must be named to the user.');
        $this->assertStringContainsString('header image', $usermessage, 'Failed field must be named to the user.');
    }

    /**
     * All-green bulk update stays unchanged: generic usermessage, executed status, plus the new
     * per-option persisted_fields.
     */
    public function test_bulk_all_green_keeps_generic_usermessage(): void {
        global $USER;
        [$cmid, $optionid1, $optionid2] = $this->create_booking_with_two_options();
        $contextid = (int)context_module::instance($cmid)->id;

        $result = (new bulk_update_options_skill())->execute(
            ['optionids' => [$optionid1, $optionid2], 'maxanswers' => 9],
            $contextid,
            (int)$USER->id
        );

        $this->assertEquals('executed', $result['status'] ?? '', (string)($result['detail'] ?? ''));
        $this->assertEquals(
            get_string('agent_booking_bulk_update_completed', 'booking', 'executed'),
            (string)($result['usermessage'] ?? ''),
            'All-green bulk keeps the plain generic usermessage.'
        );
        $this->assertStringContainsString('Updated 2 booking option(s)', (string)($result['detail'] ?? ''));

        $persisted = (array)($result['persisted_fields'] ?? []);
        $this->assertCount(2, $persisted);
        foreach ($persisted as $entry) {
            $this->assertContains('maxanswers', (array)($entry['fields'] ?? []));
        }
    }

    /**
     * Single update with one failed postcondition: persisted_fields lists the fields that DID
     * save and the detail leads with "Saved: …; not confirmed: …" — faithful error status stays.
     */
    public function test_single_update_failure_leads_with_saved_fields(): void {
        global $USER;
        [$cmid, $optionid1] = $this->create_booking_with_two_options();

        $service = new booking_skill_mutation_execute_service($this->attachment_token_service());
        $result = $service->execute(
            update_option_skill::TASK_NAME,
            [
                'optionid' => $optionid1,
                'text' => 'Renamed via agent',
                'headerimage_token' => 'tok_does_not_exist',
            ],
            $cmid,
            (int)$USER->id,
            $this->skill_support()
        );

        $this->assertEquals('error', $result['status'] ?? '', 'Faithful error status must stay.');
        $this->assertEquals('failed', $result['postcondition_status'] ?? '');
        $this->assertEquals(['text'], (array)($result['persisted_fields'] ?? []));

        $detail = (string)($result['detail'] ?? '');
        $this->assertStringStartsWith(
            'Saved: text; not confirmed: headerimage_token — ',
            $detail,
            'Detail must lead with the saved/not-confirmed split.'
        );
        $this->assertStringContainsString('Postcondition failed:', $detail);

        $codes = array_map(
            static fn(array $failure): string => (string)($failure['code'] ?? ''),
            (array)($result['failed_postconditions'] ?? [])
        );
        $this->assertContains('POSTCOND_HEADERIMAGE_MISSING', $codes);

        // The title change really landed.
        singleton_service::destroy_booking_option_singleton($optionid1);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid1);
        $this->assertEquals('Renamed via agent', trim((string)$settings->text));
    }

    /**
     * Single update all-green: persisted_fields reports the verified fields.
     */
    public function test_single_update_success_reports_persisted_fields(): void {
        global $USER;
        [$cmid, $optionid1] = $this->create_booking_with_two_options();

        $service = new booking_skill_mutation_execute_service($this->attachment_token_service());
        $result = $service->execute(
            update_option_skill::TASK_NAME,
            ['optionid' => $optionid1, 'text' => 'Green title', 'maxanswers' => 11],
            $cmid,
            (int)$USER->id,
            $this->skill_support()
        );

        $this->assertEquals('executed', $result['status'] ?? '', (string)($result['detail'] ?? ''));
        $this->assertEquals('passed', $result['postcondition_status'] ?? '');
        $persisted = (array)($result['persisted_fields'] ?? []);
        $this->assertContains('text', $persisted);
        $this->assertContains('maxanswers', $persisted);
    }

    /**
     * Create a course, a booking instance and two options. Returns [cmid, optionid1, optionid2].
     *
     * @return array{0:int,1:int,2:int}
     */
    private function create_booking_with_two_options(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Bulk transparency booking',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'First option';
        $record->courseid = $course->id;
        $record->importing = 1;
        $optionid1 = (int)$plugingenerator->create_option($record)->id;

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Second option';
        $record->importing = 1;
        $optionid2 = (int)$plugingenerator->create_option($record)->id;

        singleton_service::destroy_instance();

        return [(int)$booking->cmid, $optionid1, $optionid2];
    }

    /**
     * Booking skill support wired with the active engine's services.
     *
     * @return booking_skill_support
     */
    private function skill_support(): booking_skill_support {
        return new booking_skill_support(
            $this->attachment_token_service(),
            $this->thread_memory(),
            new skill_catalog_discovery()
        );
    }

    /**
     * Attachment token service of the active engine.
     *
     * @return object
     */
    private function attachment_token_service(): object {
        $class = \mod_booking\local\wizard\engine\engine_resolver::fqcn('services\\attachment\\attachment_token_service');
        return new $class();
    }

    /**
     * Conversation thread memory of the active engine.
     *
     * @return object
     */
    private function thread_memory(): object {
        $class = \mod_booking\local\wizard\engine\engine_resolver::fqcn('services\\conversation_thread_memory');
        return new $class();
    }
}
