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
 * Shared base for the embeddings CSV repositories (skill-catalog and documentation).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

/**
 * RFC-4180 compliant, atomic CSV store for embedding rows.
 *
 * Both embedding stacks (skill-catalog and documentation) share the same storage shape:
 *  - a header row that must match the declared schema exactly,
 *  - payload columns that routinely contain JSON escapes (\/, \", \uXXXX) and commas/newlines,
 *  - a content_hash column enabling per-row reuse on rebuild.
 *
 * Concrete repositories declare their {@see headers()}, {@see required_nonempty_columns()},
 * a {@see default_csv_path()} and a {@see store_label()} for diagnostics. Everything else
 * (parsing, validation, atomic round-trip-verified write) lives here so the two stacks cannot
 * drift apart.
 *
 * Variant awareness (Phase F): a non-empty variant key (model + dimensions) is appended to the
 * file name as "…__<variant>.csv", so embeddings for different models live in separate files and
 * a model switch never invalidates the others. An empty variant key keeps the legacy, un-suffixed
 * file name — behaviour is therefore unchanged until a caller passes a variant.
 */
abstract class embeddings_csv_repository_base {
    /**
     * Empty CSV escape character.
     *
     * PHP's default fputcsv()/fgetcsv() escape character is a backslash, which is NOT RFC-4180 and
     * does not round-trip fields that contain backslashes — and our payload columns routinely
     * contain JSON escapes such as \/, \" and \uXXXX. With the default escape, fgetcsv() desyncs the
     * column count and rows are silently dropped on read. Passing an empty escape to BOTH the writer
     * and the reader makes them RFC-4180 compliant (internal quotes are doubled, never
     * backslash-escaped), so the store round-trips losslessly.
     */
    protected const CSV_ESCAPE = '';

    /** @var string|null Optional absolute path override (testing / alternate stores). */
    private $pathoverride;

    /** @var string Normalized variant key; empty means the legacy un-suffixed file. */
    private string $variantkey;

    /** @var resource|null Cached read handle for random-access row reads (see read_row_at()). */
    private $randomhandle = null;

    /** @var resource|null Open temp-file handle for a streaming write in progress. */
    private $writerhandle = null;

    /** @var string Temp path of the streaming write currently in progress. */
    private string $writertmp = '';

    /** @var int Data rows written so far in the streaming write currently in progress. */
    private int $writercount = 0;

    /**
     * Constructor.
     *
     * @param string|null $pathoverride Absolute CSV path to use instead of the default location.
     * @param string      $variantkey   Optional variant (e.g. "model__dims"); appended to the file name.
     */
    public function __construct(?string $pathoverride = null, string $variantkey = '') {
        $this->pathoverride = $pathoverride;
        $this->variantkey = self::normalize_variant_key($variantkey);
    }

    // -------------------------------------------------------------------------
    // Subclass contract.

    /**
     * Ordered CSV header columns for this store.
     *
     * @return string[]
     */
    abstract protected function headers(): array;

    /**
     * Columns that must be non-empty for a row to be considered schema-valid.
     *
     * @return string[]
     */
    abstract protected function required_nonempty_columns(): array;

    /**
     * Absolute default CSV path (ending in ".csv") used when no path override is given.
     *
     * @return string
     */
    abstract protected function default_csv_path(): string;

    /**
     * Short human label for corruption diagnostics (e.g. "skill-catalog embeddings").
     *
     * @return string
     */
    abstract protected function store_label(): string;

    // -------------------------------------------------------------------------
    // Path / variant.

    /**
     * Return the absolute CSV path, including the variant suffix when a variant key is set.
     *
     * @return string
     */
    public function get_csv_path(): string {
        $base = $this->pathoverride ?? $this->default_csv_path();
        if ($this->variantkey === '') {
            return $base;
        }

        // Insert "__<variant>" before the .csv extension (or append it when there is none).
        if (preg_match('/\.csv$/i', $base)) {
            return (string)preg_replace('/\.csv$/i', '__' . $this->variantkey . '.csv', $base);
        }

        return $base . '__' . $this->variantkey;
    }

    /**
     * The normalized variant key in effect (empty for the legacy file).
     *
     * @return string
     */
    public function get_variant_key(): string {
        return $this->variantkey;
    }

    /**
     * Absolute path of the variant-scoped source-fingerprint sidecar (next to the CSV).
     *
     * @return string
     */
    public function get_fingerprint_path(): string {
        return $this->get_csv_path() . '.fingerprint';
    }

    /**
     * Read the stored source fingerprint (the source state the index was last built from), or ''.
     *
     * @return string
     */
    public function read_fingerprint(): string {
        $path = $this->get_fingerprint_path();
        if (!is_readable($path)) {
            return '';
        }
        return trim((string)@file_get_contents($path));
    }

    /**
     * Atomically store the source fingerprint the index was just built from.
     *
     * @param string $fingerprint
     * @return void
     */
    public function write_fingerprint(string $fingerprint): void {
        $path = $this->get_fingerprint_path();
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, trim($fingerprint)) === false) {
            return;
        }
        @chmod($tmp, $this->get_default_file_permissions());
        @rename($tmp, $path);
    }

    /**
     * Delete the stored fingerprint (e.g. when the index is discarded).
     *
     * @return void
     */
    public function delete_fingerprint(): void {
        $path = $this->get_fingerprint_path();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Normalize a variant key to a filename-safe token.
     *
     * @param string $key
     * @return string
     */
    public static function normalize_variant_key(string $key): string {
        $key = strtolower(trim($key));
        $key = (string)preg_replace('/[^a-z0-9._-]+/', '_', $key);
        return trim($key, '_');
    }

    // -------------------------------------------------------------------------
    // Read.

    /**
     * Whether the CSV file exists and is readable.
     *
     * @return bool
     */
    public function exists(): bool {
        return is_readable($this->get_csv_path());
    }

    /**
     * Read all CSV rows as associative arrays.
     *
     * @return array[]
     */
    public function read_rows(): array {
        $path = $this->get_csv_path();
        if (!is_readable($path)) {
            return [];
        }

        [$rows, $skipped] = $this->parse_file($path);
        if ($skipped > 0) {
            // A malformed row means the on-disk store is corrupt. Never hide it: readiness checks
            // treat a short read as not-ready and schedule a full rebuild, and the rebuild's
            // round-trip validation (see write_rows) prevents a corrupt file from being republished.
            debugging(
                static::class . ": skipped {$skipped} malformed row(s) while reading {$path}; "
                    . 'the ' . $this->store_label() . ' file is corrupt and must be rebuilt.',
                DEBUG_DEVELOPER
            );
        }

        return $rows;
    }

    /**
     * Number of rows dropped during the parse of the on-disk file.
     *
     * Lets readiness checks distinguish a genuinely complete store from one that only parses
     * partially, so a corrupt file forces a rebuild instead of silently serving fewer rows.
     *
     * @return int
     */
    public function count_unreadable_rows(): int {
        $path = $this->get_csv_path();
        if (!is_readable($path)) {
            return 0;
        }

        [, $skipped] = $this->parse_file($path);
        return $skipped;
    }

    /**
     * Parse a CSV file into associative rows using RFC-4180 quoting (escape disabled).
     *
     * @param string $path
     * @return array{0: array[], 1: int} parsed rows and skipped-row count
     */
    protected function parse_file(string $path): array {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [[], 0];
        }

        $headers = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE);
        if (!is_array($headers) || !$this->headers_match($headers)) {
            fclose($handle);
            return [[], 0];
        }

        $cols = $this->headers();
        $expected = count($cols);
        $rows = [];
        $skipped = 0;
        while (($fields = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE)) !== false) {
            if ($fields === null || $fields === [null]) {
                // Blank line: not a data row, and not corruption.
                continue;
            }
            if (!is_array($fields) || count($fields) !== $expected) {
                $skipped++;
                continue;
            }
            $rows[] = array_combine($cols, $fields);
        }

        fclose($handle);
        return [$rows, $skipped];
    }

    /**
     * Validate row schema and non-empty key fields.
     *
     * @param array[] $rows
     * @return bool
     */
    public function is_valid_schema(array $rows): bool {
        if (empty($rows)) {
            return false;
        }

        $required = $this->required_nonempty_columns();
        foreach ($rows as $row) {
            foreach ($this->headers() as $key) {
                if (!array_key_exists($key, $row)) {
                    return false;
                }
            }

            foreach ($required as $key) {
                if (trim((string)($row[$key] ?? '')) === '') {
                    return false;
                }
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Write.

    /**
     * Atomically write rows to CSV, verifying a lossless round-trip before publishing.
     *
     * Writes to a temp file, re-reads it, and only renames it into place when every row parses
     * back. A corrupt serialization therefore never goes live (the previous file stays), and the
     * caller sees an exception — which lets Moodle's task scheduler apply faildelay backoff instead
     * of looping expensive embeddings rebuilds.
     *
     * @param array[] $rows
     * @return void
     */
    public function write_rows(array $rows): void {
        $path = $this->get_csv_path();
        $tmppath = $path . '.tmp';

        $handle = fopen($tmppath, 'wb');
        if ($handle === false) {
            throw new \moodle_exception('cannotwritetempfile', 'error');
        }

        $headers = $this->headers();
        fputcsv($handle, $headers, ',', '"', static::CSV_ESCAPE);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = (string)($row[$header] ?? '');
            }
            fputcsv($handle, $line, ',', '"', static::CSV_ESCAPE);
        }

        fclose($handle);
        @chmod($tmppath, $this->get_default_file_permissions());

        // Round-trip sanity check before the atomic swap.
        [$verified, $skipped] = $this->parse_file($tmppath);
        if ($skipped > 0 || count($verified) !== count($rows)) {
            @unlink($tmppath);
            throw new \moodle_exception(
                'embeddingscatalogwritecorrupt',
                'bookingextension_agent',
                '',
                (object)[
                    'expected' => count($rows),
                    'parsed' => count($verified),
                    'skipped' => $skipped,
                ]
            );
        }

        rename($tmppath, $path);
    }

    // -------------------------------------------------------------------------
    // Streaming API (bounded memory).
    //
    // These let a caller process an arbitrarily large store without ever holding the whole
    // catalog in memory: stream_rows() yields one row at a time; build_key_offset_index() +
    // read_row_at() give O(1)-memory reuse lookups; and begin/stream_write_row/commit perform an
    // incremental, atomic, round-trip-verified write. The corruption guard and atomic publish of
    // write_rows() are preserved (the verify just streams instead of collecting an array).

    /**
     * Yield each valid data row, one at a time, without building the full array.
     *
     * Same header check and malformed-row skipping as parse_file(), but memory stays at one row.
     *
     * @return \Generator
     */
    public function stream_rows(): \Generator {
        $path = $this->get_csv_path();
        if (!is_readable($path)) {
            return;
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }
        try {
            $headers = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE);
            if (!is_array($headers) || !$this->headers_match($headers)) {
                return;
            }
            $cols = $this->headers();
            $expected = count($cols);
            while (($fields = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE)) !== false) {
                if ($fields === null || $fields === [null]) {
                    continue;
                }
                if (!is_array($fields) || count($fields) !== $expected) {
                    continue;
                }
                yield array_combine($cols, $fields);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Streaming schema validity check: at least one row, and every (correctly-shaped) row has all
     * headers plus non-empty required columns — without ever building the full array.
     *
     * @return bool
     */
    public function stream_is_valid_schema(): bool {
        $required = $this->required_nonempty_columns();
        $cols = $this->headers();
        $seen = 0;
        foreach ($this->stream_rows() as $row) {
            foreach ($cols as $key) {
                if (!array_key_exists($key, $row)) {
                    return false;
                }
            }
            foreach ($required as $key) {
                if (trim((string)($row[$key] ?? '')) === '') {
                    return false;
                }
            }
            $seen++;
        }
        return $seen > 0;
    }

    /**
     * Public accessor for the required non-empty columns, so streaming callers can validate rows
     * inline in a single pass (e.g. readiness/coverage checks) without re-reading the file.
     *
     * @return string[]
     */
    public function get_required_nonempty_columns(): array {
        return $this->required_nonempty_columns();
    }

    /**
     * Build a lightweight index of the on-disk rows: caller-defined key => content_hash + byte
     * offset. Holds only a hash and an int per row (no embeddings), so it scales to any catalog.
     *
     * The offset is the byte position of the row's first physical line (captured via ftell() before
     * fgetcsv()), so it is correct even when a quoted field contains embedded newlines, and can be
     * passed straight to read_row_at().
     *
     * @param callable $keyfn fn(array $row): string — return '' to skip a row.
     * @return array{index: array, total: int}
     */
    public function build_key_offset_index(callable $keyfn): array {
        $index = [];
        $total = 0;
        $path = $this->get_csv_path();
        if (!is_readable($path)) {
            return ['index' => $index, 'total' => 0];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['index' => $index, 'total' => 0];
        }
        try {
            $headers = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE);
            if (!is_array($headers) || !$this->headers_match($headers)) {
                return ['index' => $index, 'total' => 0];
            }
            $cols = $this->headers();
            $expected = count($cols);
            while (true) {
                $offset = ftell($handle);
                $fields = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE);
                if ($fields === false) {
                    break;
                }
                if ($fields === null || $fields === [null]) {
                    continue;
                }
                if (!is_array($fields) || count($fields) !== $expected) {
                    continue;
                }
                $row = array_combine($cols, $fields);
                $total++;
                $key = (string)$keyfn($row);
                if ($key !== '') {
                    $index[$key] = [
                        'content_hash' => trim((string)($row['content_hash'] ?? '')),
                        'offset' => (int)$offset,
                    ];
                }
            }
        } finally {
            fclose($handle);
        }
        return ['index' => $index, 'total' => $total];
    }

    /**
     * Read a single row by its byte offset (as returned by build_key_offset_index()).
     *
     * Uses a cached read handle so repeated reuse reads share one open file; call
     * close_random_reader() when done.
     *
     * @param int $offset
     * @return array|null
     */
    public function read_row_at(int $offset): ?array {
        if ($this->randomhandle === null) {
            $path = $this->get_csv_path();
            if (!is_readable($path)) {
                return null;
            }
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return null;
            }
            $this->randomhandle = $handle;
        }
        if (fseek($this->randomhandle, $offset) !== 0) {
            return null;
        }
        $fields = fgetcsv($this->randomhandle, 0, ',', '"', static::CSV_ESCAPE);
        if (!is_array($fields)) {
            return null;
        }
        $cols = $this->headers();
        if (count($fields) !== count($cols)) {
            return null;
        }
        return array_combine($cols, $fields);
    }

    /**
     * Close the cached random-access read handle, if open.
     *
     * @return void
     */
    public function close_random_reader(): void {
        if ($this->randomhandle !== null) {
            fclose($this->randomhandle);
            $this->randomhandle = null;
        }
    }

    /**
     * Begin a streaming write: open the temp file and write the header.
     *
     * @return void
     */
    public function begin_stream_write(): void {
        if ($this->writerhandle !== null) {
            throw new \coding_exception('embeddings_csv_repository: a streaming write is already open.');
        }
        $this->writertmp = $this->get_csv_path() . '.tmp';
        $handle = fopen($this->writertmp, 'wb');
        if ($handle === false) {
            throw new \moodle_exception('cannotwritetempfile', 'error');
        }
        fputcsv($handle, $this->headers(), ',', '"', static::CSV_ESCAPE);
        $this->writerhandle = $handle;
        $this->writercount = 0;
    }

    /**
     * Write one data row to the streaming write in progress (fields ordered by headers()).
     *
     * @param array $row
     * @return void
     */
    public function stream_write_row(array $row): void {
        if ($this->writerhandle === null) {
            throw new \coding_exception('embeddings_csv_repository: no streaming write is open.');
        }
        $line = [];
        foreach ($this->headers() as $header) {
            $line[] = (string)($row[$header] ?? '');
        }
        fputcsv($this->writerhandle, $line, ',', '"', static::CSV_ESCAPE);
        $this->writercount++;
    }

    /**
     * Commit a streaming write: close the temp file, verify a lossless round-trip by streaming it
     * back (counting rows, never collecting), then atomically rename it into place.
     *
     * @return int Number of data rows published.
     */
    public function commit_stream_write(): int {
        if ($this->writerhandle === null) {
            throw new \coding_exception('embeddings_csv_repository: no streaming write is open.');
        }
        fclose($this->writerhandle);
        $this->writerhandle = null;
        @chmod($this->writertmp, $this->get_default_file_permissions());

        [$parsed, $skipped] = $this->count_parsed_rows($this->writertmp);
        if ($skipped > 0 || $parsed !== $this->writercount) {
            @unlink($this->writertmp);
            $tmp = $this->writertmp;
            $this->writertmp = '';
            $expected = $this->writercount;
            $this->writercount = 0;
            throw new \moodle_exception(
                'embeddingscatalogwritecorrupt',
                'bookingextension_agent',
                '',
                (object)['expected' => $expected, 'parsed' => $parsed, 'skipped' => $skipped]
            );
        }

        rename($this->writertmp, $this->get_csv_path());
        $written = $this->writercount;
        $this->writertmp = '';
        $this->writercount = 0;
        return $written;
    }

    /**
     * Abort a streaming write in progress: close and delete the temp file without publishing.
     *
     * @return void
     */
    public function discard_stream_write(): void {
        if ($this->writerhandle !== null) {
            fclose($this->writerhandle);
            $this->writerhandle = null;
        }
        if ($this->writertmp !== '' && is_file($this->writertmp)) {
            @unlink($this->writertmp);
        }
        $this->writertmp = '';
        $this->writercount = 0;
    }

    /**
     * Count parseable / skipped data rows in a CSV file without collecting them (memory O(1)).
     *
     * @param string $path
     * @return array{0:int,1:int} [parsed row count, skipped row count]
     */
    private function count_parsed_rows(string $path): array {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [0, 0];
        }
        $headers = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE);
        if (!is_array($headers) || !$this->headers_match($headers)) {
            fclose($handle);
            return [0, 0];
        }
        $expected = count($this->headers());
        $parsed = 0;
        $skipped = 0;
        while (($fields = fgetcsv($handle, 0, ',', '"', static::CSV_ESCAPE)) !== false) {
            if ($fields === null || $fields === [null]) {
                continue;
            }
            if (!is_array($fields) || count($fields) !== $expected) {
                $skipped++;
                continue;
            }
            $parsed++;
        }
        fclose($handle);
        return [$parsed, $skipped];
    }

    // -------------------------------------------------------------------------
    // Helpers.

    /**
     * Compare CSV headers against expected schema.
     *
     * @param string[] $headers
     * @return bool
     */
    protected function headers_match(array $headers): bool {
        $expected = $this->headers();
        if (count($headers) !== count($expected)) {
            return false;
        }

        foreach ($expected as $idx => $name) {
            if ((string)($headers[$idx] ?? '') !== $name) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get default file permissions from Moodle config.
     *
     * @return int
     */
    protected function get_default_file_permissions(): int {
        global $CFG;

        if (!empty($CFG->filepermissions)) {
            return (int)$CFG->filepermissions;
        }

        return 0644;
    }
}
