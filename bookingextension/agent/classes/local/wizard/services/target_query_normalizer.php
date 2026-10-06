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
 * Structural normalisation of the name a user typed for a target.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services;

/**
 * Two tolerances every resolver needs, and neither of them reads a word (#2453, wave 19).
 *
 * Baseline runs 25-27: "Madame wbtf_duval (at) example.invalid" reached the user resolvers, which test for an
 * "@" and then look the WHOLE string up as an e-mail address; and "Vorstellungs-Forum" reached the module
 * resolver, which compares the raw string with the forum named "Vorstellungsforum". Both are shape, not
 * language: an address-shaped token is an address wherever it stands, and two names that differ only in
 * hyphens, spaces or case are the same name. Nothing here knows a salutation, an article or a language.
 */
final class target_query_normalizer {
    /**
     * The one address-shaped token inside a query, or '' when there is none or more than one.
     *
     * @param string $query
     * @return string
     */
    public static function address_token(string $query): string {
        if (!preg_match_all('/[^\s@<>"\'()\[\],;]+@[^\s@<>"\'()\[\],;]+/u', $query, $matches)) {
            return '';
        }
        $tokens = array_values(array_unique($matches[0]));
        return count($tokens) === 1 ? rtrim($tokens[0], '.') : '';
    }

    /**
     * A comparison key for a name: letters and digits of any script, lower case, nothing else.
     *
     * "Vorstellungs-Forum", "Vorstellungsforum" and "vorstellungs forum" share one key.
     *
     * @param string $name
     * @return string
     */
    public static function name_key(string $name): string {
        $key = preg_replace('/[^\p{L}\p{N}]+/u', '', \core_text::strtolower($name));
        return (string)$key;
    }

    /** @var int Shortest word compared as a stem (letters and digits) - the course-names block uses the same length. */
    public const MIN_STEM = 5;

    /**
     * Narrow a query to the candidates whose name carries a stem of a request word, or a request word as stem (wave 35).
     *
     * "Brandschutzkurs" (AQ-1, L49 thread 17770) is one token: narrow_by_tokens() has nothing to split, and the course
     * "Brandschutz im Betrieb" stays unfound although its word "Brandschutz" opens the request word. A request word that
     * BEGINS WITH a name word of MIN_STEM letters or more and goes on (a compound) names that course - the comparison
     * the constructor's course-names block makes, narrowed to compounds: an equal word ("Course That Does Not Exist"
     * against "Test Course") is the token search's business and stays rejected there. At least half of the request's
     * words must be such compounds, and only the candidates with the longest common stem count. No word is known
     * here, the stored names decide.
     *
     * @param string $query The whole query as the planner sent it.
     * @param callable $search fn(string $prefix, int $limit): array of candidates carrying 'name' and 'id'.
     * @param int $limit Candidates per lookup.
     * @return array The candidates sharing the longest stem (one = resolved).
     */
    public static function narrow_by_stems(string $query, callable $search, int $limit = 25): array {
        preg_match_all('/[\p{L}\p{N}]{' . self::MIN_STEM . ',}/u', \core_text::strtolower($query), $found);
        $words = array_values(array_unique($found[0] ?? []));
        $scored = [];
        $matchedwords = [];
        foreach ($words as $word) {
            foreach ((array)$search(\core_text::substr($word, 0, self::MIN_STEM), $limit) as $candidate) {
                $id = (int)($candidate['id'] ?? 0);
                $name = \core_text::strtolower((string)($candidate['name'] ?? ''));
                preg_match_all('/[\p{L}\p{N}]{' . self::MIN_STEM . ',}/u', $name, $cf);
                foreach ((array)($cf[0] ?? []) as $nameword) {
                    if ($id > 0 && $word !== $nameword && str_starts_with($word, $nameword)) {
                        $score = \core_text::strlen($nameword);
                        $matchedwords[$word] = true;
                        if ($score > (int)($scored[$id]['score'] ?? 0)) {
                            $scored[$id] = ['score' => $score, 'candidate' => $candidate];
                        }
                    }
                }
            }
        }
        if (empty($scored) || count($matchedwords) * 2 < count($words)) {
            return [];
        }
        $best = max(array_column($scored, 'score'));
        return array_values(array_map(
            static fn(array $row): array => $row['candidate'],
            array_filter($scored, static fn(array $row): bool => $row['score'] === $best)
        ));
    }

    /**
     * Narrow a person query to one user by its name tokens when the whole query found nobody (wave 26).
     *
     * "Mr Okafor" (baseline runs 28-32, SVO-3) was searched as one string and matched nobody, although
     * "Okafor" is unique on the site. Every whitespace-separated token is searched on its own; a token
     * that matches nobody carries no meaning (a salutation, a title - no word list decides that), the
     * tokens that do match must agree on exactly one user. Several users → the candidates are returned
     * for the caller's ambiguity question; nothing matches → empty.
     *
     * @param string $query The whole query as the planner sent it.
     * @param callable $search fn(string $token, int $limit): array of candidates carrying 'userid' (or 'id').
     * @param int $limit Candidates per token lookup.
     * @return array The candidates every matching token agrees on (one = resolved).
     */
    public static function narrow_by_tokens(string $query, callable $search, int $limit = 25): array {
        // Whitespace and joining punctuation split tokens: "Buchdruck-Kurs" is the tokens Buchdruck and Kurs.
        $tokens = preg_split('/[\s\-\x{2013}\x{2014}\/,;:]+/u', trim($query)) ?: [];
        $tokens = array_values(array_unique(array_filter(array_map(
            static fn($token): string => trim((string)$token, " \t\n\r\0\x0B.,;:!?\"'()"),
            $tokens
        ), static fn(string $token): bool => $token !== '' && self::address_token($token) === '' && !ctype_digit($token))));
        if (count($tokens) < 2) {
            return [];
        }

        $agreed = null;
        $matched = 0;
        foreach ($tokens as $token) {
            $byid = [];
            foreach ((array)$search($token, $limit) as $candidate) {
                $candidateid = (int)($candidate['userid'] ?? ($candidate['id'] ?? 0));
                if ($candidateid > 0) {
                    $byid[$candidateid] = $candidate;
                }
            }
            if (empty($byid)) {
                continue;
            }
            $matched++;
            $agreed = $agreed === null ? $byid : array_intersect_key($agreed, $byid);
            if (empty($agreed)) {
                return [];
            }
        }

        // A name is mostly made of its own tokens: at least half of the query's tokens must be carried by the
        // candidate. One incidental hit inside a long unrelated query ("Course That Does Not Exist ...") is not a match.
        if ($agreed === null || $matched * 2 < count($tokens)) {
            return [];
        }

        return array_values($agreed);
    }
}
