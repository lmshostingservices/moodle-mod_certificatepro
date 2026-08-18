<?php
// This file is part of the Certificate Pro plugin for Moodle - https://lms-labs.com
//
// Certificate Pro is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Certificate Pro is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Certificate Pro.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_certificatepro\local\asset;

/**
 * Thin client for the Vecteezy v2 API.
 *
 * Endpoint surface below was read from the live OpenAPI document at
 * https://www.vecteezy.com/api-docs/api/v2/swagger.json — it is the whole
 * surface, there is nothing else. Note in particular that there is
 * NO collections endpoint: member collections curated on vecteezy.com are
 * not reachable by id. See {@see curated_library} for how we bridge that.
 *
 * Auth is HTTP Bearer.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vecteezy_client {
    /** @var string Base URL for the API. */
    const BASE = 'https://api.vecteezy.com';

    /** @var string Account id, forms part of every path. */
    protected string $accountid;

    /** @var string Bearer token. */
    protected string $token;

    /** @var int Seconds before a request is abandoned. */
    protected int $timeout;

    /**
     * @param string $accountid Vecteezy account id.
     * @param string $token API key, sent as a bearer token.
     * @param int $timeout Request timeout in seconds.
     */
    public function __construct(string $accountid, string $token, int $timeout = 20) {
        $this->accountid = $accountid;
        $this->token = $token;
        $this->timeout = $timeout;
    }

    /**
     * Build a client from plugin settings.
     *
     * @return self|null Null when the plugin has not been configured with credentials.
     */
    public static function from_config(): ?self {
        $accountid = trim((string)get_config('certificatepro', 'vecteezy_account_id'));
        $token = trim((string)get_config('certificatepro', 'vecteezy_api_key'));
        if ($accountid === '' || $token === '') {
            return null;
        }
        return new self($accountid, $token);
    }

    // ------------------------------------------------------------------
    // Account
    // ------------------------------------------------------------------

    /**
     * Account quota information.
     *
     * @return array Decoded response.
     */
    public function account_info(): array {
        return $this->get('/account/info');
    }

    /**
     * Licences held by this API account for previously downloaded resources.
     *
     * We store the matching record alongside every downloaded file so that
     * provenance is provable per institution.
     *
     * @param int $page Page number.
     * @param int $perpage Results per page, max 100.
     * @return array Decoded response.
     */
    public function licenses(int $page = 1, int $perpage = 100): array {
        return $this->get('/account/licenses', ['page' => $page, 'per_page' => $perpage]);
    }

    // ------------------------------------------------------------------
    // Resources
    // ------------------------------------------------------------------

    /**
     * Search the library.
     *
     * @param string $term Search term. Required by the API.
     * @param string $contenttype One of photo, png, psd, svg, vector, video.
     * @param array $filters Any of: page, per_page, sort_by (relevance|newest),
     *      license_type (commercial|editorial), ai_generated, print_friendly,
     *      print_friendly_basic, orientation (horizontal|vertical|square|panoramic),
     *      color (hex), family_friendly, duration.
     * @return array Decoded response.
     */
    public function search(string $term, string $contenttype = 'vector', array $filters = []): array {
        $params = array_merge([
            'term' => $term,
            'content_type' => $contenttype,
            'per_page' => 40,
            'sort_by' => 'relevance',
            'license_type' => 'commercial',
            'family_friendly' => true,
        ], $filters);
        return $this->get('/resources', $params);
    }

    /**
     * Retrieve a single resource by id.
     *
     * This is the call that makes hand-curated collections work: the public
     * collection page gives us ids, and ids are all this endpoint needs.
     *
     * @param int $id Resource id.
     * @return array Decoded response.
     */
    public function resource(int $id): array {
        return $this->get('/resources/' . $id);
    }

    /**
     * Visually similar resources.
     *
     * @param int $id Resource id.
     * @param int $perpage Results per page, max 100.
     * @return array Decoded response.
     */
    public function similar(int $id, int $perpage = 24): array {
        return $this->get('/resources/' . $id . '/similar_images', ['per_page' => $perpage]);
    }

    /**
     * Resources curated for this API account.
     *
     * IMPORTANT: this is Vecteezy-side curation attached to the API account.
     * It is NOT the member "Collections" feature on vecteezy.com, and it takes
     * no collection identifier — only added_since, page and per_page. Whether
     * a member collection can be wired to this feed is an open question with
     * Vecteezy; until it is answered, use {@see curated_library}.
     *
     * @param string|null $addedsince ISO 8601 timestamp.
     * @param int $page Page number.
     * @param int $perpage Results per page, max 100.
     * @return array Decoded response.
     */
    public function curated(?string $addedsince = null, int $page = 1, int $perpage = 100): array {
        $params = ['page' => $page, 'per_page' => $perpage];
        if ($addedsince !== null) {
            $params['added_since'] = $addedsince;
        }
        return $this->get('/curated_resources', $params);
    }

    /**
     * Obtain a download URL for a resource.
     *
     * Billed by Vecteezy at the per-download rate. Every call is one licence
     * for one institution — never share the resulting file between tenants.
     *
     * @param int $id Resource id.
     * @return array Decoded response, containing the signed download URL.
     */
    public function download_url(int $id): array {
        return $this->get('/resources/' . $id . '/download');
    }

    /**
     * Status of a resized download.
     *
     * @param int $id Resource id.
     * @return array Decoded response.
     */
    public function download_status(int $id): array {
        return $this->get('/resources/' . $id . '/download_status');
    }

    /**
     * Information about a resource's source file.
     *
     * @param int $id Resource id.
     * @return array Decoded response.
     */
    public function download_info(int $id): array {
        return $this->get('/resources/' . $id . '/download_info');
    }

    // ------------------------------------------------------------------
    // Usage reporting
    // ------------------------------------------------------------------

    /**
     * Report a use of a resource.
     *
     * Fired once per institution that uses the asset, never once globally.
     *
     * @param int $id Resource id.
     * @param array $extra Any additional fields the endpoint accepts.
     * @return array Decoded response.
     */
    public function track(int $id, array $extra = []): array {
        return $this->post('/track', array_merge(['resource_id' => $id], $extra));
    }

    /**
     * Summary of tracked usage.
     *
     * @return array Decoded response.
     */
    public function track_summary(): array {
        return $this->get('/track/summary');
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * Perform a GET request.
     *
     * @param string $path Path below /v2/{account_id}.
     * @param array $params Query parameters.
     * @return array Decoded response.
     */
    protected function get(string $path, array $params = []): array {
        $url = $this->url($path);
        if ($params) {
            $url .= '?' . http_build_query($this->normalise($params));
        }
        $curl = $this->curl();
        $body = $curl->get($url);
        return $this->decode($curl, $body, 'GET ' . $path);
    }

    /**
     * Perform a POST request with a JSON body.
     *
     * @param string $path Path below /v2/{account_id}.
     * @param array $payload Body.
     * @return array Decoded response.
     */
    protected function post(string $path, array $payload): array {
        $curl = $this->curl();
        $curl->setHeader('Content-Type: application/json');
        $body = $curl->post($this->url($path), json_encode($payload));
        return $this->decode($curl, $body, 'POST ' . $path);
    }

    /**
     * Full URL for a path.
     *
     * @param string $path Path below /v2/{account_id}.
     * @return string Absolute URL.
     */
    protected function url(string $path): string {
        return self::BASE . '/v2/' . rawurlencode($this->accountid) . $path;
    }

    /**
     * A configured Moodle curl handle.
     *
     * @return \curl Handle with auth and timeouts applied.
     */
    protected function curl(): \curl {
        $curl = new \curl();
        $curl->setHeader('Authorization: Bearer ' . $this->token);
        $curl->setHeader('Accept: application/json');
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => 8,
            'CURLOPT_FOLLOWLOCATION' => 0,
        ]);
        return $curl;
    }

    /**
     * Booleans have to go over the wire as true/false, not 1/0.
     *
     * @param array $params Raw parameters.
     * @return array Parameters safe for http_build_query.
     */
    protected function normalise(array $params): array {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 'true' : 'false';
            }
        }
        return $params;
    }

    /**
     * Decode a response, converting transport and API errors into exceptions.
     *
     * @param \curl $curl The handle the request was made on.
     * @param string|bool $body Raw response body.
     * @param string $context Human-readable description of the call.
     * @return array Decoded response.
     * @throws \moodle_exception On transport failure or a non-2xx status.
     */
    protected function decode(\curl $curl, $body, string $context): array {
        if ($curl->get_errno()) {
            throw new \moodle_exception('vecteezytransport', 'certificatepro', '', $context . ': ' . $curl->error);
        }
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new \moodle_exception('vecteezyhttp', 'certificatepro', '', $context . ': HTTP ' . $status);
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new \moodle_exception('vecteezyparse', 'certificatepro', '', $context);
        }
        return $decoded;
    }
}
