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
        $contents = [];
        foreach ($conversation as $index => $message) {
            if (!is_array($message) || !isset($message['role'])) {
                continue;
            }
            $text = (string)($message['content'] ?? '');
            $contents[] = [
                'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [[
                    'text' => $text,
                ], ],
            ];
        }

        $body = [
            'systemInstruction' => [
                'parts' => [['text' => $systemprompt]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.2,
            ],
        ];

        if (!empty($knowledgebaseid)) {
            $body['tools'] = [[
                'fileSearch' => [
                    'fileSearchStoreNames' => [$knowledgebaseid],
                ],
            ], ];
            if (!empty($options['google_metadata_filter'])) {
                $body['tools'][0]['fileSearch']['metadataFilter'] = $options['google_metadata_filter'];
            }
        }

        return $this->client->request(
            'POST',
            '/' . $this->model_name(google_client::get_configured_generation_model()) . ':generateContent',
            $body
        );
    }

    /**
     * Extracts assistant text from a Gemini response.
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
     * Normalises a Gemini model resource name.
     *
     * @param string $model Model name.
     * @return string Resource name.
     */
    private function model_name(string $model): string {
        return str_starts_with($model, 'models/') ? $model : 'models/' . $model;
    }

    /**
     * Extracts text parts from a Gemini response.
     *
     * @param array $response Provider response.
     * @return string Extracted text.
     */
    private function extract_google_text(array $response): string {
        $text = '';
        foreach (($response['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                if (isset($part['text'])) {
                    $text .= (string)$part['text'] . "\n";
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
        $candidate = $response['candidates'][0] ?? [];
        $metadata = $candidate['groundingMetadata'] ?? $candidate['grounding_metadata'] ?? [];
        $chunks = $metadata['groundingChunks'] ?? $metadata['grounding_chunks'] ?? [];
        foreach ($chunks as $chunk) {
            $web = $chunk['web'] ?? [];
            $retrieved = $chunk['retrievedContext'] ?? $chunk['retrieved_context'] ?? [];
            $title = $retrieved['title'] ?? $web['title'] ?? $retrieved['uri'] ??
                get_string('source:unknown', 'local_studybuddy');
            $sources[] = [
                'title' => (string)$title,
                'sourcetype' => $type,
                'cmid' => 0,
                'chunkid' => 0,
                'documentid' => 0,
                'score' => 0.0,
                'excerpt' => shorten_text((string)($retrieved['text'] ?? $web['uri'] ?? ''), 180),
            ];
        }

        return $sources;
    }
}
