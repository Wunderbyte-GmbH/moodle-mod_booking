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
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\skill_provider;

/**
 * The selector sees only the first 240 characters of every skill description.
 *
 * The planner catalog truncates descriptions sentence-aware at 240 characters
 * (planner_catalog_service::compact_catalog_description), so the discrimination between sibling
 * skills (booking option vs Moodle course, booking vs course diagnosis, single vs bulk update,
 * dated vs slot vs self-learning option, participants vs trainers) must sit inside that window.
 * Runs 8/9: GOD-1, SC-2, DUC-3/4 (#2418).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\skill_provider
 */
final class wizard_skill_description_budget_test extends advanced_testcase {
    /** @var int Character budget of the planner catalog description. */
    private const BUDGET = 240;

    /**
     * Setup: engine aliases for the skill base classes.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * Replica of the engine rule: whitespace-normalised, cut at the last sentence boundary within 240.
     *
     * @param string $description Raw description.
     * @return string Retained text.
     */
    public static function retained(string $description): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if (\core_text::strlen($normalized) <= self::BUDGET) {
            return $normalized;
        }
        $window = \core_text::substr($normalized, 0, self::BUDGET);
        if (preg_match('/^(.*[.!?]["\'\)\]]*)(?:\s|$)/us', $window, $matches)) {
            return rtrim($matches[1]);
        }
        return '';
    }

    /**
     * Every description keeps at least one full sentence inside the window.
     */
    public function test_first_sentence_of_every_description_fits_the_window(): void {
        $empty = [];
        foreach ((new skill_provider())->get_skills() as $skill) {
            $description = (string)($skill->get_schema()['description'] ?? '');
            if (self::retained($description) === '') {
                $empty[] = $skill->get_name() . ' (' . \core_text::strlen($description) . ' chars, no sentence end in the window)';
            }
        }
        $this->assertSame([], $empty, "descriptions cut mid-sentence:\n" . implode("\n", $empty));
    }

    /**
     * Sibling skills and the identifiers that discriminate them (skill ids and property names).
     *
     * @return array<string,array{0:string,1:string[]}>
     */
    public static function discriminator_provider(): array {
        return [
            // Run 10 (#2423): search_options is read-only and names the change skills (BU-1).
            // No row for mod_booking.search_options on purpose: both wave-7/8 rewrites of its window pulled SO-4
            // to the course skills (run 11 and two Nachläufe), so the run-10 wording stays and is pinned by the
            // generic sentence check only. The description is also the embeddings anchor (see the skill file).
            'get_option_details' => ['mod_booking.get_option_details', ['mod_booking.search_options', 'optionquery']],
            // Run 10 (#2423): one person's bookings incl. received messages for a named option (DUB-2).
            'diagnose_user_booking' => [
                'mod_booking.diagnose_user_booking',
                ['course.diagnose_user_in_course', 'userquery', 'optionquery', 'includemessages'],
            ],
            // Run 10 (#2423): "cannot book" is booking, "cannot open / grade missing" is course (DBI-1).
            'diagnose_booking_issue' => [
                'mod_booking.diagnose_booking_issue',
                ['mod_booking.diagnose_user_booking', 'optionquery', 'course.diagnose_user_in_course'],
            ],
            'diagnose_cancellation_issue' => ['mod_booking.diagnose_cancellation_issue', ['mod_booking.diagnose_booking_issue']],
            'update_option' => ['mod_booking.update_option', ['mod_booking.bulk_update_options', 'optionquery']],
            // Run 10 (#2423): many options by query, with the typical fields (BU-1).
            'bulk_update_options' => [
                'mod_booking.bulk_update_options',
                ['mod_booking.update_option', 'apply_to_all', 'optionquery', 'maxanswers'],
            ],
            'create_option' => [
                'mod_booking.create_option',
                ['mod_booking.create_slotbooking_option', 'mod_booking.create_selflearning_option'],
            ],
            'create_slotbooking_option' => ['mod_booking.create_slotbooking_option', ['mod_booking.create_option', 'slot_']],
            'create_selflearning_option' => ['mod_booking.create_selflearning_option', ['mod_booking.create_option', 'duration']],
            'book_users' => ['mod_booking.book_users', ['mod_booking.update_option_trainer', 'bookusersquery']],
            // Run 10 (#2423): trainer and option are resolved by name (UOT-2/3).
            'update_option_trainer' => [
                'mod_booking.update_option_trainer',
                ['mod_booking.book_users', 'teacherquery', 'optionquery'],
            ],
            // Run 10 (#2423): a price category is not a booking option (APC-1).
            'add_price_category' => ['mod_booking.add_price_category', ['identifier', 'mod_booking.create_option']],
            // No row for mod_booking.list_option_properties on purpose: every attempt to widen its window pulled
            // LOP-1/2/3 to wizard.explain_docs (Nachläufe 2026-09-17), so the short proven description stays and is
            // pinned by the generic sentence check only.
        ];
    }

    /**
     * The identifiers that discriminate a skill from its siblings sit inside the 240-character window.
     *
     * @dataProvider discriminator_provider
     * @param string $skillname Skill id.
     * @param string[] $identifiers Skill ids / property names that must be retained.
     */
    public function test_discriminating_identifiers_are_inside_the_window(string $skillname, array $identifiers): void {
        $skill = null;
        foreach ((new skill_provider())->get_skills() as $candidate) {
            if ($candidate->get_name() === $skillname) {
                $skill = $candidate;
            }
        }
        $this->assertNotNull($skill, $skillname . ' not discovered');
        $schema = (array)$skill->get_schema();
        $retained = self::retained((string)($schema['description'] ?? ''));
        $this->assertNotSame('', $retained, $skillname);
        // Since wave 17 (#2453) a sibling's NAME no longer belongs in the description: the description is
        // embedding anchor #0, and a vector carries no negation, so a boundary sentence there pulled the
        // skill towards the very requests it was written to repel. The name now travels in the IS:/NOT:
        // card lines, which the selector reads and the anchor builder does not. The guarantee is unchanged
        // — the identifier must reach the selector — so a sibling's name (full, or short inside its own
        // namespace) is asserted against the whole card, and subject vocabulary still against the window.
        $card = $retained . ' ' . trim((string)($schema['is'] ?? '')) . ' ' . trim((string)($schema['not'] ?? ''));
        foreach ($identifiers as $identifier) {
            if (strpos($identifier, '.') !== false) {
                $short = substr($identifier, (int)strrpos($identifier, '.') + 1);
                $reached = strpos($card, $identifier) !== false || strpos($card, $short) !== false;
                $this->assertTrue($reached, $skillname . ' does not name ' . $identifier . ' on its card: ' . $card);
                continue;
            }
            $this->assertStringContainsString($identifier, $retained, $skillname . ' window: ' . $retained);
        }
    }
}
