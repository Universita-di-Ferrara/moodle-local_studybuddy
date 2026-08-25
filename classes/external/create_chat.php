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
 * Creates a StudyBuddy chat.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_chat extends external_api {
    /**
     * Returns the external function parameters.
     *
     * @return external_function_parameters Function parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, external_description::get('courseid')),
            'title' => new external_value(PARAM_TEXT, external_description::get('chattitle'), VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Creates a chat for the current user.
     *
     * @param int $courseid Course id.
     * @param string|null $title Optional chat title.
     * @return array Created chat data.
     */
    public static function execute(int $courseid, ?string $title = null): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'title' => $title,
        ]);
        $context = \context_course::instance((int)$params['courseid']);
        self::validate_context($context);
        require_capability('local/studybuddy:chat', $context);

        $chat = (new \local_studybuddy\local\chat_service())->create_chat(
            (int)$params['courseid'],
            (int)$USER->id,
            $params['title']
        );

        return [
            'chatid' => (int)$chat->id,
            'status' => (string)$chat->status,
        ];
    }

    /**
     * Returns the external function response structure.
     *
     * @return external_single_structure Response structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'chatid' => new external_value(PARAM_INT, external_description::get('chatid')),
            'status' => new external_value(PARAM_TEXT, external_description::get('chatstatus')),
        ]);
    }
}
