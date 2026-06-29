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
 * Tests for the bookingoptionimage option field.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use context_module;
use context_user;
use mod_booking\option\fields\bookingoptionimage;
use mod_booking\local\wizard\options\skills\option_input_verification;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\booking\booking_skill_mutation_execute_service;
use bookingextension_agent\local\wizard\services\preview_passthrough;
use bookingextension_agent\local\wizard\services\attachment\attachment_token_service;
use bookingextension_agent\local\wizard\services\conversation_thread_memory;
use bookingextension_agent\local\wizard\services\skill_catalog_discovery;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the bookingoptionimage option field, focusing on server-side (form-independent) saving.
 *
 * Background: booking_option::update() runs fields_info::set_data() in importing mode, which calls
 * bookingoptionimage::set_data(). Historically that method always rebuilt the draft area from the
 * option's stored files, discarding any draft a server-side caller (CSV/WS/agent) had staged in
 * $data->bookingoptionimage. These tests lock in the additive alternative path (respect an already
 * populated, staged draft) while proving the interactive-form behaviour is unchanged.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\option\fields\bookingoptionimage
 */
final class bookingoptionimage_test extends advanced_testcase {
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
     * set_data() must keep a draft a caller already staged (no HTTP form submission present).
     *
     * This is the new, form-independent path used by server-side importers.
     */
    public function test_set_data_keeps_staged_draft(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Stage a populated user draft area, exactly as a server-side caller would.
        $stageddraftid = $this->stage_user_draft_with_fixture('volleyball.png');

        $data = new stdClass();
        $data->id = $optionid;
        $data->cmid = $cmid;
        $data->bookingoptionimage = $stageddraftid;

        bookingoptionimage::set_data($data, $settings);

        // The staged draft must survive untouched.
        $this->assertEquals(
            $stageddraftid,
            (int)$data->bookingoptionimage,
            'set_data() overwrote the caller-staged draft itemid.'
        );
    }

    /**
     * Regression: without a staged draft and without a submission, set_data() still rebuilds the
     * draft from the option's stored files (the long-standing interactive-form behaviour).
     */
    public function test_set_data_rebuilds_draft_when_nothing_staged(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();

        // Give the option an existing stored image.
        $this->store_option_image($cmid, $optionid, 'yoga.png');
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $data = new stdClass();
        $data->id = $optionid;
        $data->cmid = $cmid;
        // Deliberately no bookingoptionimage staged and no $_POST submission.

        bookingoptionimage::set_data($data, $settings);

        // Set_data() must have created a draft and copied the existing file into it.
        $this->assertNotEmpty($data->bookingoptionimage ?? null, 'set_data() did not build a draft.');
        $fs = get_file_storage();
        $usercontext = context_user::instance($GLOBALS['USER']->id);
        $draftfiles = $fs->get_area_files(
            $usercontext->id,
            'user',
            'draft',
            (int)$data->bookingoptionimage,
            'id',
            false
        );
        $this->assertCount(1, $draftfiles, 'Existing stored image was not copied into the rebuilt draft.');
        $file = reset($draftfiles);
        $this->assertEquals('yoga.png', $file->get_filename());
    }

    /**
     * Integration: booking_option::update() persists a header image staged only via
     * $data->bookingoptionimage (no $_POST, no sesskey emulation).
     */
    public function test_update_persists_staged_header_image(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();
        $context = context_module::instance($cmid);
        $fs = get_file_storage();

        // No image before.
        $this->assertCount(
            0,
            $fs->get_area_files($context->id, 'mod_booking', 'bookingoptionimage', $optionid, 'id', false)
        );

        $stageddraftid = $this->stage_user_draft_with_fixture('volleyball.png');

        $data = new stdClass();
        $data->id = $optionid;
        $data->cmid = $cmid;
        $data->importing = true;
        $data->bookingoptionimage = $stageddraftid;

        booking_option::update($data, $context);

        $after = $fs->get_area_files($context->id, 'mod_booking', 'bookingoptionimage', $optionid, 'id', false);
        $this->assertCount(1, $after, 'Staged header image was not persisted by booking_option::update().');
        $file = reset($after);
        $this->assertEquals('volleyball.png', $file->get_filename());
    }

    /**
     * Regression: a header image saved through the agent's headerimage_token path must end up with a
     * non-null files.source, otherwise booking_option_settings::load_imageurl_from_db() (which filters on
     * "source IS NOT NULL") never derives imageurl and the saved image stays invisible in the GUI.
     */
    public function test_headerimage_token_save_yields_non_null_source_and_imageurl(): void {
        global $USER, $CFG;

        [$cmid, $optionid] = $this->create_booking_with_option();
        $context = context_module::instance($cmid);
        $fs = get_file_storage();

        // The service resolves a token via attachment_token_service and then deletes the temp file,
        // so copy the fixture to a throwaway temp path that the token may consume.
        $tmppath = make_request_directory() . '/upload.png';
        copy($CFG->dirroot . '/mod/booking/tests/fixtures/volleyball.png', $tmppath);

        $tokensvc = new \bookingextension_agent\local\wizard\services\attachment\attachment_token_service();
        $token = $tokensvc->create((int)$USER->id, (int)$context->id, $tmppath, 'image/png', 'volleyball.png');

        $data = new stdClass();
        $data->id = $optionid;
        $data->cmid = $cmid;
        $data->importing = true;

        // Drive the real private staging method (the code path under test).
        $service = new booking_skill_mutation_execute_service(new attachment_token_service());
        $method = new \ReflectionMethod($service, 'apply_headerimage_token_to_data');
        $method->setAccessible(true);
        // The $data variable is a by-reference parameter, so the args array must hold it by reference.
        $args = [['headerimage_token' => $token], &$data, (int)$USER->id, (int)$context->id];
        $method->invokeArgs($service, $args);

        booking_option::update($data, $context);

        // The persisted file must carry a non-null source (the fix).
        $after = $fs->get_area_files($context->id, 'mod_booking', 'bookingoptionimage', $optionid, 'id', false);
        $this->assertCount(1, $after, 'Header image staged via token was not persisted.');
        $file = reset($after);
        $this->assertNotNull($file->get_source(), 'Persisted header image has a NULL source; imageurl will not be derived.');

        // End-to-end: booking_option_settings must now derive a non-empty imageurl.
        singleton_service::destroy_booking_option_singleton($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertNotEmpty($settings->imageurl, 'imageurl not derived despite a persisted header image.');
    }

    /**
     * Integration regression: an update() without a staged image must not wipe the existing one.
     */
    public function test_update_without_staged_draft_keeps_existing_image(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();
        $context = context_module::instance($cmid);
        $fs = get_file_storage();

        $this->store_option_image($cmid, $optionid, 'fussball.png');
        $this->assertCount(
            1,
            $fs->get_area_files($context->id, 'mod_booking', 'bookingoptionimage', $optionid, 'id', false)
        );

        // Update an unrelated field, no image staged.
        $data = new stdClass();
        $data->id = $optionid;
        $data->cmid = $cmid;
        $data->importing = true;
        $data->text = 'Renamed option';

        booking_option::update($data, $context);

        $after = $fs->get_area_files($context->id, 'mod_booking', 'bookingoptionimage', $optionid, 'id', false);
        $this->assertCount(1, $after, 'Existing image was wiped by an unrelated update.');
        $file = reset($after);
        $this->assertEquals('fussball.png', $file->get_filename());
    }

    /**
     * Postcondition must FAIL when an image was requested (headerimage_token) but none is stored.
     *
     * This reproduces the thread-192 situation: update reported success but no image landed.
     */
    public function test_postcondition_fails_when_image_requested_but_missing(): void {
        [, $optionid] = $this->create_booking_with_option();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $failures = option_input_verification::verify_common_fields_structured(
            ['headerimage_token' => 'sometoken123'],
            $settings
        );

        $codes = array_map(static fn(array $f): string => (string)($f['code'] ?? ''), $failures);
        $this->assertContains(
            'POSTCOND_HEADERIMAGE_MISSING',
            $codes,
            'A requested-but-missing header image must fail the postcondition.'
        );
    }

    /**
     * Postcondition must PASS when an image was requested and is actually stored.
     */
    public function test_postcondition_passes_when_image_requested_and_present(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();
        $this->store_option_image($cmid, $optionid, 'volleyball.png');
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $failures = option_input_verification::verify_common_fields_structured(
            ['headerimage_token' => 'sometoken123'],
            $settings
        );

        $codes = array_map(static fn(array $f): string => (string)($f['code'] ?? ''), $failures);
        $this->assertNotContains('POSTCOND_HEADERIMAGE_MISSING', $codes);
    }

    /**
     * Postcondition must NOT check the image when none was requested (no headerimage_token),
     * even if the option has no image at all. An empty image is a legitimate state.
     */
    public function test_postcondition_skips_image_when_not_requested(): void {
        [, $optionid] = $this->create_booking_with_option();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $failures = option_input_verification::verify_common_fields_structured(
            ['text' => $settings->text],
            $settings
        );

        $codes = array_map(static fn(array $f): string => (string)($f['code'] ?? ''), $failures);
        $this->assertNotContains(
            'POSTCOND_HEADERIMAGE_MISSING',
            $codes,
            'Image must not be verified when it was not requested.'
        );
    }

    /**
     * Teil 1: a successful single update produces a deterministic verification observation
     * (observation_full + optiondetails) for the next planner turn.
     */
    public function test_update_emits_verification_observation(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();

        $service = new booking_skill_mutation_execute_service(new attachment_token_service());
        $support = new booking_skill_support(
            new attachment_token_service(),
            new conversation_thread_memory(),
            new skill_catalog_discovery()
        );
        $result = $service->execute(
            update_option_skill::TASK_NAME,
            ['optionid' => $optionid, 'text' => 'Verified title'],
            $cmid,
            (int)$GLOBALS['USER']->id,
            $support
        );

        $this->assertEquals('executed', $result['status'] ?? '');
        $this->assertArrayHasKey('observation_full', $result);
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('VERIFICATION REQUIRED', $observation);
        // The compact observation reflects the requested field's freshly-read value.
        $this->assertStringContainsString('title: "Verified title"', $observation);
        // It must NOT carry a full option-details payload (that would reclassify the write as a read
        // and drop the postcondition status).
        $this->assertArrayNotHasKey('optiondetails', $result);
        $this->assertEquals('passed', $result['postcondition_status'] ?? '');
    }

    /**
     * The verification summary reports the header image's present/absent state, so the planner can
     * actually verify the one field that previously slipped through (thread-195 finding).
     */
    public function test_summarize_requested_state_reports_image_status(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();

        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $lines = option_input_verification::summarize_requested_state(['headerimage_token' => 'tok'], $settings);
        $this->assertContains('header image: MISSING', $lines);

        $this->store_option_image($cmid, $optionid, 'volleyball.png');
        singleton_service::destroy_booking_option_singleton($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $lines = option_input_verification::summarize_requested_state(['headerimage_token' => 'tok'], $settings);
        $this->assertContains('header image: PRESENT', $lines);

        // Without a requested image token, the image is not part of the summary at all.
        $lines = option_input_verification::summarize_requested_state(['text' => $settings->text], $settings);
        $imagelines = array_filter($lines, static fn(string $l): bool => str_contains($l, 'header image'));
        $this->assertEmpty($imagelines);
    }

    /**
     * Teil 3: bulk update emits a compact per-option pass/fail verification observation.
     */
    public function test_bulk_update_emits_compact_verification_observation(): void {
        [$cmid, $optionid1] = $this->create_booking_with_option();

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Second option';
        $record->importing = 1;
        $optionid2 = (int)$plugingenerator->create_option($record)->id;
        singleton_service::destroy_instance();

        $service = new booking_skill_mutation_execute_service(new attachment_token_service());
        $support = new booking_skill_support(
            new attachment_token_service(),
            new conversation_thread_memory(),
            new skill_catalog_discovery()
        );
        $result = $service->execute(
            bulk_update_options_skill::TASK_NAME,
            ['optionids' => [$optionid1, $optionid2], 'maxanswers' => 7],
            $cmid,
            (int)$GLOBALS['USER']->id,
            $support
        );

        $this->assertEquals('executed', $result['status'] ?? '');
        $this->assertArrayHasKey('observation_full', $result);
        $this->assertStringContainsString('BULK VERIFICATION', (string)$result['observation_full']);
        $this->assertStringContainsString('Option ' . $optionid1, (string)$result['observation_full']);
        $this->assertStringContainsString('Option ' . $optionid2, (string)$result['observation_full']);
    }

    /**
     * preview_passthrough must find a preview when the skill ran as an internal LOOP step
     * (read skills like get_option_details/explain_docs produce their result in loop_results, not in
     * the terminal top-level results). The executor attaches a self-contained 'preview' block to the
     * step result; preview_passthrough just forwards it (no skill call, no rendering here).
     */
    public function test_preview_passthrough_scans_loop_results(): void {
        $cmid = $this->create_booking_with_option()[0];
        $context = context_module::instance($cmid);
        $userid = (int)$GLOBALS['USER']->id;

        $cm = get_coursemodule_from_id('booking', $cmid);
        $store = new \bookingextension_agent\local\wizard\conversation_store();
        $thread = $store->get_or_create_thread($userid, (int)$context->id, (int)$cm->instance);

        // Terminal results empty; the read skill ran as a loop step and carries its precomputed block.
        $loopresults = [[
            'step' => 1,
            'results' => [[
                'skill' => 'mod_booking.get_option_details',
                'status' => 'executed',
                'preview' => [
                    'type' => 'booking_option',
                    'html' => '<div class="opt-preview">Option preview</div>',
                    'payload' => ['optionids' => [$this->create_booking_with_option()[1]]],
                ],
            ]],
        ]];

        $json = preview_passthrough::resolve_preview_json([], (int)$thread->id, '_test_loop_previews', $loopresults);

        $this->assertNotEmpty($json, 'Preview must be found in loop_results, not only top-level results.');
        $descriptor = json_decode($json, true);
        $this->assertEquals('booking_option', $descriptor['type'] ?? '');
        $this->assertNotEmpty($descriptor['html'] ?? '');
    }

    /**
     * A bulk update whose optionquery matches nothing must return a search-correction payload
     * (issue_code OPTION_QUERY_NO_MATCH + candidate options + a "do not repeat" hint) instead of a
     * bare error — this is what lets the planner correct rather than loop (thread-201 fix).
     */
    public function test_bulk_no_match_returns_search_correction(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();

        $service = new booking_skill_mutation_execute_service(new attachment_token_service());
        $support = new booking_skill_support(
            new attachment_token_service(),
            new conversation_thread_memory(),
            new skill_catalog_discovery()
        );
        $result = $service->execute(
            bulk_update_options_skill::TASK_NAME,
            ['optionquery' => 'ZZZ Definitely No Such Option Title', 'text' => 'Whatever'],
            $cmid,
            (int)$GLOBALS['USER']->id,
            $support
        );

        $this->assertEquals('error', $result['status'] ?? '');
        $this->assertContains('OPTION_QUERY_NO_MATCH', (array)($result['issue_codes'] ?? []));
        $observation = (string)($result['observation_full'] ?? '');
        $this->assertStringContainsString('id=' . $optionid, $observation, 'Candidate option must be listed.');
        $this->assertStringContainsString('Do NOT re-issue', $observation);
    }

    /**
     * Bulk update must persist the header image to EVERY targeted option from a single uploaded
     * image (one token). Reproduces thread-206, where bulk applied no image at all because the flat
     * headerimage_token never reached the executor; this locks in that the shared single-option core
     * (persist_and_verify_single_option) stages and persists the image per option.
     */
    public function test_bulk_update_persists_header_image_across_all_options(): void {
        global $USER, $CFG;

        [$cmid, $optionid1] = $this->create_booking_with_option();
        $context = context_module::instance($cmid);
        $fs = get_file_storage();

        // A second option in the same instance.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Second option';
        $record->importing = 1;
        $optionid2 = (int)$plugingenerator->create_option($record)->id;
        singleton_service::destroy_instance();

        // One uploaded image, one token: the agent stages it once and reuses the draft per option.
        $tmppath = make_request_directory() . '/upload.png';
        copy($CFG->dirroot . '/mod/booking/tests/fixtures/volleyball.png', $tmppath);
        $tokensvc = new \bookingextension_agent\local\wizard\services\attachment\attachment_token_service();
        $token = $tokensvc->create((int)$USER->id, (int)$context->id, $tmppath, 'image/png', 'volleyball.png');

        $service = new booking_skill_mutation_execute_service(new attachment_token_service());
        $support = new booking_skill_support(
            new attachment_token_service(),
            new conversation_thread_memory(),
            new skill_catalog_discovery()
        );
        $result = $service->execute(
            bulk_update_options_skill::TASK_NAME,
            ['optionids' => [$optionid1, $optionid2], 'headerimage_token' => $token],
            $cmid,
            (int)$USER->id,
            $support
        );

        $this->assertEquals('executed', $result['status'] ?? '', (string)($result['detail'] ?? ''));

        // BOTH options must carry the header image, each with a non-null source so imageurl derives.
        foreach ([$optionid1, $optionid2] as $optionid) {
            $files = $fs->get_area_files($context->id, 'mod_booking', 'bookingoptionimage', $optionid, 'id', false);
            $this->assertCount(1, $files, 'Option ' . $optionid . ' did not receive the bulk header image.');
            $file = reset($files);
            $this->assertNotNull($file->get_source(), 'Option ' . $optionid . ' header image has a NULL source.');
        }
    }

    /**
     * A planner-style {changes:[{field,value}]} envelope must be flattened to top-level fields and
     * actually applied, instead of being silently dropped (the thread-206 root cause: bulk inherited
     * a changes example but only ever read flat fields).
     */
    public function test_bulk_update_flattens_changes_envelope(): void {
        [$cmid, $optionid] = $this->create_booking_with_option();

        $service = new booking_skill_mutation_execute_service(new attachment_token_service());
        $support = new booking_skill_support(
            new attachment_token_service(),
            new conversation_thread_memory(),
            new skill_catalog_discovery()
        );
        $result = $service->execute(
            bulk_update_options_skill::TASK_NAME,
            ['optionids' => [$optionid], 'changes' => [['field' => 'maxanswers', 'value' => 42]]],
            $cmid,
            (int)$GLOBALS['USER']->id,
            $support
        );

        $this->assertEquals('executed', $result['status'] ?? '', (string)($result['detail'] ?? ''));

        singleton_service::destroy_booking_option_singleton($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertEquals(42, (int)$settings->maxanswers, 'changes-envelope field was not applied.');
    }

    /**
     * Create a course, a booking instance and one option. Returns [cmid, optionid].
     *
     * @return array{0:int,1:int}
     */
    private function create_booking_with_option(): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Test booking',
        ]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Image option';
        $record->courseid = $course->id;
        $record->importing = 1;
        $option = $plugingenerator->create_option($record);

        singleton_service::destroy_instance();

        return [(int)$booking->cmid, (int)$option->id];
    }

    /**
     * Create a populated user draft area (owned by the current $USER) from a fixture image.
     *
     * @param string $fixture Fixture filename inside tests/fixtures.
     * @return int Draft item id.
     */
    private function stage_user_draft_with_fixture(string $fixture): int {
        global $CFG, $USER;

        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => $usercontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => $fixture,
        ], $CFG->dirroot . '/mod/booking/tests/fixtures/' . $fixture);

        return $draftitemid;
    }

    /**
     * Store an image directly into an option's bookingoptionimage file area (existing-image setup).
     *
     * @param int $cmid
     * @param int $optionid
     * @param string $fixture Fixture filename inside tests/fixtures.
     */
    private function store_option_image(int $cmid, int $optionid, string $fixture): void {
        global $CFG;

        $context = context_module::instance($cmid);
        $fs = get_file_storage();
        $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'mod_booking',
            'filearea' => 'bookingoptionimage',
            'itemid' => $optionid,
            'filepath' => '/',
            'filename' => $fixture,
        ], $CFG->dirroot . '/mod/booking/tests/fixtures/' . $fixture);
    }
}
