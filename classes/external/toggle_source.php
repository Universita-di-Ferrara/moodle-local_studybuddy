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

namespace local_studybuddy\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Enables or disables one source.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class toggle_source extends external_api {
    /**
     * Returns the external function parameters.
     *
     * @return external_function_parameters Function parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, external_description::get('courseid')),
            'documentid' => new external_value(PARAM_INT, external_description::get('documentid')),
            'enabled' => new external_value(PARAM_BOOL, external_description::get('enabledstate')),
        ]);
    }

    /**
     * Enables or disables a course source.
     *
     * @param int $courseid Course id.
     * @param int $documentid Document id.
     * @param bool $enabled New enabled state.
     * @return array Updated source data.
     */
    public static function execute(int $courseid, int $documentid, bool $enabled): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'documentid' => $documentid,
            'enabled' => $enabled,
        ]);
        $context = \context_course::instance((int)$params['courseid']);
        self::validate_context($context);
        require_capability('local/studybuddy:managesources', $context);

        return (new \local_studybuddy\local\source_service())->toggle_source(
            (int)$params['courseid'],
            (int)$params['documentid'],
            (bool)$params['enabled']
        );
    }

    /**
     * Returns the external function response structure.
     *
     * @return external_single_structure Response structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, external_description::get('documentid')),
            'title' => new external_value(PARAM_TEXT, external_description::get('sourcetitle')),
            'sourcetype' => new external_value(PARAM_TEXT, external_description::get('sourcetype')),
            'status' => new external_value(PARAM_TEXT, external_description::get('sourcestatus')),
            'enabled' => new external_value(PARAM_INT, external_description::get('enabledstate')),
            'cmid' => new external_value(PARAM_INT, external_description::get('cmid')),
            'timeindexed' => new external_value(PARAM_INT, external_description::get('indexedtime')),
        ]);
    }
}
