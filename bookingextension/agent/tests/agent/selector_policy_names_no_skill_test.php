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
 * The selector's policy text names no skill and carries no worked routing example.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\skill_registry_factory;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Baseline runs 31-35, EU-4 "Die Frau Krausler braucht Zugang zum Ersthelfer Grundkurs" went to
 * mod_booking.book_users three times out of four, and the selector's own intent read "Book ... into the Ersthelfer
 * Grundkurs booking option" - the course was re-labelled as an option. The policy text of the selector template
 * contained the worked example "book Anna into the First Aid course" -> book_users: an engine prompt that maps a
 * COURSE wording onto the booking-option skill, six thousand characters above the cards that say the opposite.
 * Engine prompts are skill-agnostic: the policy names no skill and gives no product example; the entity the user
 * named decides between siblings (ENTITY TYPE rule), and the cards carry the specifics.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\orchestrator
 */
final class selector_policy_names_no_skill_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Set up provider and capabilities.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Registered skill names, full and short.
     *
     * @return string[]
     */
    private function skill_names(): array {
        $names = [];
        foreach (skill_registry_factory::get_default()->get_all_prompt_contracts() as $contract) {
            $name = trim((string)($contract['skill'] ?? ''));
            // The engine's own meta skills (wizard.*: search_skills as the discovery fallback) are part of the
            // engine contract and may be named by the engine; domain skills may not.
            if ($name !== '' && strpos($name, 'wizard.') !== 0) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * The skill names a policy text mentions.
     *
     * @param string $text
     * @return string[]
     */
    private function names_in(string $text): array {
        $found = [];
        foreach ($this->skill_names() as $name) {
            if (strpos($text, $name) !== false) {
                $found[] = $name;
            }
        }
        return $found;
    }

    /**
     * The default template's policy (everything before the catalog placeholder) names no skill and no example.
     */
    public function test_the_default_template_policy_is_skill_agnostic(): void {
        $this->resetAfterTest();
        $template = (string)orchestrator::get_default_initial_prompt_template_for_action(
            \core_ai\aiactions\summarise_text::class
        );
        // Wave 32 (frozen prompt spec): the entity the user named decides between siblings.
        $this->assertStringContainsString('CHOOSING BETWEEN SIMILAR SKILLS', $template);
        $this->assertStringContainsString('The kind of thing the user names decides', $template);

        $policy = strstr($template, 'SKILL CATALOG', true) ?: $template;
        $this->assertSame([], $this->names_in($policy), 'engine policy names no skill');
        $this->assertDoesNotMatchRegularExpression('/"[^"]+"\s*->\s*[a-z_.]+\s+now/', $policy, 'no worked routing example');
    }

    /**
     * The live selector prompt keeps every skill name inside the SKILL CATALOG section.
     */
    public function test_the_live_selector_prompt_names_skills_only_in_the_catalog(): void {
        // The template is seeded configuration: the test database still holds the text seeded at install, so
        // the live site does what the upgrade path does - re-seed the defaults - before the prompt is built.
        \bookingextension_agent\local\prompt_seed_sync::apply();
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.search_options'),
            $this->constructor_skill_call('mod_booking.search_options', ['query' => 'Yoga']),
            $this->planner_sufficient('Nichts gefunden.'),
        ]);

        $this->chat('Gibt es Yoga?', (int)$threadid, $store, $runtime);

        $this->assertNotEmpty($this->scriptedplannerprompts);
        $selectorprompt = (string)$this->scriptedplannerprompts[0];
        $catalog = strpos($selectorprompt, 'SKILL CATALOG');
        $this->assertNotFalse($catalog, 'the selector prompt carries the catalog');
        $policy = substr($selectorprompt, 0, $catalog);
        $this->assertSame([], $this->names_in($policy), 'skill names live on the cards, not in the policy');
    }
}
