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

namespace bookingextension_agent\local\wizard\services\embeddings;

use bookingextension_agent\local\wizard\embeddings_csv_repository;

/**
 * Compact storage of a skill-catalog embeddings fixture.
 *
 * The test fixtures hold one vector per row. As JSON text in a CSV file a vector of 3584 values
 * takes about 70 kB, as half-floats 7 kB. The repository keeps the fixtures in the compact form,
 * an index file with the columns of each row and a file of concatenated half-float vectors,
 * and the tests build the CSV file the embeddings store reads from it.
 *
 * The conversion is lossless or it fails: a value that is not exactly a half-float is refused.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embeddings_fixture_codec {
    /** @var string Column that holds the vector as JSON text. */
    private const VECTOR_COLUMN = 'embedding_json';

    /** @var float[]|null Value of each of the 65536 half-float bit patterns. */
    private static ?array $table = null;

    /**
     * Path of the index file that belongs to a CSV file.
     *
     * @param string $csvpath
     * @return string
     */
    public static function index_path(string $csvpath): string {
        return (string)preg_replace('/\.csv$/i', '', $csvpath) . '.json';
    }

    /**
     * Path of the vector file that belongs to a CSV file.
     *
     * @param string $csvpath
     * @return string
     */
    public static function vector_path(string $csvpath): string {
        return (string)preg_replace('/\.csv$/i', '', $csvpath) . '.f16';
    }

    /**
     * Write the compact form of a CSV file next to it.
     *
     * @param string $csvpath
     * @return int Number of rows written.
     */
    public static function pack(string $csvpath): int {
        $rows = (new embeddings_csv_repository($csvpath))->read_rows();
        $index = [];
        $bin = '';
        $dimensions = 0;
        foreach ($rows as $row) {
            $json = trim((string)($row[self::VECTOR_COLUMN] ?? ''));
            unset($row[self::VECTOR_COLUMN]);
            $row['has_vector'] = $json !== '';
            $index[] = $row;
            if ($json === '') {
                continue;
            }
            $vector = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
            if ($dimensions === 0) {
                $dimensions = count($vector);
            }
            if (count($vector) !== $dimensions) {
                throw new \coding_exception('embeddings fixture: the vectors of ' . basename($csvpath) . ' differ in length.');
            }
            $bin .= pack('v*', ...array_map([self::class, 'to_half'], $vector));
        }

        $payload = ['dimensions' => $dimensions, 'rows' => $index];
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        file_put_contents(self::index_path($csvpath), json_encode($payload, $flags) . "\n");
        file_put_contents(self::vector_path($csvpath), $bin);
        return count($rows);
    }

    /**
     * Build the CSV file from its compact form, unless it is there and newer.
     *
     * @param string $csvpath
     * @return bool Whether the CSV file exists afterwards.
     */
    public static function unpack(string $csvpath): bool {
        $indexpath = self::index_path($csvpath);
        $vectorpath = self::vector_path($csvpath);
        if (!is_readable($indexpath) || !is_readable($vectorpath)) {
            return is_readable($csvpath);
        }
        $newest = max((int)filemtime($indexpath), (int)filemtime($vectorpath));
        if (is_readable($csvpath) && (int)filemtime($csvpath) >= $newest) {
            return true;
        }

        $payload = json_decode((string)file_get_contents($indexpath), true, 8, JSON_THROW_ON_ERROR);
        $dimensions = (int)($payload['dimensions'] ?? 0);
        $bin = (string)file_get_contents($vectorpath);
        $table = self::half_table();
        $rows = [];
        $offset = 0;
        foreach ((array)($payload['rows'] ?? []) as $row) {
            $hasvector = !empty($row['has_vector']);
            unset($row['has_vector']);
            $row[self::VECTOR_COLUMN] = '';
            if ($hasvector) {
                $chunk = substr($bin, $offset, $dimensions * 2);
                if ($dimensions < 1 || strlen($chunk) !== $dimensions * 2) {
                    throw new \coding_exception('embeddings fixture: ' . basename($vectorpath) . ' does not match its index.');
                }
                $offset += $dimensions * 2;
                $vector = array_map(static fn(int $half): float => $table[$half], array_values(unpack('v*', $chunk)));
                $row[self::VECTOR_COLUMN] = json_encode($vector, JSON_THROW_ON_ERROR);
            }
            $rows[] = $row;
        }
        if ($offset !== strlen($bin)) {
            throw new \coding_exception('embeddings fixture: ' . basename($vectorpath) . ' does not match its index.');
        }

        (new embeddings_csv_repository($csvpath))->write_rows($rows);
        return true;
    }

    /**
     * Build the CSV file of every compact fixture in a directory.
     *
     * @param string $directory
     * @return void
     */
    public static function unpack_directory(string $directory): void {
        foreach (glob($directory . '/skill_catalog_embeddings__*.f16') ?: [] as $vectorpath) {
            self::unpack((string)preg_replace('/\.f16$/', '.csv', (string)$vectorpath));
        }
    }

    /**
     * The half-float bit pattern of a value that is exactly a half-float.
     *
     * @param int|float $value
     * @return int
     */
    public static function to_half($value): int {
        $value = (float)$value;
        if ($value == 0.0) {
            return 0;
        }
        $sign = $value < 0 ? 0x8000 : 0;
        $abs = abs($value);
        $exp = (int)floor(log($abs, 2));
        while (2 ** $exp > $abs) {
            $exp--;
        }
        while (2 ** ($exp + 1) <= $abs) {
            $exp++;
        }
        if ($exp < -14) {
            $field = 0;
            $mantissa = $abs * 2 ** 24;
        } else {
            $field = $exp + 15;
            $mantissa = ($abs / 2 ** $exp - 1) * 1024;
        }
        if ($field > 30 || $mantissa != floor($mantissa) || $mantissa > 1023) {
            throw new \coding_exception('embeddings fixture: ' . var_export($value, true) . ' is not a half-float.');
        }
        return $sign | ($field << 10) | (int)$mantissa;
    }

    /**
     * Value of each half-float bit pattern.
     *
     * @return float[]
     */
    private static function half_table(): array {
        if (self::$table === null) {
            $table = [];
            for ($half = 0; $half < 0x10000; $half++) {
                $sign = ($half & 0x8000) ? -1.0 : 1.0;
                $exp = ($half >> 10) & 0x1f;
                $mantissa = $half & 0x3ff;
                $table[$half] = $exp === 0 ? $sign * $mantissa * 2 ** -24 : $sign * (1 + $mantissa / 1024) * 2 ** ($exp - 15);
            }
            self::$table = $table;
        }
        return self::$table;
    }
}
