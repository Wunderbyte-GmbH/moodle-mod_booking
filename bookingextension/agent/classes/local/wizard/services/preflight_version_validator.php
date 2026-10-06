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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\skill_registry;

/**
 * Layer-1 skill-version validator.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preflight_version_validator {
    /** @var string Skill registration issue code. */
    public const ISSUE_SKILL_NOT_REGISTERED = 'SKILL_NOT_REGISTERED';

    /** @var skill_registry */
    private skill_registry $registry;

    /** @var skill_version_policy */
    private skill_version_policy $policy;

    /**
     * Constructor.
     *
     * @param skill_registry $registry
     * @param skill_version_policy|null $policy
     */
    public function __construct(skill_registry $registry, ?skill_version_policy $policy = null) {
        $this->registry = $registry;
        $this->policy = $policy ?? new skill_version_policy();
    }

    /**
     * Validate skill registration + skill version for one command.
     *
     * @param array $command
     * @return array{valid:bool,error_class:string,issue_codes:string[],errors:string[]}
     */
    public function validate(array $command): array {
        $skillname = trim((string)($command['skill'] ?? ''));
        if ($skillname === '') {
            return [
                'valid' => true,
                'error_class' => '',
                'issue_codes' => [],
                'errors' => [],
            ];
        }

        $contract = $this->registry->get_skill_contract($skillname);
        if ($contract === null) {
            return [
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => [self::ISSUE_SKILL_NOT_REGISTERED],
                'errors' => ['Skill "' . $skillname . '" is not registered.'],
            ];
        }

        $requestedversion = $this->resolve_requested_version($command, $contract);
        if ($requestedversion <= 0) {
            return [
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => [skill_version_policy::ISSUE_UNSUPPORTED],
                'errors' => ['Field "version" must be an integer > 0 when provided.'],
            ];
        }

        $evaluation = $this->policy->evaluate($contract, $requestedversion);
        if (($evaluation['status'] ?? '') === skill_version_policy::STATUS_UNSUPPORTED) {
            $supportedversion = (int)($evaluation['supported_version'] ?? 1);
            return [
                'valid' => false,
                'error_class' => 'schema_error',
                'issue_codes' => array_values((array)($evaluation['issue_codes'] ?? [skill_version_policy::ISSUE_UNSUPPORTED])),
                'errors' => [
                    'Unsupported skill version "' . $requestedversion . '" for skill "' . $skillname
                    . '". Supported version is "' . $supportedversion . '".',
                ],
            ];
        }

        if (($evaluation['status'] ?? '') === skill_version_policy::STATUS_DEPRECATED) {
            return [
                'valid' => true,
                'error_class' => '',
                'issue_codes' => array_values((array)($evaluation['issue_codes'] ?? [skill_version_policy::ISSUE_DEPRECATED])),
                'errors' => [],
            ];
        }

        return [
            'valid' => true,
            'error_class' => '',
            'issue_codes' => [],
            'errors' => [],
        ];
    }

    /**
     * Resolve requested version from command or fallback to contract version.
     *
     * @param array $command
     * @param array $contract
     * @return int
     */
    private function resolve_requested_version(array $command, array $contract): int {
        if (array_key_exists('version', $command)) {
            $value = $command['version'];
        } else if (array_key_exists('skill_version', $command)) {
            $value = $command['skill_version'];
        } else {
            return (int)($contract['version'] ?? 1);
        }

        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int)$value;
        }

        return 0;
    }
}
