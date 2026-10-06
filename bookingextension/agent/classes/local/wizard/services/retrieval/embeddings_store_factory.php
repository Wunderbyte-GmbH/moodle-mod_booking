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
 * Factory that resolves the active embeddings store implementation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\retrieval;

/**
 * Single entry point for obtaining an {@see embeddings_store}.
 *
 * The `embeddingsstore` admin setting selects the backend (csv | db). It defaults to the CSV backend
 * until the migration flips it; the DB backend implements the same {@see embeddings_store} contract, so
 * switching it here changes no caller.
 */
class embeddings_store_factory {
    /**
     * Return the active embeddings store.
     *
     * @return embeddings_store
     */
    public static function instance(): embeddings_store {
        if (get_config('bookingextension_agent', 'embeddingsstore') === 'db') {
            return new db_embeddings_store(self::mappers());
        }
        return new csv_embeddings_store(self::mappers());
    }

    /**
     * The registered per-area row mappers, keyed by area.
     *
     * @return embeddings_row_mapper[]
     */
    public static function mappers(): array {
        return [
            docs_row_mapper::AREA => new docs_row_mapper(),
            skill_row_mapper::AREA => new skill_row_mapper(),
            site_content_row_mapper::AREA => new site_content_row_mapper(),
        ];
    }
}
