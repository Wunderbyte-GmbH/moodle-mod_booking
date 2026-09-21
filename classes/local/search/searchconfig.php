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
 * Configuration of the content sources for global search.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\search;

/**
 * Configuration of the content sources for global search.
 *
 * All settings live in the plugin config of mod_booking. The per area on/off switch is
 * provided by core on the search areas admin page and is not duplicated here.
 */
class searchconfig {
    /** @var string Description of the booking option. */
    public const SOURCE_DESCRIPTION = 'description';

    /** @var string Location, institution and address. */
    public const SOURCE_LOCATION = 'location';

    /** @var string String describing the weekday and time of the option. */
    public const SOURCE_DAYOFWEEKTIME = 'dayofweektime';

    /** @var string Identifier of the booking option. */
    public const SOURCE_IDENTIFIER = 'identifier';

    /** @var string Internal annotation of the booking option. */
    public const SOURCE_ANNOTATION = 'annotation';

    /** @var string Custom field values of the booking option. */
    public const SOURCE_CUSTOMFIELDS = 'customfields';

    /** @var string Names of the teachers of the booking option. */
    public const SOURCE_TEACHERS = 'teachers';

    /** @var string Names of the entities of the option and of its dates. */
    public const SOURCE_ENTITIES = 'entities';

    /** @var string Full name of the course connected to the booking option. */
    public const SOURCE_LINKEDCOURSE = 'linkedcourse';

    /** @var string Names of the competencies of the booking option. */
    public const SOURCE_COMPETENCIES = 'competencies';

    /** @var string Name of the certificate template of the booking option. */
    public const SOURCE_CERTIFICATE = 'certificate';

    /** @var string Attached files of the booking option. */
    public const SOURCE_ATTACHMENTS = 'attachments';

    /** @var string Invisible options are indexed, check_access restricts them. */
    public const INVISIBLE_RESTRICT = 'restrict';

    /** @var string Invisible options are never indexed. */
    public const INVISIBLE_NEVER = 'never';

    /**
     * All content sources which can be switched on and off.
     *
     * @return array
     */
    public static function get_all_sources(): array {
        return [
            self::SOURCE_DESCRIPTION,
            self::SOURCE_LOCATION,
            self::SOURCE_DAYOFWEEKTIME,
            self::SOURCE_IDENTIFIER,
            self::SOURCE_ANNOTATION,
            self::SOURCE_CUSTOMFIELDS,
            self::SOURCE_TEACHERS,
            self::SOURCE_ENTITIES,
            self::SOURCE_LINKEDCOURSE,
            self::SOURCE_COMPETENCIES,
            self::SOURCE_CERTIFICATE,
            self::SOURCE_ATTACHMENTS,
        ];
    }

    /**
     * The sources which are switched on when the plugin is installed.
     *
     * The annotation is an internal field, so it is off by default.
     *
     * @return array
     */
    public static function get_default_sources(): array {
        return array_values(array_diff(self::get_all_sources(), [self::SOURCE_ANNOTATION]));
    }

    /**
     * Whether the given content source flows into the search document.
     *
     * @param string $source one of the SOURCE_* constants
     * @return bool
     */
    public static function is_source_enabled(string $source): bool {
        $configured = get_config('booking', 'searchindexsources');

        if ($configured === false || $configured === null) {
            // Nothing stored yet: fall back to the defaults of a fresh installation.
            return in_array($source, self::get_default_sources(), true);
        }

        $enabled = array_filter(explode(',', (string) $configured), fn($value) => $value !== '');

        return in_array($source, $enabled, true);
    }

    /**
     * Shortnames of the custom fields which are indexed.
     *
     * An empty configuration means: index every custom field the handler returns.
     *
     * @return array empty array means "no restriction"
     */
    public static function get_customfield_shortnames(): array {
        $configured = get_config('booking', 'searchindexcustomfields');

        if (empty($configured)) {
            return [];
        }

        return array_values(array_filter(explode(',', (string) $configured), fn($value) => $value !== ''));
    }

    /**
     * Whether invisible booking options are put into the index at all.
     *
     * @return bool
     */
    public static function index_invisible_options(): bool {
        return self::get_invisible_policy() === self::INVISIBLE_RESTRICT;
    }

    /**
     * The configured policy for invisible booking options.
     *
     * @return string
     */
    public static function get_invisible_policy(): string {
        $configured = get_config('booking', 'searchinvisibleoptions');

        if ($configured === self::INVISIBLE_NEVER) {
            return self::INVISIBLE_NEVER;
        }

        return self::INVISIBLE_RESTRICT;
    }
}
