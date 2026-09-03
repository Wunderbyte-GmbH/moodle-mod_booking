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
use mod_booking\local\wizard\options\skills\update_option_skill;

/**
 * N-591b guard: update_option must advertise its course-linking capability for discovery.
 *
 * Thread 591 (2026-07-12): the follow-up "is the Moodle course connected to the booking option?
 * link it" ranked course.add_activity into the embed_topk candidates instead of update_option,
 * because NONE of update_option's discovery anchors (its schema description = anchor #0, plus its
 * example_utterances) mentioned course linking — even though coursequery links a course to an
 * existing option. The selector then had no correct skill to pick.
 *
 * This is a contract-presence guard for the anchor content the retrieval builder embeds
 * (embeddings_catalog_builder_service: description + each example_utterance). It does NOT exercise
 * the ranking itself — that needs real embeddings and a rebuilt fixture (ops step D2) and is
 * verified in the live/real-LLM run. It guards against silently dropping the linking anchors,
 * which would re-break discovery of the canonical course-linking path.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\update_option_skill
 */
final class agent_update_option_course_link_discovery_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * The discovery anchors of update_option must cover linking a course to an option.
     */
    public function test_update_option_advertises_course_linking_for_discovery(): void {
        $schema = (new update_option_skill())->get_schema();

        // Anchor #0 (description) is BOTH an embedded retrieval anchor and the planner card the
        // selector reads to choose — it must name the course-linking capability and its key.
        $description = (string)($schema['description'] ?? '');
        $this->assertStringContainsStringIgnoringCase(
            'course',
            $description,
            'update_option description must mention course linking so the selector picks it for '
            . 'a "link the course to the option" request (thread 591).'
        );
        $this->assertStringContainsString(
            'coursequery',
            $description,
            'update_option description must name the coursequery key that performs the linking.'
        );

        // At least one example utterance must be a linking request so embed_topk ranks the skill
        // into the candidate list for that intent (the missing anchor in thread 591).
        $utterances = array_map('strval', (array)($schema['example_utterances'] ?? []));
        $haslinkingutterance = false;
        foreach ($utterances as $utterance) {
            $lower = \core_text::strtolower($utterance);
            if (
                strpos($lower, 'course') !== false
                && (strpos($lower, 'link') !== false || strpos($lower, 'connect') !== false)
            ) {
                $haslinkingutterance = true;
                break;
            }
        }
        $this->assertTrue(
            $haslinkingutterance,
            'update_option must carry at least one course-linking example utterance so discovery '
            . 'surfaces it for the "connect course to option" intent. Utterances: '
            . implode(' | ', $utterances)
        );
    }
}
