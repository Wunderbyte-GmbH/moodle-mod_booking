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
 * A name search ignores accents and case on every database family.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\lookup\accent_insensitive_like;

/**
 * MySQL and MariaDB compare accent-insensitively through their collation; PostgreSQL ignores the accent flag of
 * sql_like(), so "federation" never found the activity "Fédération nationale". The helper folds both sides there.
 *
 * @covers \bookingextension_agent\local\wizard\services\lookup\accent_insensitive_like
 */
final class accent_insensitive_like_test extends \advanced_testcase {
    /**
     * Ids of the courses whose full name contains the value, through the helper.
     *
     * @param string $value
     * @return int[]
     */
    private function find(string $value): array {
        global $DB;
        [$sql, $params] = accent_insensitive_like::contains('fullname', 'needle', $value);
        return array_map('intval', array_keys($DB->get_records_select('course', $sql, $params, 'id', 'id')));
    }

    /**
     * Words without accents find names with accents and the other way round, in any case.
     */
    public function test_accents_and_case_are_ignored_both_ways(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $accented = (int)$gen->create_course(['fullname' => "Fédération nationale d'apiculture"])->id;
        $plain = (int)$gen->create_course(['fullname' => 'Federation Cup Training'])->id;
        $umlaut = (int)$gen->create_course(['fullname' => 'Übungsdaten Woche 3'])->id;

        $this->assertEqualsCanonicalizing([$accented, $plain], $this->find('federation'));
        $this->assertEqualsCanonicalizing([$accented, $plain], $this->find('FÉDÉRATION'));
        $this->assertSame([$umlaut], $this->find('ubungsdaten'));
        $this->assertSame([$umlaut], $this->find('Übungsdaten'));
    }

    /**
     * Non-matches stay non-matches, and LIKE wildcards in the value are taken literally.
     */
    public function test_no_match_and_wildcards_stay_literal(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $gen->create_course(['fullname' => "Fédération nationale d'apiculture"]);
        $percent = (int)$gen->create_course(['fullname' => 'Rabatt 50% Kurs'])->id;

        $this->assertSame([], $this->find('federations'));
        $this->assertSame([], $this->find('f_d_ration'));
        $this->assertSame([$percent], $this->find('50%'));
    }

    /**
     * Folding maps accented Latin letters to their base letter and leaves other scripts alone.
     */
    public function test_fold_keeps_other_scripts(): void {
        $this->assertSame('federation', accent_insensitive_like::fold('Fédération'));
        $this->assertSame('ubung', accent_insensitive_like::fold('Übung'));
        $this->assertSame('москва', accent_insensitive_like::fold('Москва'));
        $this->assertSame('芳 刘', accent_insensitive_like::fold('芳 刘'));
    }
}
