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
use bookingextension_agent\local\wizard\services\embeddings\embeddings_catalog_builder_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * The embeddings skill catalog must be identical for every session language.
 *
 * Wunderbyte-GmbH/Wunderbyte-GmbH#2420 (root cause of #2223 on the test VM): a skill that built its
 * description with get_string() produced a different anchor hash per session language. The rebuild runs
 * in English, so every non-English session found the catalog "stale" and discovery silently fell back to
 * the full slim_all catalog. Guards every registered skill of every plugin.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\services\embeddings\embeddings_catalog_builder_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class embeddings_catalog_language_independence_test extends advanced_testcase {
    /**
     * Run a callback with a minimal German language pack installed (PHPUnit ships only "en", and
     * get_string() silently falls back to English for a missing pack, which would hide the defect).
     * The plugin's own lang/de files are used once the pack exists; the pack is removed afterwards.
     *
     * @param callable $callback
     * @return mixed
     */
    private function with_german_pack(callable $callback) {
        global $CFG;
        $dir = $CFG->langotherroot . '/de';
        $created = !is_dir($dir);
        if ($created) {
            make_writable_directory($dir);
            file_put_contents($dir . '/langconfig.php', "<?php\n\$string['thislanguage'] = 'Deutsch';\n"
                . "\$string['parentlanguage'] = '';\n");
            get_string_manager()->reset_caches();
        }
        try {
            return $callback();
        } finally {
            if ($created) {
                remove_dir($dir);
                get_string_manager()->reset_caches();
            }
        }
    }

    /**
     * Anchor hashes of the full catalog in one session language.
     *
     * @param string $lang
     * @return array<string,string> anchor key => content hash
     */
    private function anchor_hashes(string $lang): array {
        force_current_language($lang);
        $rows = (new embeddings_catalog_builder_service())->build_full_catalog_rows(
            skill_registry_factory::get_default(),
            'wunderbyte-embeddings',
            3584
        );
        force_current_language('');
        $hashes = [];
        foreach ($rows as $row) {
            $hashes[$row['skill'] . '#' . $row['anchor_index']] = (string)$row['content_hash'];
        }
        ksort($hashes);
        return $hashes;
    }

    /**
     * English and German sessions expect exactly the same anchors and hashes.
     */
    public function test_catalog_hashes_are_identical_in_english_and_german(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $en = $this->anchor_hashes('en');
        $de = $this->with_german_pack(fn() => $this->anchor_hashes('de'));
        $this->assertNotEmpty($en);
        $differing = array_keys(array_diff_assoc($en, $de) + array_diff_assoc($de, $en));
        $this->assertSame([], $differing, 'anchors that change with the session language: ' . implode(', ', $differing));
    }
}
