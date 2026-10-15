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

namespace bookingextension_agent\local\wizard\services\lookup;

/**
 * "Field contains value", ignoring case and accents on every database family.
 *
 * sql_like($field, $param, false, false) asks for an accent-insensitive comparison. MySQL and MariaDB deliver it through
 * their collation; the PostgreSQL driver ignores the flag, so a name search for "federation" never found "Fédération".
 * On PostgreSQL both sides are folded instead: the column through translate() and the value in PHP, with one table that
 * maps every accented Latin letter to its base letter. The table is derived from Moodle's own transliteration
 * (core_text::specialtoascii), letter by letter; no word is known here.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class accent_insensitive_like {
    /** @var array{0: string, 1: string}|null The translate() table: accented letters, their base letters. */
    private static ?array $table = null;

    /**
     * SQL condition and parameters for "$field contains $value", ignoring case and accents.
     *
     * @param string $field Column or expression.
     * @param string $param Parameter name; the condition uses it and two more names derived from it.
     * @param string $value The text to look for; LIKE wildcards in it are taken literally.
     * @return array{0: string, 1: array<string, string>}
     */
    public static function contains(string $field, string $param, string $value): array {
        global $DB;
        if ($DB->get_dbfamily() !== 'postgres') {
            return [$DB->sql_like($field, ':' . $param, false, false), [$param => '%' . $DB->sql_like_escape($value) . '%']];
        }
        [$from, $to] = self::table();
        return [
            'translate(' . $field . ', :' . $param . 'from, :' . $param . 'to) ILIKE :' . $param,
            [$param . 'from' => $from, $param . 'to' => $to, $param => '%' . $DB->sql_like_escape(self::fold($value)) . '%'],
        ];
    }

    /**
     * The text in lower case with every accented Latin letter replaced by its base letter.
     *
     * @param string $text
     * @return string
     */
    public static function fold(string $text): string {
        [$from, $to] = self::table();
        return \core_text::strtolower(strtr($text, array_combine(
            preg_split('//u', $from, -1, PREG_SPLIT_NO_EMPTY),
            str_split($to)
        )));
    }

    /**
     * Accented Latin letters (Latin-1 Supplement and Latin Extended-A/B) whose transliteration is one ASCII letter.
     *
     * @return array{0: string, 1: string}
     */
    private static function table(): array {
        if (self::$table === null) {
            $from = '';
            $to = '';
            for ($codepoint = 0xC0; $codepoint <= 0x24F; $codepoint++) {
                $letter = \core_text::code2utf8($codepoint);
                $base = \core_text::specialtoascii($letter);
                if (strlen($base) === 1 && ctype_alpha($base) && $base !== $letter) {
                    $from .= $letter;
                    $to .= strtolower($base);
                }
            }
            self::$table = [$from, $to];
        }
        return self::$table;
    }
}
