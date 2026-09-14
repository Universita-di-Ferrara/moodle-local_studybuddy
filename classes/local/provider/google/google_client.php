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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Minimal REST client for Google Gemini APIs used by local_studybuddy.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class google_client {
    /** @var string Base Google Generative Language URL without API version. */
    private string $baseurl;

    /** @var string API key. */
    private string $apikey;

    /**
     * Constructor.
     *
     * @param string|null $apikey API key.
     * @param string|null $baseurl Base URL.
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
        return (string)get_config('local_studybuddy', 'googleapikey');
    }

    /**
     * Returns the configured base URL.
     *
     * @return string
     */
    public static function get_configured_baseurl(): string {
        return (string)(get_config('local_studybuddy', 'googlebaseurl') ?: 'https://generativelanguage.googleapis.com');
    }

    /**
     * Returns the configured Gemini generation model.
     *
     * @return string
     */
    public static function get_configured_generation_model(): string {
        return (string)(get_config('local_studybuddy', 'googlegenerationmodel') ?: 'gemini-3.5-flash');
    }

    /**
     * Sends a JSON request and decodes the JSON response.
     *
     * @param string $method HTTP method.
     * @param string $path API path relative to /v1beta.
     * @param array|null $json JSON body.
     * @param array $headers Extra headers.
     * @return array Decoded response.
     */
    public function request(string $method, string $path, ?array $json = null, array $headers = []): array {
        $raw = $this->raw_request($method, $path, $json, $headers);
        return $this->decode_response($raw);
    }

    /**
     * Sends a request to the Gemini Interactions API and decodes the response.
     *
     * @param string $method HTTP method.
     * @param string $path API path relative to /v1beta.
     * @param array|null $json JSON body.
     * @param array $headers Extra headers.
     * @return array Decoded response.
     */
    public function interaction_request(string $method, string $path, ?array $json = null, array $headers = []): array {
        $raw = $this->raw_request($method, $path, $json, $headers, 'v1beta');
        return $this->decode_response($raw);
    }

    /**
     * Decodes a JSON API response.
     *
     * @param string $raw Raw response body.
     * @return array Decoded response.
     */
    private function decode_response(string $raw): array {
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }

        return $decoded;
    }

    /**
     * Sends a JSON request and returns the raw response body.
     *
     * @param string $method HTTP method.
     * @param string $path API path relative to the selected API version.
     * @param array|null $json JSON body.
     * @param array $headers Extra headers.
     * @param string $apiversion API version.
     * @return string Raw body.
     */
    public function raw_request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        string $apiversion = 'v1beta'
    ): string {
        $this->require_apikey();

        $url = $this->url('/' . trim($apiversion, '/') . $path);
        $httpheaders = array_merge([
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->apikey,
            'X-Client-Request-Id: local-studybuddy-' . bin2hex(random_bytes(8)),
        ], $headers);
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
            $decoded = json_decode((string)$raw, true);
            $message = $decoded['error']['message'] ?? $raw;
            throw new \moodle_exception('googleapierror', 'local_studybuddy', '', $message);
        }

        return (string)$raw;
    }

    /**
     * Uploads a file directly into a Gemini File Search store.
     *
     * @param string $store Name like fileSearchStores/abc.
     * @param string $filepath Local file path.
     * @param string $filename Display name.
     * @param string $mimetype MIME type.
     * @param array $custommetadata Custom metadata fields.
     * @return array Operation response.
     */
    public function upload_to_file_search_store(
        string $store,
        string $filepath,
        string $filename,
        string $mimetype,
        array $custommetadata = []
    ): array {
        $this->require_apikey();

        if (!is_readable($filepath)) {
            throw new \moodle_exception('google:filereaderror', 'local_studybuddy', '', $filename);
        }

        $filesize = filesize($filepath);
        if ($filesize === false) {
            throw new \moodle_exception('google:filereaderror', 'local_studybuddy', '', $filename);
        }

        $metadata = [
            'displayName' => $filename,
        ];
        if (!empty($custommetadata)) {
            $metadata['customMetadata'] = $custommetadata;
        }

        // Gemini File Search expects a resumable upload, not multipart form data.
        $startcurl = new \curl(['proxy' => true]);
        $startcurl->setHeader([
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->apikey,
            'X-Goog-Upload-Protocol: resumable',
            'X-Goog-Upload-Command: start',
            'X-Goog-Upload-Header-Content-Length: ' . $filesize,
            'X-Goog-Upload-Header-Content-Type: ' . $mimetype,
            'X-Client-Request-Id: local-studybuddy-' . bin2hex(random_bytes(8)),
        ]);
        $startpayload = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        if ($startpayload === false) {
            throw new \moodle_exception('invalidjson', 'local_studybuddy');
        }
        $startresponse = $startcurl->post(
            $this->url('/upload/v1beta/' . $store . ':uploadToFileSearchStore'),
            $startpayload
        );
        $startstatus = (int)($startcurl->get_info()['http_code'] ?? 0);
        $starterror = (string)($startcurl->error ?? '');

        if ($startcurl->get_errno() !== 0 || $startstatus === 0) {
            throw new \moodle_exception('curlerror', 'local_studybuddy', '', $starterror ?: $startresponse);
        }

        if ($startstatus >= 400) {
            $decoded = json_decode((string)$startresponse, true);
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $startresponse) : $startresponse;
            throw new \moodle_exception('googleapierror', 'local_studybuddy', '', $message);
        }

        $uploadurl = $this->response_header($startcurl, 'X-Goog-Upload-URL');
        if ($uploadurl === '') {
            throw new \moodle_exception('google:uploadurlmissing', 'local_studybuddy');
        }

        $filehandle = fopen($filepath, 'rb');
        if ($filehandle === false) {
            throw new \moodle_exception('google:filereaderror', 'local_studybuddy', '', $filename);
        }

        $uploadcurl = new \curl(['proxy' => true]);
        $uploadcurl->setHeader([
            'Accept: application/json',
            'Content-Length: ' . $filesize,
            'Content-Type: ' . $mimetype,
            'X-Goog-Upload-Offset: 0',
            'X-Goog-Upload-Command: upload, finalize',
        ]);
        try {
            $raw = $uploadcurl->post($uploadurl, '', [
                'CURLOPT_CUSTOMREQUEST' => 'POST',
                'CURLOPT_UPLOAD' => true,
                'CURLOPT_INFILE' => $filehandle,
                'CURLOPT_INFILESIZE' => $filesize,
            ]);
            $status = (int)($uploadcurl->get_info()['http_code'] ?? 0);
            $error = (string)($uploadcurl->error ?? '');
        } finally {
            fclose($filehandle);
        }

        if ($uploadcurl->get_errno() !== 0 || $status === 0) {
            throw new \moodle_exception('curlerror', 'local_studybuddy', '', $error ?: $raw);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $raw) : $raw;
            throw new \moodle_exception('googleapierror', 'local_studybuddy', '', $message);
        }

        return $decoded;
    }

    /**
     * Returns a response header value from a Moodle curl request.
     *
     * @param \curl $curl Moodle curl instance.
     * @param string $name Header name.
     * @return string Header value or empty string.
     */
    private function response_header(\curl $curl, string $name): string {
        foreach ($curl->getResponse() as $header => $value) {
            if (strcasecmp((string)$header, $name) !== 0) {
                continue;
            }

            if (is_array($value)) {
                $value = end($value);
            }
            return trim((string)$value);
        }

        return '';
    }

    /**
     * Builds a Google API URL with the API key as query parameter.
     *
     * @param string $path Absolute path under the configured base URL.
     * @return string
     */
    private function url(string $path): string {
        $separator = str_contains($path, '?') ? '&' : '?';
        return $this->baseurl . $path . $separator . 'key=' . rawurlencode($this->apikey);
    }

    /**
     * Ensures the API key is configured.
     *
     * @return void
     */
    private function require_apikey(): void {
        if ($this->apikey === '') {
            throw new \moodle_exception('google:apikeymissing', 'local_studybuddy');
        }
    }
}
