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

namespace local_studybuddy\local;

use local_studybuddy\local\provider\google\google_chat_provider;
use local_studybuddy\local\provider\google\google_client;

/**
 * Tests the Gemini chat request and grounding response contract without network access.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_studybuddy\local\provider\google\google_chat_provider
 */
final class google_chat_provider_test extends \advanced_testcase {
    /**
     * Tests the request body sent to generateContent.
     *
     * @return void
     */
    public function test_builds_file_search_request(): void {
        $this->resetAfterTest();
        set_config('googlegenerationmodel', 'gemini-test', 'local_studybuddy');
        $client = new class ('test-key', 'https://example.test') extends google_client {
            /** @var array Captured request. */
            public array $captured = [];

            /**
             * Captures a request without contacting Google.
             *
             * @param string $method HTTP method.
             * @param string $path API path.
             * @param array|null $json JSON payload.
             * @param array $headers HTTP headers.
             * @return array
             */
            public function request(string $method, string $path, ?array $json = null, array $headers = []): array {
                $this->captured = [
                    'method' => $method,
                    'path' => $path,
                    'json' => $json,
                    'headers' => $headers,
                ];
                return [];
            }
        };

        $provider = new google_chat_provider($client);
        $provider->ask_course_kb(
            'fileSearchStores/course-5',
            [
                ['role' => 'user', 'content' => 'Question'],
                ['role' => 'assistant', 'content' => 'Answer'],
            ],
            'System instructions',
            ['google_metadata_filter' => 'moodle_cmid = 5']
        );

        $this->assertSame('POST', $client->captured['method']);
        $this->assertSame('/models/gemini-test:generateContent', $client->captured['path']);
        $this->assertSame('model', $client->captured['json']['contents'][1]['role']);
        $this->assertSame(
            'fileSearchStores/course-5',
            $client->captured['json']['tools'][0]['fileSearch']['fileSearchStoreNames'][0]
        );
        $this->assertSame(
            'moodle_cmid = 5',
            $client->captured['json']['tools'][0]['fileSearch']['metadataFilter']
        );
    }

    /**
     * Tests grounding extraction using the REST response field names.
     *
     * @return void
     */
    public function test_extracts_snake_case_grounding_sources(): void {
        $provider = new google_chat_provider(new google_client('test-key', 'https://example.test'));
        $sources = $provider->extract_sources([
            'candidates' => [[
                'grounding_metadata' => [
                    'grounding_chunks' => [[
                        'retrieved_context' => [
                            'title' => 'Course notes.pdf',
                            'text' => 'Relevant course content.',
                        ],
                    ], ],
                ],
            ], ],
        ]);

        $this->assertCount(1, $sources);
        $this->assertSame('Course notes.pdf', $sources[0]['title']);
        $this->assertSame(0.0, $sources[0]['score']);
        $this->assertSame('Relevant course content.', $sources[0]['excerpt']);
    }
}
