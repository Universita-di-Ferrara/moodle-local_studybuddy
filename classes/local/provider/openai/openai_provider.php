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

namespace local_studybuddy\local\provider\openai;

use local_studybuddy\local\prompt_config;
use local_studybuddy\local\provider\ai_provider;

/**
 * OpenAI implementation of the AI provider contract.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_provider implements ai_provider {
    /** @var openai_responses_api Responses API. */
    private openai_responses_api $responsesapi;

    /**
     * Constructor.
     *
     * @param openai_client|null $client Client.
     */
    public function __construct(?openai_client $client = null) {
        $client = $client ?? new openai_client();
        $this->responsesapi = new openai_responses_api($client);
    }

    /**
     * Generates structured JSON using Responses API, with file_search when a vector store exists.
     *
     * @param string $prompt Controlled prompt.
     * @param array $context Context and params.
     * @return array
     */
    public function generate(string $prompt, array $context): array {
        $params = $context['params'] ?? [];
        $courseid = (int)($params['courseid'] ?? 0);
        $vectorstoreid = (string)($params['vectorstoreid'] ?? '');

        if ($vectorstoreid === '' && $courseid > 0) {
            $vectorstoreid = (new openai_course_vector_store_service())->ensure_vector_store($courseid)->externalid ?? '';
        }

        if ($vectorstoreid === '') {
            throw new \moodle_exception('providerstoremissing', 'local_studybuddy');
        }

        $input = $this->build_input($params);
        $response = $this->responsesapi->create(
            $input,
            $prompt . "\n\n" . prompt_config::get('providerjsoninstructions', 'default:providerjsoninstructions'),
            $vectorstoreid !== '' ? [$vectorstoreid] : [],
            null,
            null,
            $params['openai_file_filter'] ?? null
        );

        $text = $this->responsesapi->extract_text($response);
        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            $decoded = $this->decode_json_from_text($text);
        }

        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        $decoded['_openai'] = [
            'response_id' => $response['id'] ?? null,
            'vector_store_id' => $vectorstoreid ?: null,
        ];

        return $decoded;
    }

    /**
     * Builds a Responses API input item.
     *
     * @param array $params Generation params.
     * @return array
     */
    private function build_input(array $params): array {
        $text = prompt_config::format_activity_input($params) . "\n";
        $text .= prompt_config::get('providerfileinstructions', 'default:providerfileinstructions') . "\n";

        return [[
            'role' => 'user',
            'content' => [[
                'type' => 'input_text',
                'text' => $text,
            ], ],
        ], ];
    }

    /**
     * Attempts to recover JSON embedded in text.
     *
     * @param string $text Text.
     * @return array|null
     */
    private function decode_json_from_text(string $text): ?array {
        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
