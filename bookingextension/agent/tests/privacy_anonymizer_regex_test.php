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
use ReflectionClass;
use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * Pins the anonymization token / email grammar that was consolidated into class constants (S9).
 *
 * These constants are the single source for every matcher and parser in the anonymizer, so a drift
 * here would silently break round-tripping (or leak PII). The tests therefore assert the exact
 * pattern strings AND their match/anchor semantics — not just "it compiles".
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_regex_test extends advanced_testcase {
    /**
     * Read a (private) class constant by name.
     *
     * @param string $name
     * @return string
     */
    private function const_value(string $name): string {
        return (string)(new ReflectionClass(privacy_anonymizer::class))->getConstant($name);
    }

    /**
     * The token find-pattern is pinned and matches word-bounded tokens anywhere in text.
     */
    public function test_token_find_pattern(): void {
        $find = $this->const_value('ANON_TOKEN_FIND_PATTERN');
        $this->assertSame('/\bANON_USER_\d+(?:@anon\.invalid|_[a-z]+)?\b/', $find);

        $this->assertSame(1, preg_match($find, 'see ANON_USER_7 here'));
        $this->assertSame(1, preg_match($find, 'field ANON_USER_12_email value'));
        $this->assertSame(0, preg_match($find, 'ANON_USER'), 'A bare prefix without a number is not a token.');
        $this->assertSame(0, preg_match($find, 'ANON_USER_7X'), 'Uppercase suffix is not part of the grammar.');

        // The email-shaped token is consumed as ONE token, never a bare id leaving "@anon.invalid" behind.
        $this->assertSame(1, preg_match($find, 'teacheremail ANON_USER_3@anon.invalid please', $m));
        $this->assertSame('ANON_USER_3@anon.invalid', $m[0]);
    }

    /**
     * The token parse-pattern is anchored and captures the stable id (without the field suffix).
     */
    public function test_token_parse_pattern_anchors_and_captures(): void {
        $parse = $this->const_value('ANON_TOKEN_PARSE_PATTERN');
        $this->assertSame('/^(ANON_USER_\d+)(?:@anon\.invalid|_[a-z]+)?$/', $parse);

        $this->assertSame(1, preg_match($parse, 'ANON_USER_42_firstname', $m));
        $this->assertSame('ANON_USER_42', $m[1], 'Group 1 must be the id without the field suffix.');

        $this->assertSame(1, preg_match($parse, 'ANON_USER_9', $m2));
        $this->assertSame('ANON_USER_9', $m2[1]);

        $this->assertSame(1, preg_match($parse, 'ANON_USER_5@anon.invalid', $m3));
        $this->assertSame('ANON_USER_5', $m3[1], 'The email-shaped token must parse to its base id.');

        // Anchored: an embedded token must NOT match (this is what separates parse from find).
        $this->assertSame(0, preg_match($parse, 'x ANON_USER_42 y'));
    }

    /**
     * The email subpattern is pinned and drives both the bare and the key=value matchers identically.
     */
    public function test_email_subpattern_drives_both_matchers(): void {
        $core = $this->const_value('EMAIL_SUBPATTERN');
        $this->assertSame('[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}', $core);

        $bare = '/\b' . $core . '\b/i';
        $kv = '/\b(email)\s*=\s*(' . $core . ')/iu';

        // Bare matcher (case-insensitive) finds an address in free text and rejects non-addresses.
        $this->assertSame(1, preg_match($bare, 'write to A.B+tag@Example.com today'));
        $this->assertSame(0, preg_match($bare, 'no at-sign in this sentence'));
        $this->assertSame(0, preg_match($bare, 'missing@tld'), 'A TLD of >=2 letters is required.');

        // Key=value matcher captures the address in group 2.
        $this->assertSame(1, preg_match($kv, 'email=foo.bar@sub.example.org', $kvm));
        $this->assertSame('foo.bar@sub.example.org', $kvm[2]);
    }

    /**
     * The public token detector reflects the find-pattern grammar exactly.
     */
    public function test_looks_like_anon_token_public_api(): void {
        $this->assertTrue(privacy_anonymizer::looks_like_anon_token('ANON_USER_1'));
        $this->assertTrue(privacy_anonymizer::looks_like_anon_token('ANON_USER_1_email'));
        $this->assertTrue(privacy_anonymizer::looks_like_anon_token('ANON_USER_1@anon.invalid'));
        $this->assertTrue(privacy_anonymizer::looks_like_anon_token('prefix ANON_USER_3 suffix'));
        $this->assertFalse(privacy_anonymizer::looks_like_anon_token('ANON_USER'));
        $this->assertFalse(privacy_anonymizer::looks_like_anon_token('just a name'));
    }
}
