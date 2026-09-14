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

namespace local_studybuddy\local\provider\vertexai;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/filelib.php');

/**
 * Minimal REST client for Gemini and RAG Engine on Vertex AI.
 *
 * @package    local_studybuddy
 * @copyright  2026 Università degli Studi di Ferrara - Unife
 * @author     Andrea Bertelli <andrea.bertelli@unife.it>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vertexai_client {
    /** @var string API key. */
    private string $apikey;

    /** @var string OAuth2 access token or equivalent principal-bearing credential. */
    private string $accesstoken;

    /** @var string Base regional Vertex AI URL. */
    private string $baseurl;

    /**
     * Constructor.
     *
     * @param string|null $apikey Google Cloud API key.
     * @param string|null $baseurl Regional Vertex AI base URL.
     * @param string|null $accesstoken OAuth access token.
     */
    public function __construct(?string $apikey = null, ?string $baseurl = null, ?string $accesstoken = null) {
        $this->apikey = $apikey ?? self::get_configured_apikey();
        $this->accesstoken = $accesstoken ?? self::get_configured_principal_token();
        $this->baseurl = rtrim($baseurl ?? self::get_configured_baseurl(), '/');
    }

    /**
     * Returns the configured API key.
     *
     * @return string
     */
    public static function get_configured_apikey(): string {
        return (string)get_config('local_studybuddy', 'vertexaiapikey');
    }

    /**
     * Returns the configured OAuth2 access token.
     *
     * @return string
     */
    public static function get_configured_accesstoken(): string {
        return (string)get_config('local_studybuddy', 'vertexaiaccesstoken');
    }

    /**
     * Returns the configured service account JSON.
     *
     * @return string
     */
    public static function get_configured_service_account_json(): string {
        return (string)get_config('local_studybuddy', 'vertexaiserviceaccountjson');
    }

    /**
     * Returns an OAuth token from service account JSON or manual fallback.
     *
     * @return string
     */
    public static function get_configured_principal_token(): string {
        $serviceaccountjson = self::get_configured_service_account_json();
        if (trim($serviceaccountjson) !== '') {
            return self::get_service_account_access_token($serviceaccountjson);
        }

        return self::get_configured_accesstoken();
    }

    /**
     * Returns the configured regional base URL.
     *
     * @return string
     */
    public static function get_configured_baseurl(): string {
        $configured = trim((string)get_config('local_studybuddy', 'vertexaibaseurl'));
        if ($configured !== '') {
            return $configured;
        }

        $jsonbaseurl = self::get_service_account_json_value(['vertexai_baseurl', 'vertexaiBaseUrl', 'base_url', 'baseurl']);
        if ($jsonbaseurl !== '') {
            return $jsonbaseurl;
        }

        return self::get_base_url_for_location(self::get_configured_location());
    }

    /**
     * Returns the Vertex AI endpoint for a location.
     *
     * @param string $location Vertex AI location.
     * @return string Base URL.
     */
    public static function get_base_url_for_location(string $location): string {
        if ($location === 'global') {
            return 'https://aiplatform.googleapis.com';
        }

        if (in_array($location, ['eu', 'us'], true)) {
            return 'https://aiplatform.' . $location . '.rep.googleapis.com';
        }

        return 'https://' . $location . '-aiplatform.googleapis.com';
    }

    /**
     * Returns the configured Google Cloud project id.
     *
     * @return string
     */
    public static function get_configured_projectid(): string {
        $configured = trim((string)get_config('local_studybuddy', 'vertexaiprojectid'));
        if ($configured !== '') {
            return $configured;
        }

        return self::get_service_account_json_value(['project_id', 'projectId', 'project']);
    }

    /**
     * Returns the configured Vertex AI location.
     *
     * @return string
     */
    public static function get_configured_location(): string {
        $configured = trim((string)get_config('local_studybuddy', 'vertexailocation'));
        if ($configured !== '') {
            return $configured;
        }

        $jsonlocation = self::get_service_account_json_value([
            'vertexai_location',
            'vertexAiLocation',
            'vertexailocation',
            'location',
            'region',
        ]);
        if ($jsonlocation !== '') {
            return $jsonlocation;
        }

        return 'europe-west3';
    }

    /**
     * Returns the regional locations supported by Vertex AI RAG Engine.
     *
     * Multi-regions such as eu, us and global are intentionally excluded:
     * they can be available for model inference but are not RAG Engine locations.
     *
     * @return array<string, string> Supported locations.
     */
    public static function get_supported_rag_locations(): array {
        return [
            'us-central1' => 'us-central1',
            'us-east1' => 'us-east1',
            'us-east4' => 'us-east4',
            'europe-west3' => 'europe-west3',
            'europe-west4' => 'europe-west4',
            'europe-central2' => 'europe-central2',
            'europe-north1' => 'europe-north1',
            'europe-southwest1' => 'europe-southwest1',
            'europe-west1' => 'europe-west1',
            'europe-west2' => 'europe-west2',
            'europe-west6' => 'europe-west6',
            'europe-west8' => 'europe-west8',
            'europe-west9' => 'europe-west9',
            'asia-east1' => 'asia-east1',
            'asia-northeast1' => 'asia-northeast1',
            'asia-northeast3' => 'asia-northeast3',
            'asia-south1' => 'asia-south1',
            'asia-southeast1' => 'asia-southeast1',
            'us-east5' => 'us-east5',
            'us-south1' => 'us-south1',
            'us-west1' => 'us-west1',
            'us-west4' => 'us-west4',
        ];
    }

    /**
     * Checks whether a location can be used by Vertex AI RAG Engine.
     *
     * @param string|null $location Location to check, or the configured location.
     * @return bool
     */
    public static function is_supported_rag_location(?string $location = null): bool {
        $location = $location ?? self::get_configured_location();
        return array_key_exists($location, self::get_supported_rag_locations());
    }

    /**
     * Reads one scalar value from the configured service account JSON.
     *
     * @param array $keys Candidate keys.
     * @return string Configured value or empty string.
     */
    private static function get_service_account_json_value(array $keys): string {
        $serviceaccountjson = trim(self::get_configured_service_account_json());
        if ($serviceaccountjson === '') {
            return '';
        }

        $credentials = json_decode($serviceaccountjson, true);
        if (!is_array($credentials)) {
            return '';
        }

        foreach ($keys as $key) {
            if (!empty($credentials[$key]) && is_scalar($credentials[$key])) {
                return trim((string)$credentials[$key]);
            }
        }

        return '';
    }

    /**
     * Returns the configured Gemini generation model.
     *
     * @return string
     */
    public static function get_configured_generation_model(): string {
        return (string)(get_config('local_studybuddy', 'vertexaigenerationmodel') ?: 'gemini-2.5-flash');
    }

    /**
     * Returns the Google Cloud OAuth scope used by Vertex AI.
     *
     * @return string
     */
    private static function oauth_scope(): string {
        return 'https://www.googleapis.com/auth/cloud-platform';
    }

    /**
     * Sends a JSON request and decodes the response.
     *
     * @param string $method HTTP method.
     * @param string $path API path relative to the configured base URL.
     * @param array|null $json JSON body.
     * @param array $headers Extra headers.
     * @param bool $requireprincipal Whether a principal-bearing credential is required.
     * @return array Decoded response.
     */
    public function request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        bool $requireprincipal = false
    ): array {
        $raw = $this->raw_request($method, $path, $json, $headers, $requireprincipal);
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
     * @param string $path API path relative to the configured base URL.
     * @param array|null $json JSON body.
     * @param array $headers Extra headers.
     * @param bool $requireprincipal Whether a principal-bearing credential is required.
     * @return string Raw response body.
     */
    public function raw_request(
        string $method,
        string $path,
        ?array $json = null,
        array $headers = [],
        bool $requireprincipal = false
    ): string {
        $this->require_configuration($requireprincipal);

        $url = $this->url($path);
        $httpheaders = array_merge([
            'Content-Type: application/json',
            'X-Client-Request-Id: local-studybuddy-' . bin2hex(random_bytes(8)),
        ], $headers);
        $httpheaders = array_merge($httpheaders, $this->auth_headers());
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
        $curlerrno = $curl->get_errno();

        if ($curlerrno !== 0 || $status === 0) {
            $diagnostic = $error ?: (string)$raw;
            debugging(
                'StudyBuddy Vertex request failed: method=' . $method . '; path=' . $path .
                    '; httpcode=' . $status . '; curlerrno=' . $curlerrno . '; message=' . $diagnostic,
                DEBUG_DEVELOPER
            );
            throw new \moodle_exception('vertexaiapierror', 'local_studybuddy', '', $diagnostic);
        }

        if ($status >= 400) {
            $decoded = json_decode((string)$raw, true);
            $message = $decoded['error']['message'] ?? $raw;
            debugging(
                'StudyBuddy Vertex request rejected: method=' . $method . '; path=' . $path .
                    '; httpcode=' . $status . '; message=' . (string)$message,
                DEBUG_DEVELOPER
            );
            throw new \moodle_exception('vertexaiapierror', 'local_studybuddy', '', $message);
        }

        return (string)$raw;
    }

    /**
     * Uploads a local file into a Vertex AI RAG corpus.
     *
     * @param string $corpusname Corpus resource name.
     * @param string $filepath Local file path.
     * @param string $filename Display name.
     * @param string $mimetype MIME type.
     * @return array Operation response.
     */
    public function upload_rag_file(string $corpusname, string $filepath, string $filename, string $mimetype): array {
        $this->require_configuration(true);

        $postfields = [
            'metadata' => json_encode([
                'ragFile' => [
                    'displayName' => $filename,
                    'description' => get_string('vertexupload_description', 'local_studybuddy'),
                ],
            ], JSON_UNESCAPED_UNICODE),
            'file' => new \CURLFile($filepath, $mimetype, $filename),
        ];

        $url = $this->url('/upload/v1beta1/' . $corpusname . '/ragFiles:upload');

        $headers = array_merge([
            'Accept: application/json',
            'X-Goog-Upload-Protocol: multipart',
            'X-Client-Request-Id: local-studybuddy-' . bin2hex(random_bytes(8)),
        ], $this->auth_headers());
        $curl = new \curl(['proxy' => true]);
        $curl->setHeader($headers);
        $raw = $curl->post($url, $postfields);
        $status = (int)($curl->get_info()['http_code'] ?? 0);
        $error = (string)($curl->error ?? '');

        if ($curl->get_errno() !== 0 || $status === 0) {
            throw new \moodle_exception('vertexaiapierror', 'local_studybuddy', '', $error ?: $raw);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $raw) : $raw;
            throw new \moodle_exception('vertexaiapierror', 'local_studybuddy', '', $message);
        }

        return $decoded;
    }

    /**
     * Builds the standard Vertex AI Gemini generateContent path.
     *
     * @return string
     */
    public function generation_path(): string {
        $projectid = rawurlencode(self::get_configured_projectid());
        $location = rawurlencode(self::get_configured_location());
        $model = rawurlencode(self::get_configured_generation_model());

        return '/v1beta1/projects/' . $projectid . '/locations/' . $location .
            '/publishers/google/models/' . $model . ':generateContent';
    }

    /**
     * Builds the Vertex AI parent resource path.
     *
     * @return string
     */
    public function parent_name(): string {
        return 'projects/' . self::get_configured_projectid() . '/locations/' . self::get_configured_location();
    }

    /**
     * Builds a URL with API key authentication.
     *
     * @param string $path API path.
     * @return string
     */
    private function url(string $path): string {
        if ($this->accesstoken !== '') {
            return $this->baseurl . $path;
        }

        $separator = str_contains($path, '?') ? '&' : '?';
        return $this->baseurl . $path . $separator . 'key=' . rawurlencode($this->apikey);
    }

    /**
     * Ensures required settings exist.
     *
     * @param bool $requireprincipal Whether API key auth is not sufficient.
     * @return void
     */
    private function require_configuration(bool $requireprincipal = false): void {
        if ($requireprincipal && $this->accesstoken === '') {
            throw new \moodle_exception('vertexai:principalmissing', 'local_studybuddy');
        }

        if ($this->apikey === '' && $this->accesstoken === '') {
            throw new \moodle_exception('vertexai:apikeymissing', 'local_studybuddy');
        }

        if (self::get_configured_projectid() === '') {
            throw new \moodle_exception('vertexai:projectmissing', 'local_studybuddy');
        }
    }

    /**
     * Builds authentication headers.
     *
     * @return array
     */
    private function auth_headers(): array {
        if ($this->accesstoken !== '') {
            return ['Authorization: Bearer ' . $this->accesstoken];
        }

        return ['X-Goog-Api-Key: ' . $this->apikey];
    }

    /**
     * Gets and caches an OAuth access token generated from a service account key.
     *
     * @param string $serviceaccountjson Service account JSON.
     * @return string Access token.
     */
    private static function get_service_account_access_token(string $serviceaccountjson): string {
        $cachekey = 'vertexai_sa_token_' . sha1($serviceaccountjson);
        $expireskey = $cachekey . '_expires';
        $cachedtoken = (string)get_config('local_studybuddy', $cachekey);
        $cachedexpires = (int)get_config('local_studybuddy', $expireskey);

        if ($cachedtoken !== '' && $cachedexpires > time() + 120) {
            return $cachedtoken;
        }

        $credentials = self::decode_service_account_json($serviceaccountjson);
        $tokenresponse = self::request_service_account_token($credentials);
        $token = (string)($tokenresponse['access_token'] ?? '');
        if ($token === '') {
            throw new \moodle_exception('vertexai:tokenmissing', 'local_studybuddy');
        }

        $expiresin = max(300, (int)($tokenresponse['expires_in'] ?? 3600));
        set_config($cachekey, $token, 'local_studybuddy');
        set_config($expireskey, time() + $expiresin, 'local_studybuddy');

        return $token;
    }

    /**
     * Decodes and validates service account JSON.
     *
     * @param string $serviceaccountjson Service account JSON.
     * @return array Credentials.
     */
    private static function decode_service_account_json(string $serviceaccountjson): array {
        $credentials = json_decode($serviceaccountjson, true);
        if (!is_array($credentials)) {
            throw new \moodle_exception('vertexai:serviceaccountinvalid', 'local_studybuddy');
        }

        foreach (['client_email', 'private_key', 'token_uri'] as $field) {
            if (empty($credentials[$field]) || !is_string($credentials[$field])) {
                throw new \moodle_exception('vertexai:serviceaccountmissingfield', 'local_studybuddy', '', $field);
            }
        }

        if (($credentials['type'] ?? 'service_account') !== 'service_account') {
            throw new \moodle_exception('vertexai:serviceaccountinvalidtype', 'local_studybuddy');
        }

        return $credentials;
    }

    /**
     * Requests a Google OAuth token using a signed JWT assertion.
     *
     * @param array $credentials Service account credentials.
     * @return array Token response.
     */
    private static function request_service_account_token(array $credentials): array {
        $now = time();
        $assertion = self::build_jwt([
            'iss' => $credentials['client_email'],
            'scope' => self::oauth_scope(),
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ], $credentials['private_key']);

        $curl = new \curl(['proxy' => true]);
        $curl->setHeader(['Content-Type: application/x-www-form-urlencoded']);
        $raw = $curl->post($credentials['token_uri'], http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ], '', '&'));

        $status = (int)($curl->get_info()['http_code'] ?? 0);
        $error = (string)($curl->error ?? '');

        if ($curl->get_errno() !== 0 || $status === 0) {
            throw new \moodle_exception('vertexaiapierror', 'local_studybuddy', '', $error ?: $raw);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['error_description'] ?? $decoded['error'] ?? $raw) : $raw;
            throw new \moodle_exception('vertexaiapierror', 'local_studybuddy', '', $message);
        }

        return $decoded;
    }

    /**
     * Builds a signed RS256 JWT.
     *
     * @param array $claims JWT claims.
     * @param string $privatekey Private key.
     * @return string JWT.
     */
    private static function build_jwt(array $claims, string $privatekey): string {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];
        $unsigned = self::base64url_encode(json_encode($header, JSON_UNESCAPED_UNICODE)) . '.' .
            self::base64url_encode(json_encode($claims, JSON_UNESCAPED_UNICODE));
        $signature = '';

        if (!openssl_sign($unsigned, $signature, $privatekey, OPENSSL_ALGO_SHA256)) {
            throw new \moodle_exception('vertexai:serviceaccountsignfailed', 'local_studybuddy');
        }

        return $unsigned . '.' . self::base64url_encode($signature);
    }

    /**
     * Encodes data using base64url.
     *
     * @param string $data Data.
     * @return string Encoded data.
     */
    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
