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

namespace local_studybuddy\local\provider\google;

use local_studybuddy\local\prompt_config;
use local_studybuddy\local\provider\ai_provider;

/**
 * Google Gemini implementation of the AI provider contract.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class google_provider implements ai_provider {
    /** @var google_client Google API client. */
    private google_client $client;

    /**
     * Constructor.
     *
     * @param google_client|null $client Client.
     */
    public function __construct(?google_client $client = null) {
        $this->client = $client ?? new google_client();
    }

    /**
     * Generates structured JSON using Gemini Interactions and File Search.
     *
     * @param string $prompt Controlled prompt.
     * @param array $context Context and params.
     * @return array
     */
    public function generate(string $prompt, array $context): array {
        $params = $context['params'] ?? [];
        $courseid = (int)($params['courseid'] ?? 0);
        $storeid = (string)($params['googlestoreid'] ?? '');

        if ($storeid === '' && $courseid > 0) {
            $storeid = (new google_course_file_search_store_service())->ensure_file_search_store($courseid)->externalid ?? '';
        }

        if ($storeid === '') {
            throw new \moodle_exception('providerstoremissing', 'local_studybuddy');
        }

        $body = [
            'model' => google_client::get_configured_generation_model(),
            'input' => $this->build_input($params),
            'system_instruction' => $prompt . "\n\n" .
                prompt_config::get('providerjsoninstructions', 'default:providerjsoninstructions'),
            'response_format' => [[
                'type' => 'text',
                'mime_type' => 'application/json',
            ], ],
            'store' => false,
        ];

        if ($storeid !== '') {
            $body['tools'] = [[
                'type' => 'file_search',
                'file_search_store_names' => [$storeid],
            ], ];
            if (!empty($params['google_metadata_filter'])) {
                $body['tools'][0]['metadata_filter'] = $params['google_metadata_filter'];
            }
        }

        $response = $this->client->interaction_request('POST', '/interactions', $body);
        $text = $this->extract_text($response);
        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            $decoded = $this->decode_json_from_text($text);
        }

        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        $decoded['_google'] = [
            'model' => google_client::get_configured_generation_model(),
            'file_search_store' => $storeid ?: null,
        ];

        return $decoded;
    }

    /**
     * Builds the user prompt input.
     *
     * @param array $params Generation params.
     * @return string
     */
    private function build_input(array $params): string {
        $text = prompt_config::format_activity_input($params) . "\n";
        $text .= prompt_config::get('providerfileinstructions', 'default:providerfileinstructions') . "\n";

        $text .= "\n" . prompt_config::get('providerjsoninstructions', 'default:providerjsoninstructions');

        return $text;
    }

    /**
     * Extracts text from model output steps in an Interaction response.
     *
     * @param array $response Response.
     * @return string
     */
    private function extract_text(array $response): string {
        $text = '';

        foreach (($response['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach (($step['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'text' && isset($content['text'])) {
                    $text .= (string)$content['text'] . "\n";
                }
            }
        }

        return trim($text);
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
