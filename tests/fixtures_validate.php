<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Validate embeddings fixture for tests.
 * Quick script to verify fixture CSV is correct and contains embeddings.
 *
 * @package   mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$fixturepath = __DIR__ . '/agent/embedded_llm/fixtures/task_catalog_embeddings.csv';
$runtimepath = make_temp_directory('mod_booking/wizard') . '/task_catalog_embeddings.csv';

if (!file_exists($fixturepath)) {
    echo "ERROR: Fixture not found at $fixturepath\n";
    exit(1);
}

// Copy fixture to runtime location.
copy($fixturepath, $runtimepath);
echo "✓ Fixture copied to runtime\n";

// Read CSV and validate.
$file = fopen($runtimepath, 'r');
$headers = fgetcsv($file);
$rowcount = 0;

if (!$headers) {
    echo "ERROR: Cannot read CSV headers\n";
    exit(1);
}

$expectedheaders = [
    'task', 'intent', 'readonly', 'description',
    'minimal_input_json', 'example_input_json', 'message_triggers_json',
    'embedding_model', 'embedding_dimensions', 'content_hash', 'embedding_json',
];

if ($headers !== $expectedheaders) {
    echo "ERROR: CSV headers mismatch\n";
    echo "Expected: " . implode(", ", $expectedheaders) . "\n";
    echo "Got: " . implode(", ", $headers) . "\n";
    exit(1);
}

echo "✓ CSV headers are correct\n";

// Validate rows.
$embeddingcol = array_search('embedding_json', $headers);
while (($row = fgetcsv($file)) !== false) {
    $rowcount++;

    if (count($row) !== count($headers)) {
        echo "ERROR: Row $rowcount has incorrect column count\n";
        exit(1);
    }

    // Validate embedding is JSON array.
    if (!empty($row[$embeddingcol])) {
        $embedding = json_decode($row[$embeddingcol], true);
        if (!is_array($embedding)) {
            echo "ERROR: Row $rowcount has invalid embedding JSON\n";
            exit(1);
        }
        if (count($embedding) !== 1536) {
            echo "ERROR: Row $rowcount embedding has " . count($embedding) . " dimensions, expected 1536\n";
            exit(1);
        }
    }
}
fclose($file);

echo "✓ CSV validated: $rowcount tasks with embeddings\n";
echo "✓ Each embedding has 1536 dimensions (correct!)\n";

// Verify first few embeddings are floats.
$file = fopen($runtimepath, 'r');
fgetcsv($file); // Skip header.
for ($i = 0; $i < 3; $i++) {
    $row = fgetcsv($file);
    if ($row && !empty($row[$embeddingcol])) {
        $embedding = json_decode($row[$embeddingcol], true);
        $sample = array_slice($embedding, 0, 3);
        echo "  Task {$i}: sample embedding = [" . implode(", ", $sample) . ", ...]\n";
    }
}
fclose($file);

echo "\nSUCCESS: Fixture is valid and ready for tests!\n";
