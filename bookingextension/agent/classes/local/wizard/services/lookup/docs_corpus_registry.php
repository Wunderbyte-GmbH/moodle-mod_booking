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
 * Registry of documentation corpora (the single corpus_id → docs-root authority).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\lookup;

/**
 * Single source of truth mapping a corpus_id to its absolute docs root.
 *
 * A document is addressed end-to-end as the pair (corpus_id, relpath); a bare relpath is
 * meaningless without its corpus_id. Index, lookup, skill result and preview all resolve the
 * absolute root exclusively through this registry, and every file read is confined to the
 * resolved root of that corpus_id.
 *
 * Corpora come exclusively from the admin "documentation corpora" textarea (the docscorpora
 * setting), parsed by {@see corpus_source_parser}. There is no component provider scan: the two
 * defaults (the agent's own docs and mod_booking) are seeded as component lines in the setting's
 * default value, so they are available out of the box without absolute paths.
 *
 * The registry distinguishes the *declared* corpus_ids (every syntactically valid line) from the
 * *resolvable* ones (root exists right now and lies under $CFG->dirroot). list()/resolve_root()
 * expose the resolvable set; {@see declared_corpus_ids()} exposes the declared set so the index
 * prune (B1) never deletes a declared-but-momentarily-unreadable corpus.
 */
class docs_corpus_registry {
    /** @var array|null Per-instance resolved map (corpus_id => abs root). */
    private ?array $corpora = null;

    /** @var array{declared: string[], resolvable: array, warnings: string[], notices: string[]}|null */
    private ?array $parsed = null;

    /** @var array|null Test override (only honoured under PHPUNIT_TEST). */
    private static ?array $testcorpora = null;

    /**
     * Constructor.
     *
     * @param array|null $corpora Explicit corpus_id => absolute root map (bypasses
     *                                           parsing; mainly for callers that already know the
     *                                           set). When null, the registry parses the setting.
     */
    public function __construct(?array $corpora = null) {
        if ($corpora !== null) {
            $this->corpora = $this->sanitize($corpora);
        }
    }

    /**
     * Return all resolvable corpora as corpus_id => absolute root.
     *
     * @return array
     */
    public function list(): array {
        if ($this->corpora !== null) {
            return $this->corpora;
        }
        if (self::$testcorpora !== null) {
            return $this->corpora = $this->sanitize(self::$testcorpora);
        }
        return $this->corpora = $this->parsed()['resolvable'];
    }

    /**
     * Return every declared corpus_id, including those whose root is currently unreadable.
     *
     * Prune decisions measure "superfluous" against this set (not against is_dir), so a declared
     * but momentarily unreadable corpus is never deleted from the index.
     *
     * @return string[]
     */
    public function declared_corpus_ids(): array {
        // An explicit corpus map (constructor / testing) is authoritative for both sets.
        if ($this->corpora !== null) {
            return array_keys($this->corpora);
        }
        if (self::$testcorpora !== null) {
            return array_keys($this->sanitize(self::$testcorpora));
        }
        return $this->parsed()['declared'];
    }

    /**
     * Resolve the absolute docs root for a corpus_id.
     *
     * @param string $corpusid
     * @return string|null Absolute root, or null when the corpus is unknown / unresolvable.
     */
    public function resolve_root(string $corpusid): ?string {
        return $this->list()[trim($corpusid)] ?? null;
    }

    /**
     * Whether a corpus_id is registered (resolvable).
     *
     * @param string $corpusid
     * @return bool
     */
    public function is_known(string $corpusid): bool {
        return $this->resolve_root($corpusid) !== null;
    }

    /**
     * Return the primary corpus_id (first resolvable), or null when none.
     *
     * @return string|null
     */
    public function primary(): ?string {
        foreach ($this->list() as $corpusid => $unused) {
            return $corpusid;
        }
        return null;
    }

    /**
     * Parse the configured textarea once per instance.
     *
     * @return array{declared: string[], resolvable: array, warnings: string[], notices: string[]}
     */
    private function parsed(): array {
        if ($this->parsed === null) {
            $textarea = (string)get_config('bookingextension_agent', 'docscorpora');
            $this->parsed = corpus_source_parser::parse($textarea);
        }
        return $this->parsed;
    }

    /**
     * Normalise an explicit corpus map (drop empties, trim roots).
     *
     * @param array $corpora
     * @return array
     */
    private function sanitize(array $corpora): array {
        $clean = [];
        foreach ($corpora as $corpusid => $root) {
            $corpusid = trim((string)$corpusid);
            $root = rtrim((string)$root, '/\\');
            if ($corpusid !== '' && $root !== '') {
                $clean[$corpusid] = $root;
            }
        }
        return $clean;
    }

    /**
     * Override the corpus set for unit tests (e.g. temp-dir corpora).
     *
     * @param array|null $corpora Map to use, or null to restore parsing.
     * @return void
     */
    public static function set_corpora_for_testing(?array $corpora): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('set_corpora_for_testing() is only available under PHPUNIT_TEST.');
        }
        self::$testcorpora = $corpora;
    }
}
