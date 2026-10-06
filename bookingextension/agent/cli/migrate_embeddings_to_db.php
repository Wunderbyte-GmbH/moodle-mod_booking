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
 * Import a committed CSV embeddings index into the DB backend (no re-embedding).
 *
 * The CSV rows already carry their vectors, so migration is a plain copy through the shared
 * embeddings_store contract (one generation swap + fingerprint carry-over). Run this once before or
 * after flipping the `embeddingsstore` setting to `db` to avoid re-embedding the docs corpus.
 *
 * Usage:
 *   php migrate_embeddings_to_db.php [--area=docs] [--model=NAME] [--dims=N]
 *
 * Options:
 *   --area=NAME   Embeddings area to migrate (default docs).
 *   --model=NAME  Embedding model (default: the active configured model).
 *   --dims=N      Embedding dimensions (default: the active configured dimensions).
 *   --help        Show this help.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\retrieval\docs_row_mapper;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_migration_service;

[$options, $unrecognised] = cli_get_params(
    [
        'area'  => docs_row_mapper::AREA,
        'model' => '',
        'dims'  => 0,
        'help'  => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln("Import a committed CSV embeddings index into the DB backend (no re-embedding).");
    cli_writeln("Usage: php migrate_embeddings_to_db.php [--area=docs] [--model=NAME] [--dims=N]");
    exit(0);
}

$resolved = (new embeddings_action_config_resolver())->resolve();
$area = (string)$options['area'];
$model = trim((string)$options['model']) !== '' ? trim((string)$options['model']) : (string)$resolved['model'];
$dims = (int)$options['dims'] > 0 ? (int)$options['dims'] : (int)$resolved['dimensions'];

cli_writeln("Migrating area='{$area}' variant='{$model}__{$dims}' from CSV to the DB backend …");

$result = (new embeddings_store_migration_service())->migrate_csv_to_db($area, $model, $dims);

if ($result['status'] === 'skipped') {
    cli_writeln("Skipped ({$result['reason']}): nothing to migrate.");
    exit(0);
}

cli_writeln("Done: {$result['migrated']} rows imported into bx_agent_embeddings.");
cli_writeln("Set embeddingsstore=db (Site administration → the agent settings) to serve from the DB.");
exit(0);
