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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Sends a StudyBuddy chat message.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_message extends external_api {
    /**
     * Returns the external function parameters.
     *
     * @return external_function_parameters Function parameters.
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'chatid' => new external_value(PARAM_INT, external_description::get('chatid')),
            'message' => new external_value(PARAM_TEXT, external_description::get('usermessage')),
        ]);
    }

    /**
     * Sends a message to a StudyBuddy chat.
     *
     * @param int $chatid Chat id.
     * @param string $message User message.
     * @return array Service response.
     */
    public static function execute(int $chatid, string $message): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'chatid' => $chatid,
            'message' => $message,
        ]);
        $service = new \local_studybuddy\local\chat_service();
        $chat = $service->get_chat((int)$params['chatid'], (int)$USER->id);
        $context = \context_course::instance((int)$chat->courseid);
        self::validate_context($context);

        $message = trim(clean_param($params['message'], PARAM_TEXT));
        if ($message === '') {
            throw new \invalid_parameter_exception(get_string('messageempty', 'local_studybuddy'));
        }

        return $service->send_message(
            (int)$params['chatid'],
            (int)$USER->id,
            $message
        );
    }

    /**
     * Returns the external function response structure.
     *
     * @return external_single_structure Response structure.
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'messageid' => new external_value(PARAM_INT, external_description::get('assistantmessageid')),
            'response' => new external_value(PARAM_RAW, external_description::get('assistantresponse')),
            'responseformat' => new external_value(PARAM_INT, external_description::get('responseformat')),
            'timecreated' => new external_value(PARAM_INT, external_description::get('creationtime')),
            'sources' => self::source_list_returns(),
        ]);
    }

    /**
     * Returns the source response structure.
     *
     * @return external_multiple_structure Source list structure.
     */
    private static function source_list_returns(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'title' => new external_value(PARAM_TEXT, external_description::get('sourcetitle')),
            'sourcetype' => new external_value(PARAM_TEXT, external_description::get('sourcetype')),
            'cmid' => new external_value(PARAM_INT, external_description::get('cmid')),
            'chunkid' => new external_value(PARAM_INT, external_description::get('chunkid')),
            'documentid' => new external_value(PARAM_INT, external_description::get('documentid')),
        ]));
    }
}
