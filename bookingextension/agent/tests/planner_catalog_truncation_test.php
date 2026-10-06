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

declare(strict_types=1);

namespace bookingextension_agent;

use advanced_testcase;
use core_text;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Slim-catalog cards must never be cut off mid-sentence (thread 589 / C1).
 *
 * The planner's slim catalog compacts every skill description via
 * planner_catalog_service::compact_catalog_description(). A hard character cap that slices inside
 * a sentence can invert the card's meaning: the live course.create_course card was cut right after
 * "The system asks which course category to use unless…", so the surviving fragment reads as an
 * instruction to ASK instead of ACT. This guard asserts that whenever a card was shortened, the
 * shortened text ends at a sentence boundary of the original description — which holds both when
 * descriptions are authored under the cap and when the truncation becomes sentence-aware.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service::compact_catalog_description
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service::slim_prompt_catalog_for_planner
 */
final class planner_catalog_truncation_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Every compacted (shortened) catalog card ends at a sentence boundary of its original description.
     *
     * The invariant is applied to ALL skills of the real registry so it doubles as a drift guard for
     * newly added skills; it is trivially satisfied by cards that were not shortened at all.
     */
    public function test_compacted_descriptions_end_at_sentence_boundary(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();
        $this->assertNotEmpty($contracts, 'The default skill registry must expose prompt contracts.');

        // Original (un-compacted) description per skill, exactly as the contracts carry it.
        $originals = [];
        foreach ($contracts as $contract) {
            if (!is_array($contract)) {
                continue;
            }
            $skill = trim((string)($contract['skill'] ?? ''));
            if ($skill !== '') {
                $originals[$skill] = (string)($contract['description'] ?? '');
            }
        }

        // Build the slim planner catalog exactly like the discovery phase does.
        $service = new planner_catalog_service(new assistant_state_guidance_service());
        $catalog = $service->slim_prompt_catalog_for_planner($contracts);
        $this->assertNotEmpty($catalog, 'The slim planner catalog must not be empty.');

        // The two cards of the live defect must actually be covered by this guard.
        $skillnames = array_map(static fn(array $entry): string => (string)$entry['skill'], $catalog);
        $this->assertContains('course.create_course', $skillnames);
        $this->assertContains('course.scaffold_course_content', $skillnames);

        $violations = [];
        foreach ($catalog as $entry) {
            $skill = (string)$entry['skill'];
            $compact = (string)($entry['description'] ?? '');
            $normalized = trim((string)preg_replace('/\s+/', ' ', (string)($originals[$skill] ?? '')));

            // Not shortened (only whitespace-normalized) → the invariant holds trivially.
            if ($compact === '' || $compact === $normalized) {
                continue;
            }

            if (!$this->ends_at_sentence_boundary($compact, $normalized)) {
                $violations[] = $skill . ': card is cut mid-sentence — "' . $compact . '"';
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Slim catalog cards were truncated mid-sentence (shorten the description or truncate at a "
                . "sentence boundary):\n" . implode("\n", $violations)
        );
    }

    /**
     * The RENDERED selector card carries the compacted description unchanged.
     *
     * The test above guards slim_prompt_catalog_for_planner(), which shortens sentence-aware at 240 —
     * and that layer was never the problem. render_catalog_as_text() then cut the result a SECOND
     * time with a hard substr(…, 0, 160), mid-word, which is what the selector actually received:
     *
     *   ## mod_booking.diagnose_waitinglist [readonly]
     *   Diagnose why the waiting list … or - most commonly - why reducing the numbe
     *
     * Ten skill classes in mod_booking and local_taskflow are authored against the documented 240
     * window and carry that in a code comment with a ticket number (#471, #472, #473, #2423); every
     * one of their sibling-discriminating sentences sat past 160 and was thrown away. Baseline run 17
     * attributed DWL-2, TDP-4, DMD-4, DAS-2, LR-2 and GRD-4 to it.
     */
    /**
     * A description barely over the cap keeps its last sentence instead of losing it whole.
     *
     * Survey of 2026-09-22: 58 of 83 descriptions exceed 240 characters, and several lose exactly
     * the sentence somebody wrote to separate the skill from a sibling - list_option_fields loses
     * "Not the built-in option properties", create_option_field loses its two-way boundary, remember
     * loses "it is NOT for recalling previous conversation". Some are barely over: 249 characters
     * costing 51, 277 costing 57, 290 costing 60.
     *
     * Dropping a whole sentence because a description is nine characters too long is a cliff, not a
     * budget. Within a fifth over the cap the text stays whole; far beyond it the cut still applies,
     * because a card of 841 characters has a different problem and the slim catalogue has to carry
     * every card at once.
     */
    public function test_a_description_barely_over_the_cap_keeps_its_last_sentence(): void {
        $service = new planner_catalog_service(new assistant_state_guidance_service());

        // Two sentences, a handful of characters over the cap: the second must survive.
        $second = ' Not the sibling skill.';
        $padding = 241 - core_text::strlen($second) - 1;
        $first = str_pad('One sentence that carries the main purpose of the skill', $padding, ' and more') . '.';
        $barely = $first . $second;
        $this->assertGreaterThan(240, core_text::strlen($barely));
        $this->assertLessThanOrEqual(288, core_text::strlen($barely));
        $this->assertSame($barely, $service->compact_catalog_description($barely));

        // Far beyond the cap the sentence-aware cut still applies.
        $long = $first . ' ' . str_pad('A second sentence', 300, ' that keeps going') . '. Tail sentence.';
        $this->assertGreaterThan(288, core_text::strlen($long));
        $compacted = $service->compact_catalog_description($long);
        $this->assertNotSame($long, $compacted);
        $this->assertLessThanOrEqual(240, core_text::strlen($compacted));
        $this->assertStringEndsWith('.', $compacted);
    }

    public function test_rendered_card_keeps_the_compacted_description(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $registry = skill_registry_factory::get_default();
        $service = new planner_catalog_service(new assistant_state_guidance_service());
        $catalog = $service->slim_prompt_catalog_for_planner($registry->get_all_prompt_contracts());
        $this->assertNotEmpty($catalog);

        $violations = [];
        foreach ($catalog as $entry) {
            $skill = (string)$entry['skill'];
            $expected = trim((string)preg_replace('/\s+/', ' ', (string)($entry['description'] ?? '')));
            if ($expected === '') {
                continue;
            }
            $rendered = $service->render_catalog_as_text([$entry]);
            if (!str_contains($rendered, $expected)) {
                // Report the rendered description line, which is what the model reads.
                $lines = explode("\n", $rendered);
                $violations[] = $skill . ': rendered as "' . ($lines[1] ?? '') . '"';
            }
        }

        $this->assertSame(
            [],
            $violations,
            "The rendered selector card must carry the compacted description unchanged — a second, "
                . "hard truncation slices the sibling discrimination off:\n" . implode("\n", $violations)
        );
    }

    /**
     * A skill whose schema requires nothing says so, instead of leaving the line out silently.
     *
     * Decision rule 3 of the selector prompt ("missing required input -> clarification") is stated in
     * every prompt, while "this skill needs nothing" was expressed by the ABSENCE of a line. The model
     * read that silence as ignorance rather than freedom and invented a mandatory field (run 17: DMD-4
     * claimed diagnose_message_delivery "works with a specific assignment ID", which its schema marks
     * optional).
     */
    public function test_a_skill_without_required_fields_says_so(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $service = new planner_catalog_service(new assistant_state_guidance_service());
        $rendered = $service->render_catalog_as_text([[
            'skill' => 'demo.without_required',
            'readonly' => true,
            'description' => 'A skill that resolves everything it needs by itself.',
            'required_input' => [],
            // The claim is only printed when it is true, so the fixture has to state it: this demo skill
            // really does accept an empty input.
            'accepts_empty_input' => true,
        ]]);

        $this->assertStringContainsString('REQUIRED: none', $rendered);
    }

    /**
     * No card claims "REQUIRED: none" while its own structure gate rejects an empty input.
     *
     * Sixteen registered skills declare no required field in the schema and still refuse {} in
     * check_structure() — update_option_trainer, get_option_details, generate_questions, wizard.forget and
     * others. Advertising those as free of charge is how EU-2 lost course.enrol_user to a booking skill in
     * run 19: the target card read "REQUIRED: userquery" and the wrong one read "REQUIRED: none", so the
     * apparent cost was inverted. The skill's own gate is the source of truth here, not the schema flag.
     */
    public function test_no_card_claims_none_while_its_gate_refuses_empty_input(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $registry = skill_registry_factory::get_default();
        $service = new planner_catalog_service(new assistant_state_guidance_service());
        $liars = [];

        foreach ($registry->get_skill_names() as $name) {
            $skill = $registry->get_skill($name);
            if ($skill === null || !method_exists($skill, 'check_structure')) {
                continue;
            }
            try {
                $structure = (array)$skill->check_structure([]);
                // A rejection the gate flags RECOVERABLE_INPUT_ERROR is the skill asking the user, not a
                // precondition for routing — the selector may send the request there. Same rule as
                // base_skill::accepts_empty_input(); question.generate_questions is that case.
                $codes = array_map('strval', (array)($structure['issue_codes'] ?? []));
                $acceptsempty = !empty($structure['valid'])
                    || in_array('RECOVERABLE_INPUT_ERROR', $codes, true);
            } catch (\Throwable $e) {
                $acceptsempty = false;
            }
            if ($acceptsempty) {
                continue;
            }

            $contract = $skill->get_prompt_contract()->to_array();
            $contract['skill'] = $name;
            $rendered = $service->render_catalog_as_text([$contract]);
            if (str_contains($rendered, 'REQUIRED: none')) {
                $liars[] = $name;
            }
        }

        $this->assertSame(
            [],
            $liars,
            "These cards promise the selector they need nothing, while their own check_structure() rejects an "
                . "empty input:\n" . implode("\n", $liars)
        );
    }

    /**
     * Every card says something true about what it requires — never nothing.
     *
     * The REQUIRED line has three truthful forms: the schema's required fields, the gate's alternatives
     * ("one of a | b", declared as required_groups), or "none" when an empty input really is accepted.
     * A card with no line at all is the state that cost GOD-1 and GOD-2 in run 21: the model reads silence
     * as ignorance rather than freedom, exactly as decision rule 3 of the selector prompt invites it to.
     *
     * This is the stricter successor of the honesty test above. That one only forbade the lie; this one also
     * forbids the silence, which is how wave 13 traded one defect for the other.
     */
    public function test_every_card_states_its_requirement(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $registry = skill_registry_factory::get_default();
        $service = new planner_catalog_service(new assistant_state_guidance_service());
        $silent = [];

        foreach ($registry->get_all_prompt_contracts() as $contract) {
            if (!is_array($contract) || ($contract['skill'] ?? '') === '') {
                continue;
            }
            if (!str_contains($service->render_catalog_as_text([$contract]), 'REQUIRED:')) {
                $silent[] = (string)$contract['skill'];
            }
        }

        $this->assertSame(
            [],
            $silent,
            "These cards say nothing at all about what they need. Either the schema marks the fields required, "
                . "or prompt_meta['required_groups'] names the alternatives the structure gate accepts:\n"
                . implode("\n", $silent)
        );
    }

    /**
     * Whether a shortened card ends exactly where a sentence of the original description ends.
     *
     * An optional trailing ellipsis marker ("..." or "…") is ignored; the remaining text must be a
     * prefix of the (whitespace-normalized) original AND end in a sentence terminator (. ! ?),
     * optionally followed by a closing quote/bracket.
     *
     * @param string $compact the compacted card text
     * @param string $normalized the whitespace-normalized original description
     * @return bool
     */
    private function ends_at_sentence_boundary(string $compact, string $normalized): bool {
        $stripped = rtrim($compact);
        if (str_ends_with($stripped, '…')) {
            $stripped = rtrim(core_text::substr($stripped, 0, core_text::strlen($stripped) - 1));
        } else if (str_ends_with($stripped, '...')) {
            $stripped = rtrim(core_text::substr($stripped, 0, core_text::strlen($stripped) - 3));
        }

        if ($stripped === '' || !str_starts_with($normalized, $stripped)) {
            return false;
        }

        return (bool)preg_match('/[.!?]["\'\)\]]*$/u', $stripped);
    }
}
