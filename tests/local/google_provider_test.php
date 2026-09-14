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

use local_studybuddy\local\provider\google\google_provider;
use local_studybuddy\local\provider\google\google_client;

/**
 * Tests structured generation through the Gemini Interactions API.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_studybuddy\local\provider\google\google_provider
 */
final class google_provider_test extends \advanced_testcase {
    /**
     * Tests the Interactions payload and structured output parsing.
     *
     * @return void
     */
    public function test_generates_structured_output(): void {
        $this->resetAfterTest();
        set_config('googlegenerationmodel', 'gemini-test', 'local_studybuddy');
        $client = new class ('test-key', 'https://example.test') extends google_client {
            /** @var array Captured request. */
            public array $captured = [];

            /**
             * Captures an interaction request without contacting Google.
             *
             * @param string $method HTTP method.
             * @param string $path API path.
             * @param array|null $json JSON payload.
             * @param array $headers HTTP headers.
             * @return array
             */
            public function interaction_request(
                string $method,
                string $path,
                ?array $json = null,
                array $headers = []
            ): array {
                $this->captured = [
                    'method' => $method,
                    'path' => $path,
                    'json' => $json,
                    'headers' => $headers,
                ];
                return [
                    'steps' => [[
                        'type' => 'model_output',
                        'content' => [[
                            'type' => 'text',
                            'text' => '{"title":"Generated activity"}',
                        ], ],
                    ], ],
                ];
            }
        };

        $provider = new google_provider($client);
        $result = $provider->generate('Create an activity.', [
            'params' => [
                'googlestoreid' => 'fileSearchStores/course-5',
                'activitytype' => 'quiz',
            ],
        ]);

        $this->assertSame('POST', $client->captured['method']);
        $this->assertSame('/interactions', $client->captured['path']);
        $this->assertSame('gemini-test', $client->captured['json']['model']);
        $this->assertFalse($client->captured['json']['store']);
        $this->assertSame('application/json', $client->captured['json']['response_format'][0]['mime_type']);
        $this->assertSame('Generated activity', $result['title']);
    }
}
