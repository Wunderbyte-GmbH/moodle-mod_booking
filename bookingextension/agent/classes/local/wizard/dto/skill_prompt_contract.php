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

namespace bookingextension_agent\local\wizard\dto;

use bookingextension_agent\local\wizard\contracts\skill_family_contract;

/**
 * Value object for explicit planner prompt contracts.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_prompt_contract {
    /** @var array */
    private array $payload;

    /**
     * Constructor.
     *
     * @param array $payload
     */
    public function __construct(array $payload = []) {
        $this->payload = $payload;
    }

    /**
     * Convert to normalized array payload.
     *
     * @return array
     */
    public function to_array(): array {
        $namespace = trim((string)($this->payload['namespace'] ?? ''));
        $family = trim((string)($this->payload['family'] ?? ''));
        if ($family === '' && $namespace !== '') {
            $family = $namespace . '.general';
        }

        return [
            'intent' => trim((string)($this->payload['intent'] ?? '')),
            'anchors' => self::normalize_string_list((array)($this->payload['anchors'] ?? [])),
            'minimal_input' => self::normalize_string_list((array)($this->payload['minimal_input'] ?? [])),
            // The schema's own required flags; the selection catalogue prints these as REQUIRED.
            'required_input' => self::normalize_string_list((array)($this->payload['required_input'] ?? [])),
            'accepts_empty_input' => (bool)($this->payload['accepts_empty_input'] ?? true),
            'required_groups' => array_values((array)($this->payload['required_groups'] ?? [])),
            'example_input' => is_array($this->payload['example_input'] ?? null) ? (array)$this->payload['example_input'] : [],
            'namespace' => $namespace,
            'family' => skill_family_contract::normalize_family($family),
            'version' => max(1, (int)($this->payload['version'] ?? 1)),
            'capabilities' => self::normalize_string_list((array)($this->payload['capabilities'] ?? [])),
            'context_scopes' => self::normalize_string_list((array)($this->payload['context_scopes'] ?? [])),
            'risk_class' => trim((string)($this->payload['risk_class'] ?? '')),
        ];
    }

    /**
     * Normalize a list of scalar/string values into unique, trimmed strings.
     *
     * @param mixed[] $values
     * @return string[]
     */
    private static function normalize_string_list(array $values): array {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $entry = trim($value);
            if ($entry === '') {
                continue;
            }
            $normalized[] = $entry;
        }

        return array_values(array_unique($normalized));
    }
}
