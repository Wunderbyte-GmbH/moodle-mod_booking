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
 * Sibling discrimination belongs on the card, not in the embedded description.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Tests for the IS:/NOT: lines of the selection catalogue (#2453, wave 17).
 *
 * A skill that separates itself from a sibling inside its DESCRIPTION pays for it twice. The description is
 * embedding anchor #0, and a vector carries no negation: "not the built-in option properties" lies next to
 * "built-in option properties" and additionally spells out the competitor's subject, so the boundary sentence
 * attracts the very queries it was written to repel (forensics of LOP-4, baseline run 22). The selector, a
 * language model reading text, does read negation — so the boundary belongs on the card and nowhere else.
 *
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service
 * @covers \bookingextension_agent\local\wizard\skill_registry
 */
final class skill_catalog_discrimination_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * Build the service under test.
     *
     * @return planner_catalog_service
     */
    private function service(): planner_catalog_service {
        return new planner_catalog_service(new assistant_state_guidance_service());
    }

    /**
     * The card prints both clauses in the established card grammar.
     */
    public function test_the_card_prints_is_and_not_lines(): void {
        $this->resetAfterTest();

        $text = $this->service()->render_catalog_as_text([[
            'skill' => 'mod_booking.demo',
            'description' => 'Lists something.',
            'is' => 'Custom fields an administrator defined on booking options.',
            'not' => 'The built-in fields of a booking option.',
        ]]);

        $this->assertStringContainsString(
            'IS: Custom fields an administrator defined on booking options.',
            $text
        );
        $this->assertStringContainsString('NOT: The built-in fields of a booking option.', $text);
    }

    /**
     * A skill without a sibling stays silent rather than printing an empty line.
     */
    public function test_a_skill_without_a_sibling_prints_no_line(): void {
        $this->resetAfterTest();

        $text = $this->service()->render_catalog_as_text([[
            'skill' => 'mod_booking.demo',
            'description' => 'Lists something.',
        ]]);

        $this->assertStringNotContainsString('IS:', $text);
        $this->assertStringNotContainsString('NOT:', $text);
    }

    /**
     * The slim catalogue carries the clauses too.
     *
     * With slim_all every card is in the prompt at once, so confusability is highest and nothing pre-filters.
     */
    public function test_the_slim_catalogue_carries_the_clauses(): void {
        $this->resetAfterTest();

        $slim = $this->service()->slim_prompt_catalog_for_planner([[
            'skill' => 'mod_booking.demo',
            'description' => 'Lists something.',
            'is' => 'Custom fields.',
            'not' => 'Built-in fields.',
        ]]);

        $this->assertSame('Custom fields.', $slim[0]['is']);
        $this->assertSame('Built-in fields.', $slim[0]['not']);
    }

    /**
     * The clauses never reach the embedding anchors.
     *
     * This is the whole point of the move: retrieval must not see the competitor's vocabulary.
     */
    public function test_the_clauses_never_reach_an_embedding_anchor(): void {
        $this->resetAfterTest();

        $registry = skill_registry_factory::get_default();

        foreach ($registry->get_all_prompt_contracts() as $contract) {
            // The anchor list is built from description + example_utterances only
            // (embeddings_catalog_builder_service::build_anchor_list), so those two fields ARE the
            // embedded text. Neither may repeat a clause that exists to keep queries away.
            $embedded = trim((string)($contract['description'] ?? '')) . ' '
                . implode(' ', array_map('strval', (array)($contract['example_utterances'] ?? [])));

            foreach (['is', 'not'] as $key) {
                $clause = trim((string)($contract[$key] ?? ''));
                if ($clause === '') {
                    continue;
                }
                $this->assertStringNotContainsString(
                    $clause,
                    $embedded,
                    'the ' . $key . ' clause of ' . $contract['skill'] . ' is repeated in its embedded text'
                );
            }
        }
    }

    /**
     * No description names another registered skill.
     *
     * A sibling's skill name inside an embedded description is the sharpest form of the defect: it puts the
     * competitor's own identifier into this skill's vector.
     */
    public function test_no_description_names_a_sibling_skill(): void {
        $this->resetAfterTest();

        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();
        $names = [];
        foreach ($contracts as $contract) {
            $name = trim((string)($contract['skill'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $offenders = [];
        foreach ($contracts as $contract) {
            $own = trim((string)($contract['skill'] ?? ''));
            $description = (string)($contract['description'] ?? '');
            foreach ($names as $name) {
                if ($name !== $own && strpos($description, $name) !== false) {
                    $offenders[] = $own . ' names ' . $name;
                }
            }
        }

        $this->assertSame([], $offenders, "descriptions name sibling skills:\n" . implode("\n", $offenders));
    }

    /**
     * A clause never refers to a sibling by a short name two namespaces share.
     *
     * Inside its own namespace a card may name a sibling short ("update_rule") instead of fully qualified,
     * which saves characters in the slim catalogue. That is only safe while the short name identifies
     * exactly one skill — core.diagnose_permissions and local_taskflow.diagnose_permissions are the
     * standing counterexample, and those must be spelled out.
     */
    public function test_a_clause_never_uses_an_ambiguous_short_name(): void {
        $this->resetAfterTest();

        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();

        $shortcounts = [];
        foreach ($contracts as $contract) {
            $name = trim((string)($contract['skill'] ?? ''));
            if ($name === '') {
                continue;
            }
            $short = substr($name, (int)strrpos($name, '.') + 1);
            $shortcounts[$short] = ($shortcounts[$short] ?? 0) + 1;
        }
        $ambiguous = array_keys(array_filter($shortcounts, static fn ($count): bool => $count > 1));

        $offenders = [];
        foreach ($contracts as $contract) {
            $clauses = trim((string)($contract['is'] ?? '')) . ' ' . trim((string)($contract['not'] ?? ''));
            foreach ($ambiguous as $short) {
                if (preg_match('/(?<![.\w])' . preg_quote($short, '/') . '\b/', $clauses)) {
                    $offenders[] = $contract['skill'] . ' uses the ambiguous short name ' . $short;
                }
            }
        }

        $this->assertSame([], $offenders, "ambiguous short names on cards:\n" . implode("\n", $offenders));
    }

    /**
     * Every clause stays inside the card budget.
     *
     * The cap is a guard, not a feature: each clause is paid for in the slim catalogue, where all cards enter
     * the prompt together. A skill needing more than this to say what it is not has an unclear scope.
     */
    public function test_every_clause_stays_inside_the_card_budget(): void {
        $this->resetAfterTest();

        $registry = skill_registry_factory::get_default();
        $overlong = [];
        foreach ($registry->get_all_prompt_contracts() as $contract) {
            foreach (['is', 'not'] as $key) {
                $clause = trim((string)($contract[$key] ?? ''));
                if (\core_text::strlen($clause) > 160) {
                    $overlong[] = $contract['skill'] . ' ' . $key . ': ' . \core_text::strlen($clause);
                }
            }
        }

        $this->assertSame([], $overlong, "discrimination clauses over budget:\n" . implode("\n", $overlong));
    }

    /**
     * A fence is mutual: when A names B on its card, B names A on its card.
     *
     * Baseline runs 25-28: list_rule_properties said "not the written documentation (wizard.explain_docs)",
     * explain_docs said nothing about rule properties - and LRP-4 went to explain_docs in nine of ten runs.
     * The selector follows the card that speaks. Wave 17 checked only that a named skill has SOME fence;
     * 31 pairs were one-sided, most of them across plugins. A reference counts in either spelling: the
     * full name, or the short name inside the sibling's own namespace.
     */
    public function test_every_sibling_fence_is_mutual(): void {
        $this->resetAfterTest();

        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();
        $card = [];
        $short = [];
        foreach ($contracts as $contract) {
            $name = trim((string)($contract['skill'] ?? ''));
            if ($name === '') {
                continue;
            }
            $card[$name] = trim((string)($contract['is'] ?? '')) . ' ' . trim((string)($contract['not'] ?? ''));
            $short[$name] = substr($name, (int)strrpos($name, '.') + 1);
        }
        $names = static function (string $text, string $own) use ($card, $short): array {
            $found = [];
            foreach ($card as $other => $unused) {
                if ($other === $own) {
                    continue;
                }
                $pattern = '/(?<![.\\w])' . preg_quote($short[$other], '/') . '\\b/';
                if (strpos($text, $other) !== false || preg_match($pattern, $text)) {
                    $found[] = $other;
                }
            }
            return $found;
        };

        $onesided = [];
        foreach ($card as $a => $text) {
            foreach ($names($text, $a) as $b) {
                if (!in_array($a, $names($card[$b], $b), true)) {
                    $onesided[] = $a . ' names ' . $b . ', which does not name it back';
                }
            }
        }

        $this->assertSame([], $onesided, "one-sided fences:\n" . implode("\n", $onesided));
    }

    /**
     * No raw IS:/NOT: clause is longer than the registry cap.
     *
     * The registry cuts every clause at {@see skill_registry::DISCRIMINATION_CAP} characters, mid-word, and the
     * card carries the cut text - the selector never sees the rest. Wave 38 wrote two NOT lines of 233 and 249
     * characters: the sibling names at the end fell off (the mutual-fence test caught that), and the A/B replay
     * had measured the uncut text. A clause that does not fit says so here, before any replay.
     */
    public function test_no_raw_clause_exceeds_the_registry_cap(): void {
        $this->resetAfterTest();

        $registry = skill_registry_factory::get_default();
        $over = [];
        foreach ($registry->get_skills() as $skill) {
            $schema = $skill->get_schema();
            $name = $skill->get_name();
            foreach (['is', 'not'] as $key) {
                $clause = trim((string)preg_replace('/\s+/', ' ', (string)($schema[$key] ?? '')));
                $length = \core_text::strlen($clause);
                if ($length > skill_registry::DISCRIMINATION_CAP) {
                    $over[] = $name . ' ' . $key . ' (' . $length . ')';
                }
            }
        }

        $this->assertSame([], $over, "clauses over the cap:\n" . implode("\n", $over));
    }
}
