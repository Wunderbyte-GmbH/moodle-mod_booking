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
use mod_booking\local\wizard\options\skills\create_option_skill;

/**
 * N3 guards: the intuitive keys 'description' and 'price' must not die as bare schema mismatch.
 *
 * Models send these keys systematically on create_option (threads 585/590, E2E), but the alias
 * groups in create_option_skill map neither 'description' nor 'price' → 'prices'. Today both die
 * as a comment-less "Unknown properties" schema mismatch without any guidance.
 *
 * The N3 decision between the two fix variants is still open, so each test passes under EITHER:
 *  (a) ACCEPT + MAP: check_structure accepts the key and preflight canonicalizes it into the
 *      prepared input ('description' stays a description field, 'price' becomes a 'prices'
 *      structure). B10 WARNING: if this variant is chosen, the canonicalization MUST happen in
 *      PREFLIGHT — the executor's structure re-check is deliberately disabled since agent commit
 *      e14118d, so a key that only passes check_structure but is not canonicalized into the
 *      prepared input would reach execute() unmapped or be dropped silently.
 *  (b) EXPLICIT NEGATION: the key stays rejected, but the rejection carries explicit guidance —
 *      the skill's top-level schema description OR the error's 'repair' channel (F3 two-channel
 *      contract) names the key and points to the correct way ('update_option' for description,
 *      'prices' for price). Guidance that only sits in the user-facing 'errors' channel does NOT
 *      count: that placement is exactly the F3-W2 defect guarded by
 *      agent_create_option_error_channels_test.
 *
 * Drift-guard note (agent_skill_guidance_schema_drift_test, commit 8d98281c5): variant (a) grows
 * the schema and is drift-guard-safe by construction; variant (b) touches the schema description,
 * which the drift guard does not scan. Should variant (b) instead be worded inside a contextual
 * guidance pack line, that line must follow the drift guard's documented patterns (negative
 * advice, or naming the owning skill, e.g. "… set the description via mod_booking.update_option
 * afterwards").
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 */
final class agent_create_option_intuitive_keys_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * 'description' is either accepted and mapped into the prepared input, or rejected with
     * explicit guidance pointing to update_option.
     */
    public function test_intuitive_description_key_is_accepted_or_explicitly_guided(): void {
        [$contextid, $userid] = $this->make_booking_context();

        $this->assert_intuitive_key_accepted_or_guided(
            [
                'text' => 'Vikings evening class',
                'maxanswers' => 10,
                'description' => 'An introduction to everyday life in the viking age.',
            ],
            'description',
            'description',
            ['description', 'update_option'],
            $contextid,
            $userid
        );
    }

    /**
     * 'price' is either accepted and canonicalized to a 'prices' structure in the prepared
     * input, or rejected with explicit guidance naming the canonical 'prices' key.
     */
    public function test_intuitive_price_key_is_accepted_or_explicitly_guided(): void {
        [$contextid, $userid] = $this->make_booking_context();

        // A price category is required so that variant (a) can canonicalize a scalar price
        // into a valid prices map without tripping the price-category preflight validation.
        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_pricecategory((object)[
            'ordernum' => 1,
            'name' => 'default',
            'identifier' => 'default',
            'defaultvalue' => 0,
            'pricecatsortorder' => 1,
        ]);

        $this->assert_intuitive_key_accepted_or_guided(
            [
                'text' => 'Vikings evening class',
                'maxanswers' => 10,
                'price' => 15,
            ],
            'price',
            'prices',
            ['prices'],
            $contextid,
            $userid
        );
    }

    /**
     * Assert the N3 disjunction for one intuitive input key.
     *
     * Variant (a): check_structure accepts the input AND preflight carries the canonical key in
     * its prepared input (B10: canonicalization must happen in preflight, see class docblock).
     * Variant (b): check_structure rejects the input AND the top-level schema description or the
     * structural 'repair' channel contains every guidance needle.
     *
     * @param array $input Planner-style task input containing the intuitive key.
     * @param string $intuitivekey The intuitive key under test (as models send it).
     * @param string $canonicalkey The canonical key the prepared input must carry under (a).
     * @param string[] $guidanceneedles Substrings the guidance must contain under (b).
     * @param int $contextid Module context id of the target booking instance.
     * @param int $userid Acting user id.
     */
    private function assert_intuitive_key_accepted_or_guided(
        array $input,
        string $intuitivekey,
        string $canonicalkey,
        array $guidanceneedles,
        int $contextid,
        int $userid
    ): void {
        $skill = new create_option_skill();
        $structure = $skill->check_structure($input);

        if (!empty($structure['valid'])) {
            // Variant (a) chosen: the key is accepted — verify preflight canonicalization.
            $dto = $skill->preflight($input, $contextid, $userid);
            $prepared = (array)$dto->preparedinput;
            $this->assertArrayHasKey(
                $canonicalkey,
                $prepared,
                "N3 variant (a) half-done for '{$intuitivekey}': check_structure accepts the key, but "
                . "preflight does not canonicalize it to '{$canonicalkey}' in the prepared input. B10: "
                . 'the mapping must happen in PREFLIGHT (executor structure re-check is disabled since '
                . 'agent commit e14118d), otherwise the value is silently dropped or reaches execute() '
                . 'unmapped. Preflight result: '
                . json_encode(['status' => $dto->status, 'issues' => $dto->issues])
            );
            $this->assertNotEmpty(
                $prepared[$canonicalkey],
                "N3 variant (a): the canonicalized '{$canonicalkey}' value in the prepared input must "
                . "not be empty — the user's '{$intuitivekey}' value must survive the mapping."
            );
            return;
        }

        // Variant (b) required: the rejection must carry explicit guidance in the schema
        // description or in the F3 repair channel (NOT in the user-facing errors channel).
        $guidance = trim((string)(($skill->get_schema())['description'] ?? ''));
        $repair = $structure['repair'] ?? null;
        if (is_string($repair)) {
            $guidance .= ' ' . $repair;
        } else if (is_array($repair)) {
            $guidance .= ' ' . implode(' ', array_map('strval', $repair));
        }

        $missing = [];
        foreach ($guidanceneedles as $needle) {
            if (stripos($guidance, $needle) === false) {
                $missing[] = $needle;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "N3 decision not implemented for the intuitive key '{$intuitivekey}': the input is rejected "
            . 'as a bare schema mismatch without guidance. Either accept + canonicalize the key in '
            . "preflight (variant a), or negate it explicitly — schema description or 'repair' channel "
            . 'must mention: ' . implode(', ', $guidanceneedles) . '. Guidance inspected (schema '
            . 'description + repair channel): "' . trim($guidance) . '". Rejection errors (user channel, '
            . 'does not count as guidance): ' . json_encode($structure['errors'] ?? [])
        );
    }

    /**
     * Create a course with one booking instance and return [contextid, userid] for preflight.
     *
     * @return array Two ints: module context id and acting (admin) user id.
     */
    private function make_booking_context(): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $booking = $gen->create_module('booking', ['course' => $course->id]);
        $context = context_module::instance($booking->cmid);

        return [(int)$context->id, (int)$USER->id];
    }
}
