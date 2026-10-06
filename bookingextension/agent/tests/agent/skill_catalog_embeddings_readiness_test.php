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
 * D1 regression guard: committed skill-catalog fixtures must reach embed_topk under PHPUnit.
 *
 * E2E audit blueprint compound_prompt §7 finding 2 (D1): discovery reported em=missing under
 * PHPUnit and fell back to slim_all, so the real-LLM tests were not live-representative on the
 * discovery side. Root cause (empirically verified 2026-07-12): the test base registered the
 * embeddings provider with a HARD-CODED dimensions=1536, so switching the embeddings model to
 * one with a different vector size (bge-multilingual-gemma2 = 3584, the live/Scaleway model)
 * produced the variant key "...__1536", for which no fixture exists — the store reported
 * missing. The variant/switch machinery itself is correct (production resolve() reads the real
 * dimensions from the provider config); only the test registration hard-coded the size.
 *
 * This test drives the exact discovery path — register the wunderbyte provider for a model (dummy
 * credentials; readiness is a pure store probe, no network), then resolve() the active variant and
 * get_catalog_status() — once per COMMITTED fixture variant. It fails whenever a committed fixture
 * is not reachable, which is what the hard-coded dimensions caused for every non-1536 model. It is
 * key-independent, so it stays green in CI and red exactly on the D1 defect.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service;
use bookingextension_agent\local\wizard\skill_registry;

/**
 * Skill-catalog embeddings readiness across every committed fixture variant (D1 guard).
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\embeddings\embeddings_readiness_service
 */
final class skill_catalog_embeddings_readiness_test extends abstract_agent_testcase {
    /**
     * Every committed skill-catalog fixture variant must resolve to a ready catalog.
     *
     * The test base derives the embeddings dimensions from the fixture, so registering the
     * provider for model X must produce the variant X carries in its committed fixture (not a
     * hard-coded 1536). Pre-fix, the bge-multilingual-gemma2 (3584) case resolved to
     * bge-multilingual-gemma2__1536 and reported missing; this guards against that regression
     * and against the general model/dimensions switch breaking.
     *
     * @return void
     */
    public function test_committed_fixture_variants_reach_embed_topk(): void {
        $this->setAdminUser();

        if (!class_exists('\\aiprovider_wunderbyte\\provider')) {
            $this->fail('aiprovider_wunderbyte is not installed — the discovery readiness path '
                . 'under test (wunderbyte embeddings) cannot exist in this env at all.');
        }

        $variants = $this->committed_fixture_variants();
        $this->assertNotEmpty(
            $variants,
            'No committed skill-catalog embeddings fixtures found — the discovery real-LLM tests '
            . 'have no live-representative variant to run against.'
        );

        $readiness = new embeddings_readiness_service();
        $registry = skill_registry::make_default();

        foreach ($variants as $variant) {
            [$model, $fixturedims] = $variant;

            // Register EXACTLY like the real-LLM base does, but with dummy credentials: readiness
            // is a pure store probe. Dimensions are left to auto-derive (null) so this asserts the
            // registration picks the fixture's size, which is the D1 fix under test.
            $this->register_live_wunderbyte_provider(
                'phpunit-dummy-key',
                'phpunit-dummy-model',
                'phpunit-dummy-model',
                $model,
                'https://llm.invalid/v1/chat/completions',
                'https://llm.invalid/v1/embeddings'
            );

            // Discovery reads the active variant back from the provider config (production path).
            $settings = (new embeddings_action_config_resolver())->resolve();
            $resolvedmodel = (string)$settings['model'];
            $resolveddims = (int)$settings['dimensions'];

            $this->assertSame(
                $fixturedims,
                $resolveddims,
                "D1: registering embeddings model '$model' must resolve to its fixture's dimensions "
                . "($fixturedims), not a hard-coded size. Resolved $resolveddims — this is the "
                . 'variant/dimensions mismatch that made discovery fall back to slim_all.'
            );

            $status = $readiness->get_catalog_status($registry, $resolvedmodel, $resolveddims);

            $this->assertSame(
                'ready',
                (string)($status['status'] ?? 'unknown'),
                "D1: skill-catalog embeddings for variant {$resolvedmodel}__{$resolveddims} must be "
                . 'ready so discovery runs embed_topk. Got status=' . (string)($status['status'] ?? 'unknown')
                . '. Probed CSV: '
                . \bookingextension_agent\local\wizard\embeddings_csv_repository::for_variant(
                    $resolvedmodel,
                    $resolveddims
                )->get_csv_path()
            );
            $this->assertNotEmpty(
                (array)($status['rows'] ?? []),
                "D1: variant {$resolvedmodel}__{$resolveddims} is ready but returned no rows — "
                . 'discovery cannot run embed_topk.'
            );
        }
    }

    /**
     * Discover the committed skill-catalog fixture variants as [model, dimensions] pairs.
     *
     * The fixtures are named skill_catalog_embeddings__<model>__<dims>.csv (the unversioned
     * skill_catalog_embeddings.csv is the legacy default and is skipped — it has no variant key).
     *
     * @return array[] List of [string model, int dimensions].
     */
    private function committed_fixture_variants(): array {
        $files = glob(__DIR__ . '/fixtures/skill_catalog_embeddings__*.csv') ?: [];
        $variants = [];
        foreach ($files as $file) {
            $name = basename((string)$file, '.csv');
            $suffix = substr($name, strlen('skill_catalog_embeddings__'));
            $sep = strrpos($suffix, '__');
            if ($sep === false) {
                continue;
            }
            $model = substr($suffix, 0, $sep);
            $dims = (int)substr($suffix, $sep + 2);
            if ($model === '' || $dims < 1) {
                continue;
            }
            $variants[] = [$model, $dims];
        }
        return $variants;
    }
}
