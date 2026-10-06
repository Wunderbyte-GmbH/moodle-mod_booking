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
use bookingextension_agent\local\wizard\services\scaffold\skill_template_generator;

/**
 * A provided skillname must win over the description slug (#2338, MCP-F7).
 *
 * "list_free_rooms" + description "Lister les salles libres" produced
 * salles.lister_les_salles_libres; umlauts mangled to underscores and slugs cut mid-word
 * ("..._custom_actio"). The caller's name is namespaced and kept; the fallback slug
 * transliterates umlauts and cuts at a word boundary.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\scaffold\skill_template_generator
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scaffold_skillname_test extends advanced_testcase {
    /**
     * Generate a bundle and return the resulting skillname.
     *
     * @param array $spec
     * @return string
     */
    private function skillname(array $spec): string {
        $bundle = skill_template_generator::generate($spec + ['component' => 'local_salles']);
        return (string)($bundle['skillname'] ?? '');
    }

    /**
     * An un-namespaced skillname is namespaced and kept — never replaced by the description.
     */
    public function test_provided_skillname_wins(): void {
        $this->resetAfterTest();
        $name = $this->skillname([
            'skillname' => 'list_free_rooms',
            'description' => 'Lister les salles libres',
        ]);

        $this->assertSame('salles.list_free_rooms', $name);
    }

    /**
     * The description fallback transliterates umlauts and cuts at a word boundary.
     */
    public function test_description_fallback_is_readable(): void {
        $this->resetAfterTest();
        $name = $this->skillname([
            'description' => 'Eigene Fähigkeit für den Agenten aus einem local-Plugin bereitstellen können',
        ]);

        $this->assertStringContainsString('faehigkeit', $name, 'umlauts transliterate, never bare underscores');
        $this->assertDoesNotMatchRegularExpression(
            '/_[a-z]{1,2}$/',
            $name,
            'the slug must cut at a word boundary, not mid-word'
        );
    }
}
