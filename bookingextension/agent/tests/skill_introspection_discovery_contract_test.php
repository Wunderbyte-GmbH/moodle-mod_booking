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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\interfaces\skill_discovery_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_introspection_provider_interface;
use bookingextension_agent\local\wizard\wizard\skills\list_skills_skill;
use bookingextension_agent\local\wizard\wizard\skills\search_skills_skill;

/**
 * S5b: list_skills/search_skills depend on injected contracts, not on engine machinery.
 *
 * These tests inject a FAKE provider and assert the skill uses its data and never the engine — the
 * whole point of the extraction. They also pin the status -> user-message mapping that stays in the
 * skill (presentation).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\wizard\skills\list_skills_skill
 * @covers \bookingextension_agent\local\wizard\wizard\skills\search_skills_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class skill_introspection_discovery_contract_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * list_skills renders the injected introspection provider's rows (no registry/evaluator), and
     * enriches unavailable rows with the localized deny-reason label (the presentation it kept).
     */
    public function test_list_skills_uses_injected_introspection(): void {
        $this->resetAfterTest();

        $fake = new class implements skill_introspection_provider_interface {
            /**
             * Return canned available/unavailable action rows for the fake provider.
             *
             * @param int $userid
             * @param int $contextid
             * @param string $scope
             * @return array
             */
            public function list_actions(int $userid, int $contextid, string $scope): array {
                return [
                    'available' => [
                        ['skill' => 'test.alpha', 'label' => 'test.alpha', 'description' => 'Alpha skill',
                            'readonly' => true, 'provider' => 'bookingextension_agent'],
                    ],
                    'unavailable' => [
                        ['skill' => 'test.beta', 'label' => 'test.beta', 'description' => 'Beta skill',
                            'readonly' => false, 'provider' => 'bookingextension_agent',
                            'deny_reason' => 'runtime_disabled', 'diagnostics' => []],
                    ],
                ];
            }

            /**
             * Return a fixed slim catalog string for the fake provider.
             *
             * @param int $userid
             * @param int $contextid
             * @param string $scope
             * @return string
             */
            public function render_full_skill_catalog(int $userid, int $contextid, string $scope): string {
                // The skill hands the planner the provider's slim catalog text verbatim.
                return "## test.alpha [readonly]\nAlpha skill";
            }
        };

        $skill = new list_skills_skill();
        $skill->set_introspection_provider($fake);
        // Contextid/userid are irrelevant — the fake provider ignores them, proving no engine call.
        $result = $skill->execute(['scope' => 'all'], 999999, 1);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(['test.alpha'], array_map(static fn($a) => $a['skill'], $result['actions']));

        $this->assertCount(1, $result['unavailable_actions']);
        $beta = $result['unavailable_actions'][0];
        $this->assertSame('test.beta', $beta['skill']);
        $this->assertSame('runtime_disabled', $beta['deny_reason']);
        $this->assertNotSame('', trim((string)$beta['deny_reason_label']), 'Localized deny label must be added by the skill.');

        $this->assertStringContainsString('test.alpha', (string)$result['observation_full']);
    }

    /**
     * Fake introspection provider with two skills whose descriptions are distinctive strings.
     *
     * @return skill_introspection_provider_interface
     */
    private function make_two_skill_provider(): skill_introspection_provider_interface {
        return new class implements skill_introspection_provider_interface {
            /**
             * Two available rows, one readonly and one mutating.
             *
             * @param int $userid
             * @param int $contextid
             * @param string $scope
             * @return array
             */
            public function list_actions(int $userid, int $contextid, string $scope): array {
                return [
                    'available' => [
                        ['skill' => 'test.alpha', 'label' => 'test.alpha', 'description' => 'ALPHA_DESCRIPTION_TEXT',
                            'readonly' => true, 'provider' => 'bookingextension_agent'],
                        ['skill' => 'test.gamma', 'label' => 'test.gamma', 'description' => 'GAMMA_DESCRIPTION_TEXT',
                            'readonly' => false, 'provider' => 'bookingextension_agent'],
                    ],
                    'unavailable' => [],
                ];
            }

            /**
             * The full catalog carries the card format with descriptions.
             *
             * @param int $userid
             * @param int $contextid
             * @param string $scope
             * @return string
             */
            public function render_full_skill_catalog(int $userid, int $contextid, string $scope): string {
                return "## test.alpha [readonly]\nALPHA_DESCRIPTION_TEXT\n\n## test.gamma [mutating]\nGAMMA_DESCRIPTION_TEXT";
            }
        };
    }

    /**
     * Default detail "names": the answer and the observation list the skill names only, no descriptions
     * (the overview the user asked for; thread 23502 handed the synchronizer 49 KB of selector cards).
     */
    public function test_list_skills_default_detail_lists_names_only(): void {
        $this->resetAfterTest();

        $skill = new list_skills_skill();
        $skill->set_introspection_provider($this->make_two_skill_provider());
        $result = $skill->execute(['scope' => 'all'], 999999, 1);

        $this->assertSame('executed', $result['status']);
        $this->assertSame('names', $result['detail_level']);
        foreach (['detail', 'usermessage', 'observation_full'] as $key) {
            $text = (string)$result[$key];
            $this->assertStringContainsString('test.alpha', $text, $key);
            $this->assertStringContainsString('test.gamma', $text, $key);
            $this->assertStringNotContainsString('ALPHA_DESCRIPTION_TEXT', $text, $key);
            $this->assertStringNotContainsString('GAMMA_DESCRIPTION_TEXT', $text, $key);
        }
        // Read/write grouping survives in the names-only listing.
        $this->assertStringContainsString('readonly', (string)$result['observation_full']);
        $this->assertStringContainsString('mutating', (string)$result['observation_full']);
    }

    /**
     * detail "full": the observation is the complete catalog with every description (a skill is to be
     * picked from it next), and the answer carries the descriptions too.
     */
    public function test_list_skills_full_detail_carries_descriptions(): void {
        $this->resetAfterTest();

        $skill = new list_skills_skill();
        $skill->set_introspection_provider($this->make_two_skill_provider());
        $result = $skill->execute(['scope' => 'all', 'detail' => 'full'], 999999, 1);

        $this->assertSame('full', $result['detail_level']);
        $this->assertStringContainsString('ALPHA_DESCRIPTION_TEXT', (string)$result['observation_full']);
        $this->assertStringContainsString('GAMMA_DESCRIPTION_TEXT', (string)$result['observation_full']);
        $this->assertStringContainsString('ALPHA_DESCRIPTION_TEXT', (string)$result['detail']);
    }

    /**
     * An unknown detail value is a recoverable input error (a question, never a hard error), and the
     * user text carries no schema field list.
     */
    public function test_list_skills_unknown_detail_is_recoverable(): void {
        $this->resetAfterTest();

        $skill = new list_skills_skill();
        $check = $skill->check_structure(['detail' => 'verbose']);

        $this->assertFalse($check['valid']);
        $this->assertContains('RECOVERABLE_INPUT_ERROR', $check['issue_codes']);
        $this->assertCount(1, $check['errors']);
        $this->assertStringNotContainsString('RECOVERABLE_INPUT_ERROR', $check['errors'][0]);
    }

    /**
     * search_skills renders the injected discovery provider's matches on STATUS_OK.
     */
    public function test_search_skills_uses_injected_discovery_on_success(): void {
        $this->resetAfterTest();

        $fake = new class implements skill_discovery_provider_interface {
            /**
             * Return a single canned discovered skill with STATUS_OK.
             *
             * @param string $query
             * @param int $contextid
             * @param int $userid
             * @param int $topk
             * @return array
             */
            public function discover(string $query, int $contextid, int $userid, int $topk = 5): array {
                return ['status' => self::STATUS_OK, 'discovered_skills' => [
                    ['skill' => 'mod_booking.create_option', 'schema' => ['description' => 'Create a booking option']],
                ]];
            }
        };

        $skill = new search_skills_skill();
        $skill->set_discovery_provider($fake);
        $result = $skill->execute(['query' => 'create a booking option'], 1, 1);

        $this->assertSame('executed', $result['status']);
        $this->assertSame(
            ['mod_booking.create_option'],
            array_map(static fn($d) => $d['skill'], $result['discovered_skills'])
        );
        $this->assertStringContainsString('mod_booking.create_option', (string)$result['observation_full']);
    }

    /**
     * Each discovery failure status maps to the exact user-facing message (presentation kept in skill).
     */
    public function test_search_skills_maps_failure_statuses(): void {
        $this->resetAfterTest();

        $cases = [
            skill_discovery_provider_interface::STATUS_EMBEDDINGS_UNAVAILABLE
                => 'Skill discovery is unavailable because embeddings are disabled.',
            skill_discovery_provider_interface::STATUS_CATALOG_NOT_READY
                => 'Skill catalog embeddings are not ready.',
            skill_discovery_provider_interface::STATUS_EMBEDDING_FAILED
                => 'Failed to generate embedding for the query.',
        ];

        foreach ($cases as $status => $expectedmessage) {
            $fake = new class ($status) implements skill_discovery_provider_interface {
                /** @var string */
                private string $status;
                /**
                 * Capture the status the fake provider should report.
                 *
                 * @param string $status
                 */
                public function __construct(string $status) {
                    $this->status = $status;
                }
                /**
                 * Return the configured failure status with no discovered skills.
                 *
                 * @param string $query
                 * @param int $contextid
                 * @param int $userid
                 * @param int $topk
                 * @return array
                 */
                public function discover(string $query, int $contextid, int $userid, int $topk = 5): array {
                    return ['status' => $this->status, 'discovered_skills' => []];
                }
            };

            $skill = new search_skills_skill();
            $skill->set_discovery_provider($fake);
            $result = $skill->execute(['query' => 'anything'], 1, 1);

            $this->assertSame('failed', $result['status'], "status {$status}");
            $this->assertSame($expectedmessage, $result['message'], "status {$status}");
            $this->assertSame([], $result['discovered_skills']);
        }
    }

    /**
     * An empty query fails early without consulting the discovery provider at all.
     */
    public function test_search_skills_empty_query_short_circuits(): void {
        $this->resetAfterTest();

        $provider = new class implements skill_discovery_provider_interface {
            /**
             * Fail loudly if discovery is consulted for an empty query.
             *
             * @param string $query
             * @param int $contextid
             * @param int $userid
             * @param int $topk
             * @return array
             */
            public function discover(string $query, int $contextid, int $userid, int $topk = 5): array {
                throw new \RuntimeException('discover() must not be called for an empty query');
            }
        };

        $skill = new search_skills_skill();
        $skill->set_discovery_provider($provider);
        $result = $skill->execute(['query' => '   '], 1, 1);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('No search query', (string)$result['observation_full']);
    }
}
