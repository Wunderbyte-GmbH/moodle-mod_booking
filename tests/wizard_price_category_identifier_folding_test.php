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

use mod_booking\local\wizard\options\skills\add_price_category_skill;

/**
 * A price category identifier with diacritics is folded, not rejected.
 *
 * F78 (baseline run 31 thread 9379 and re-run 9567, APC-3): the planner built {identifier: "retraités", name:
 * "Retraités"} for « Ajoute une catégorie tarifaire « retraités » ». The identifier check allowed only
 * [a-z0-9_-], so a complete, correct command ended as an input question - while the same prompt in run 30 had
 * produced "retraites" by chance. The identifier is a technical key: diacritics are folded to ASCII and the
 * case lowered before validation; the display name keeps its accents. Anything that is still not a key
 * (spaces, punctuation) is asked about as before.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\add_price_category_skill
 */
final class wizard_price_category_identifier_folding_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * Run 31 bytes: the accented identifier is valid and prepared as its ASCII key.
     */
    public function test_an_accented_identifier_is_folded(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $skill = new add_price_category_skill();

        $structure = $skill->check_structure(['identifier' => 'retraités', 'name' => 'Retraités']);
        $this->assertTrue((bool)($structure['valid'] ?? false), json_encode($structure));

        $preflight = $skill->preflight(['identifier' => 'retraités', 'name' => 'Retraités'], \context_system::instance()->id, 2);
        $prepared = (array)$preflight->preparedinput;
        $this->assertSame('retraites', (string)($prepared['identifier'] ?? ''), json_encode($preflight->to_array()));
        $this->assertSame('Retraités', (string)($prepared['name'] ?? ''), 'the display name keeps its accents');
    }

    /**
     * A value that is no key even after folding is still refused.
     */
    public function test_a_non_key_is_still_refused(): void {
        $this->resetAfterTest();
        $skill = new add_price_category_skill();

        $structure = $skill->check_structure(['identifier' => 'Rentner Preis!', 'name' => 'Rentner']);
        $this->assertFalse((bool)($structure['valid'] ?? true));
    }
}
