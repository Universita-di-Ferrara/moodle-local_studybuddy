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

use local_studybuddy\local\provider\chat_provider_interface;

/**
 * Google Gemini chat provider using File Search.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class google_chat_provider implements chat_provider_interface {
    /** @var google_client REST client. */
    private google_client $client;

    /**
     * Constructor.
     *
     * @param google_client|null $client Optional Google API client.
     */
    public function __construct(?google_client $client = null) {
        $this->client = $client ?? new google_client();
    }

    /**
     * Sends a conversation to Gemini with File Search enabled.
     *
     * @param string|null $knowledgebaseid File Search store name.
     * @param array $conversation Conversation messages.
     * @param string $systemprompt System instructions.
     * @param array $options Provider options.
     * @return array Provider response.
     */
    public function ask_course_kb(?string $knowledgebaseid, array $conversation, string $systemprompt, array $options = []): array {
        $input = [];
        foreach ($conversation as $message) {
            if (!is_array($message) || !isset($message['role'])) {
                continue;
            }
            $text = (string)($message['content'] ?? '');
            $input[] = [
                'type' => $message['role'] === 'assistant' ? 'model_output' : 'user_input',
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ], ],
            ];
        }

        $body = [
            'model' => google_client::get_configured_generation_model(),
            'input' => $input,
            'system_instruction' => $systemprompt,
            'store' => false,
        ];

        if (!empty($knowledgebaseid)) {
            $body['tools'] = [[
                'type' => 'file_search',
                'file_search_store_names' => [$knowledgebaseid],
            ], ];
            if (!empty($options['google_metadata_filter'])) {
                $body['tools'][0]['metadata_filter'] = $options['google_metadata_filter'];
            }
        }

        return $this->client->interaction_request('POST', '/interactions', $body);
    }

    /**
     * Extracts assistant text from a Gemini Interaction response.
     *
     * @param array $response Provider response.
     * @return string Assistant text.
     */
    public function extract_text(array $response): string {
        return $this->extract_google_text($response);
    }

    /**
     * Extracts grounding sources from a Gemini response.
     *
     * @param array $response Provider response.
     * @return array Normalised sources.
     */
    public function extract_sources(array $response): array {
        return $this->extract_grounding_sources($response, 'google_file_search');
    }

    /**
     * Extracts text parts from a Gemini response.
     *
     * @param array $response Provider response.
     * @return string Extracted text.
     */
    private function extract_google_text(array $response): string {
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
     * Normalises grounding chunks returned by Gemini.
     *
     * @param array $response Provider response.
     * @param string $type Normalised source type.
     * @return array Normalised sources.
     */
    private function extract_grounding_sources(array $response, string $type): array {
        $sources = [];
        $seen = [];
        foreach (($response['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') !== 'model_output') {
                continue;
            }
            foreach (($step['content'] ?? []) as $content) {
                foreach (($content['annotations'] ?? []) as $annotation) {
                    if (($annotation['type'] ?? '') !== 'file_citation') {
                        continue;
                    }
                    $title = (string)($annotation['file_name'] ?? $annotation['source'] ??
                        $annotation['document_uri'] ?? get_string('source:unknown', 'local_studybuddy'));
                    $key = $title . '|' . (string)($annotation['document_uri'] ?? $annotation['source'] ?? '');
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $sources[] = [
                        'title' => $title,
                        'sourcetype' => $type,
                        'cmid' => 0,
                        'chunkid' => 0,
                        'documentid' => 0,
                        'score' => 0.0,
                        'excerpt' => '',
                    ];
                }
            }
        }

        return $sources;
    }
}
