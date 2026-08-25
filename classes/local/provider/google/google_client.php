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
        return (string)(get_config('local_studybuddy', 'googlegenerationmodel') ?: 'gemini-3-flash-preview');
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
     * @param string $path API path relative to /v1beta.
     * @param array|null $json JSON body.
     * @param array $headers Extra headers.
     * @return string Raw body.
     */
    public function raw_request(string $method, string $path, ?array $json = null, array $headers = []): string {
        $this->require_apikey();

        $curl = curl_init($this->url('/v1beta' . $path));
        $httpheaders = array_merge([
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->apikey,
            'X-Client-Request-Id: local-studybuddy-' . bin2hex(random_bytes(8)),
        ], $headers);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $httpheaders,
        ]);

        if ($json !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($raw === false) {
            throw new \moodle_exception('curlerror', 'local_studybuddy', '', $error);
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
        $startcurl = curl_init($this->url('/upload/v1beta/' . $store . ':uploadToFileSearchStore'));
        curl_setopt_array($startcurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $this->apikey,
                'X-Goog-Upload-Protocol: resumable',
                'X-Goog-Upload-Command: start',
                'X-Goog-Upload-Header-Content-Length: ' . $filesize,
                'X-Goog-Upload-Header-Content-Type: ' . $mimetype,
                'X-Client-Request-Id: local-studybuddy-' . bin2hex(random_bytes(8)),
            ],
            CURLOPT_POSTFIELDS => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);

        $startresponse = curl_exec($startcurl);
        $startstatus = curl_getinfo($startcurl, CURLINFO_HTTP_CODE);
        $startheadersize = curl_getinfo($startcurl, CURLINFO_HEADER_SIZE);
        $starterror = curl_error($startcurl);
        curl_close($startcurl);

        if ($startresponse === false) {
            throw new \moodle_exception('curlerror', 'local_studybuddy', '', $starterror);
        }

        $startbody = substr((string)$startresponse, $startheadersize);
        if ($startstatus >= 400) {
            $decoded = json_decode($startbody, true);
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $startbody) : $startbody;
            throw new \moodle_exception('googleapierror', 'local_studybuddy', '', $message);
        }

        $uploadurl = '';
        $responseheaders = substr((string)$startresponse, 0, $startheadersize);
        if (preg_match('/^X-Goog-Upload-URL:\s*(.+)$/im', $responseheaders, $matches)) {
            $uploadurl = trim($matches[1]);
        }
        if ($uploadurl === '') {
            throw new \moodle_exception('google:uploadurlmissing', 'local_studybuddy');
        }

        $filehandle = fopen($filepath, 'rb');
        if ($filehandle === false) {
            throw new \moodle_exception('google:filereaderror', 'local_studybuddy', '', $filename);
        }

        $uploadcurl = curl_init($uploadurl);
        curl_setopt_array($uploadcurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $filehandle,
            CURLOPT_INFILESIZE => $filesize,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Length: ' . $filesize,
                'Content-Type: ' . $mimetype,
                'X-Goog-Upload-Offset: 0',
                'X-Goog-Upload-Command: upload, finalize',
            ],
        ]);

        $raw = curl_exec($uploadcurl);
        $status = curl_getinfo($uploadcurl, CURLINFO_HTTP_CODE);
        $error = curl_error($uploadcurl);
        fclose($filehandle);
        curl_close($uploadcurl);

        if ($raw === false) {
            throw new \moodle_exception('curlerror', 'local_studybuddy', '', $error);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $raw) : $raw;
            throw new \moodle_exception('googleapierror', 'local_studybuddy', '', $message);
        }

        return $decoded;
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
