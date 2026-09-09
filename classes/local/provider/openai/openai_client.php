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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Minimal REST client for OpenAI APIs used by local_studybuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class openai_client {
    /** @var string Base API URL. */
    private string $baseurl;

    /** @var string API key. */
    private string $apikey;

    /**
     * Constructor.
     *
     * @param string|null $apikey API key.
     * @param string|null $baseurl Base API URL.
     */
    public function __construct(?string $apikey = null, ?string $baseurl = null) {
        $this->apikey = $apikey ?? self::get_configured_apikey();
        $this->baseurl = rtrim($baseurl ?? self::get_configured_baseurl(), '/');
    }

    /**
     * Returns the configured API key.
     *
     * @return string
     */
    public static function get_configured_apikey(): string {
        $apikey = (string)get_config('local_studybuddy', 'openaiapikey');
        if ($apikey !== '') {
            return $apikey;
        }

        return (string)(get_config('aiprovider_openai', 'apikey') ?: '');
    }

    /**
     * Returns the configured base URL.
     *
     * @return string
     */
    public static function get_configured_baseurl(): string {
        return (string)(get_config('local_studybuddy', 'openaibaseurl') ?: 'https://api.openai.com/v1');
    }

    /**
     * Returns the configured Responses model.
     *
     * @return string
     */
    public static function get_configured_responses_model(): string {
        return (string)(get_config('local_studybuddy', 'openairesponsesmodel') ?: 'gpt-4.1');
    }

    /**
     * Sends a JSON request and decodes the JSON response.
     *
     * @param string $method HTTP method.
     * @param string $path API path, including optional query string.
     * @param array|null $json JSON body.
     * @param array $headers Extra headers.
     * @return array Decoded response.
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = []): array {
        $raw = $this->raw_request($method, $path, $json, $headers);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        return $decoded;
    }

    /**
     * Sends a request and returns the raw body.
     *
     * @param string $method HTTP method.
     * @param string $path API path.
     * @param array|null $json JSON body.
     * @param array $headers Extra headers.
     * @return string Raw response body.
     */
    public function raw_request(string $method, string $path, ?array $json = null, array $headers = []): string {
        $this->require_apikey();

        $url = $this->baseurl . $path;
        $httpheaders = $this->headers($headers);
        $body = '';
        if ($json !== null) {
            $body = json_encode($json, JSON_UNESCAPED_UNICODE);
            if ($body === false) {
                throw new \moodle_exception('invalidjson', 'local_studybuddy');
            }
        }

        $curl = new \curl(['proxy' => true]);
        $curl->setHeader($httpheaders);
        $method = strtoupper($method);
        if ($method === 'GET') {
            $raw = $curl->get($url);
        } else if ($method === 'DELETE') {
            $raw = $curl->delete($url, [], ['CURLOPT_USERPWD' => '']);
        } else if ($method === 'POST') {
            $raw = $curl->post($url, $body);
        } else {
            $raw = $curl->post($url, $body, ['CURLOPT_CUSTOMREQUEST' => $method]);
        }

        $status = (int)($curl->get_info()['http_code'] ?? 0);
        $error = (string)($curl->error ?? '');

        if ($curl->get_errno() !== 0 || $status === 0) {
            throw new \moodle_exception('curlerror', 'local_studybuddy', '', $error ?: $raw);
        }

        if ($status >= 400) {
            $decoded = json_decode($raw, true);
            $message = $decoded['error']['message'] ?? $raw;
            throw new \moodle_exception('openaiapierror', 'local_studybuddy', '', $message);
        }

        return (string)$raw;
    }

    /**
     * Uploads a file through multipart form data.
     *
     * @param string $filepath Local file path.
     * @param string $purpose OpenAI file purpose.
     * @param string|null $filename Upload filename.
     * @return array Decoded response.
     */
    public function upload_file(string $filepath, string $purpose = 'assistants', ?string $filename = null): array {
        $this->require_apikey();

        $curl = new \curl(['proxy' => true]);
        $headers = $this->headers([], false);
        $postfields = [
            'purpose' => $purpose,
            'file' => new \CURLFile($filepath, null, $filename ?? basename($filepath)),
        ];
        $curl->setHeader($headers);
        $raw = $curl->post($this->baseurl . '/files', $postfields);

        $status = (int)($curl->get_info()['http_code'] ?? 0);
        $error = (string)($curl->error ?? '');

        if ($curl->get_errno() !== 0 || $status === 0) {
            throw new \moodle_exception('curlerror', 'local_studybuddy', '', $error ?: $raw);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $raw) : $raw;
            throw new \moodle_exception('openaiapierror', 'local_studybuddy', '', $message);
        }

        return $decoded;
    }

    /**
     * Builds default headers.
     *
     * @param array $extra Extra headers.
     * @param bool $json Whether to include JSON content type.
     * @return string[]
     */
    private function headers(array $extra = [], bool $json = true): array {
        $headers = [
            'Authorization: Bearer ' . $this->apikey,
            'OpenAI-Beta: assistants=v2',
            'X-Client-Request-Id: local-studybuddy-' . bin2hex(random_bytes(8)),
        ];

        if ($json) {
            $headers[] = 'Content-Type: application/json';
        }

        $organization = (string)get_config('local_studybuddy', 'openaiorganization');
        if ($organization !== '') {
            $headers[] = 'OpenAI-Organization: ' . $organization;
        }

        $project = (string)get_config('local_studybuddy', 'openaiproject');
        if ($project !== '') {
            $headers[] = 'OpenAI-Project: ' . $project;
        }

        return array_merge($headers, $extra);
    }

    /**
     * Ensures the API key is configured.
     *
     * @return void
     */
    private function require_apikey(): void {
        if ($this->apikey === '') {
            throw new \moodle_exception('openai:apikeymissing', 'local_studybuddy');
        }
    }
}
