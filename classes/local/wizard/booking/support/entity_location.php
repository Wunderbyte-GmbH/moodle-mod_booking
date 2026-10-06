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

namespace mod_booking\local\wizard\booking\support;

/**
 * Location handling of the wizard skills when local_entities manages locations.
 *
 * With local_entities installed the option form derives location and address from the linked
 * entity (option/fields/entities::prepare_save_field clears both columns and refills them from the
 * entity key). A wizard payload without that key therefore wipes the location and address of every
 * entity-linked option it touches (write-path baseline W1, finding W12, Wunderbyte-GmbH#2414).
 * This helper resolves a requested location to exactly one entity (data-derived, no word lists)
 * and hands the existing link back to the payload for updates that do not touch the location.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class entity_location {
    /** @var string Relation handler class of local_entities. */
    private const HANDLER = 'local_entities\\entitiesrelation_handler';

    /** @var string Issue code: the requested location matches no entity. */
    public const ISSUE_NOT_FOUND = 'LOCATION_ENTITY_NOT_FOUND';

    /** @var string Issue code: the requested location matches several entities. */
    public const ISSUE_AMBIGUOUS = 'LOCATION_ENTITY_AMBIGUOUS';

    /** @var int Maximum number of entities offered as remedies. */
    private const REMEDY_LIMIT = 20;

    /**
     * Whether local_entities manages locations on this site.
     *
     * @return bool
     */
    public static function installed(): bool {
        return class_exists(self::HANDLER);
    }

    /**
     * Form key of the option-level entity relation (defined by local_entities).
     *
     * @return string
     */
    public static function form_key(): string {
        return (defined('LOCAL_ENTITIES_FORM_ENTITYID') ? LOCAL_ENTITIES_FORM_ENTITYID : 'local_entities_entityid_') . '0';
    }

    /**
     * Entities whose name equals the requested location (case-insensitive, equipment excluded).
     *
     * @param string $location Requested location.
     * @return array<int,array{id:int,label:string}>
     */
    public static function resolve(string $location): array {
        global $DB;
        $location = trim($location);
        if ($location === '' || !self::installed()) {
            return [];
        }
        $sql = "SELECT id, name
                  FROM {local_entities}
                 WHERE " . $DB->sql_like('name', ':name', false) . "
                   AND (entitytype <> 'equipment' OR entitytype IS NULL)
              ORDER BY id";
        return self::rows($DB->get_records_sql($sql, ['name' => $DB->sql_like_escape($location)]));
    }

    /**
     * Existing (non-equipment) entities as structured remedies.
     *
     * @return array<int,array{id:int,label:string}>
     */
    public static function candidates(): array {
        global $DB;
        if (!self::installed()) {
            return [];
        }
        $sql = "SELECT id, name
                  FROM {local_entities}
                 WHERE (entitytype <> 'equipment' OR entitytype IS NULL)
              ORDER BY name, id";
        return self::rows($DB->get_records_sql($sql, [], 0, self::REMEDY_LIMIT));
    }

    /**
     * The entity currently linked to an option (0 when none).
     *
     * @param int $optionid Option id.
     * @return int
     */
    public static function existing_entityid(int $optionid): int {
        if (!self::installed() || $optionid <= 0) {
            return 0;
        }
        $handler = self::HANDLER;
        return (int)(new $handler('mod_booking', 'option'))->get_entityid_by_instanceid($optionid);
    }

    /**
     * The location name the option form stores for an entity.
     *
     * @param int $entityid Entity id.
     * @return string
     */
    public static function name_for(int $entityid): string {
        if (!self::installed() || $entityid <= 0) {
            return '';
        }
        $handler = self::HANDLER;
        return (string)$handler::get_name_for_filter($entityid);
    }

    /**
     * Preflight step for update-style skills: resolve a requested location to one entity.
     *
     * Exactly one match → 'entityid' in the prepared input, no issue. None or several → a
     * clarification issue with the entities as structured remedies (the caller returns invalid).
     * Without local_entities the input passes untouched.
     *
     * @param array $prepared Prepared input (by reference; receives 'entityid').
     * @param callable $string Resolves a booking language string: fn(string $id, $a): string.
     * @return array|null Issue or null.
     */
    public static function preflight_issue(array &$prepared, callable $string): ?array {
        $location = trim((string)($prepared['location'] ?? ''));
        if ($location === '' || !self::installed()) {
            return null;
        }
        $matches = self::resolve($location);
        if (count($matches) === 1) {
            $prepared['entityid'] = $matches[0]['id'];
            return null;
        }
        $notfound = empty($matches);
        return [
            'code' => $notfound ? self::ISSUE_NOT_FOUND : self::ISSUE_AMBIGUOUS,
            'severity' => 'needs_clarification',
            'field' => 'location',
            'message' => $string(
                $notfound ? 'agent_booking_location_entity_not_found' : 'agent_booking_location_entity_ambiguous',
                $location
            ),
            'user_question' => $string('agent_booking_location_entity_question', $location),
            'remedy_options' => $notfound ? self::candidates() : $matches,
        ];
    }

    /**
     * Map DB rows to remedies.
     *
     * @param array $records Records with id and name.
     * @return array<int,array{id:int,label:string}>
     */
    private static function rows(array $records): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = ['id' => (int)$record->id, 'label' => (string)$record->name];
        }
        return $rows;
    }
}
