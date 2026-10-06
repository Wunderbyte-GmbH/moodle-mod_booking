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
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\orchestrator;

/**
 * Tests the shared model/dimensions resolution that both index services delegate to (S4).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\embeddings_action_config_resolver
 */
final class embeddings_config_resolver_test extends advanced_testcase {
    /**
     * An explicit, valid override wins over the active config — verbatim, no defaulting.
     */
    public function test_explicit_override_wins(): void {
        $this->resetAfterTest();
        $out = (new embeddings_action_config_resolver())->resolve_with_overrides('custom-model', 256);
        $this->assertSame(['model' => 'custom-model', 'dimensions' => 256], $out);
    }

    /**
     * A model override is trimmed; an all-blank override falls back to the default model.
     */
    public function test_model_override_trimmed_and_blank_defaults(): void {
        $this->resetAfterTest();
        $resolver = new embeddings_action_config_resolver();

        $this->assertSame('spaced-model', $resolver->resolve_with_overrides('  spaced-model  ', 256)['model']);
        $this->assertSame(
            orchestrator::EMBEDDINGS_DEFAULT_MODEL,
            $resolver->resolve_with_overrides('', 256)['model'],
            'An empty model string must fall back to the default, not be stored as "".'
        );
        $this->assertSame(
            orchestrator::EMBEDDINGS_DEFAULT_MODEL,
            $resolver->resolve_with_overrides('   ', 256)['model']
        );
    }

    /**
     * A non-positive dimension override falls back to the default dimensions.
     */
    public function test_non_positive_dimensions_default(): void {
        $this->resetAfterTest();
        $resolver = new embeddings_action_config_resolver();

        $this->assertSame(orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS, $resolver->resolve_with_overrides('m', 0)['dimensions']);
        $this->assertSame(orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS, $resolver->resolve_with_overrides('m', -10)['dimensions']);
    }

    /**
     * With no overrides the result is exactly the active config (here: the defaults, no provider
     * configured) — proving the no-override path is behaviourally identical to resolve().
     */
    public function test_no_override_equals_active_config(): void {
        $this->resetAfterTest();
        $resolver = new embeddings_action_config_resolver();

        $resolved = $resolver->resolve_with_overrides(null, null);
        $this->assertSame($resolver->resolve(), $resolved);
        $this->assertSame(orchestrator::EMBEDDINGS_DEFAULT_MODEL, $resolved['model']);
        $this->assertSame(orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS, $resolved['dimensions']);
    }
}
