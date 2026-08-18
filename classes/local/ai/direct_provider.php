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

namespace mod_certificatepro\local\ai;

/**
 * Composes artwork through a directly configured model endpoint.
 *
 * For sites that have not adopted Moodle's own artificial intelligence
 * subsystem. The credential is entered by an administrator in plugin settings,
 * or inherited from a shared credentials plugin if the site has one; it is
 * never bundled with the plugin and never leaves the site except as an
 * authorisation header on the request the administrator has enabled.
 *
 * Nothing about a learner is sent. The request contains the author's written
 * description of the artwork they want and the site's brand colours, and
 * nothing else.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class direct_provider implements provider {
    /** @var string The endpoint used when the administrator has not overridden it. */
    protected const DEFAULT_ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /** @var string The API version header the endpoint expects. */
    protected const API_VERSION = '2023-06-01';

    /** @var int How long to wait for a composition before giving up. */
    protected const TIMEOUT = 60;

    /**
     * Whether a credential is configured.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->api_key() !== '';
    }

    /**
     * The identifier recorded against artwork from this route.
     *
     * @return string
     */
    public function source(): string {
        return generator::SOURCE_DIRECT;
    }

    /**
     * The configured credential.
     *
     * Prefers this plugin's own setting, and falls back to a shared credentials
     * plugin when the site uses one, so administrators enter the key once.
     *
     * @return string the key, or an empty string when none is configured
     */
    protected function api_key(): string {
        $key = trim((string) get_config('mod_certificatepro', 'aiapikey'));
        if ($key !== '') {
            return $key;
        }

        // Some sites keep one shared credential for every plugin that needs one.
        $shared = trim((string) get_config('local_aiconfig', 'apikey'));

        return $shared;
    }

    /**
     * The endpoint to call.
     *
     * @return string
     */
    protected function endpoint(): string {
        $configured = trim((string) get_config('mod_certificatepro', 'aiendpoint'));
        return $configured !== '' ? $configured : self::DEFAULT_ENDPOINT;
    }

    /**
     * The model to ask.
     *
     * @return string
     */
    protected function model(): string {
        $configured = trim((string) get_config('mod_certificatepro', 'aimodel'));
        return $configured !== '' ? $configured : 'claude-sonnet-4-5';
    }

    /**
     * Ask the endpoint to compose artwork.
     *
     * @param string $instruction the full instruction to send
     * @param \context $context the context the request is made in
     * @return string|null
     */
    public function compose(string $instruction, \context $context): ?string {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $key = $this->api_key();
        if ($key === '') {
            return null;
        }

        $payload = json_encode([
            'model' => $this->model(),
            'max_tokens' => 8000,
            'system' => 'You compose decorative vector artwork. You reply with one SVG document '
                . 'and nothing else: no prose, no explanation, no code fences.',
            'messages' => [
                ['role' => 'user', 'content' => $instruction],
            ],
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return null;
        }

        $curl = new \curl();
        $curl->setHeader([
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: ' . self::API_VERSION,
        ]);

        $response = $curl->post($this->endpoint(), $payload, [
            'CURLOPT_TIMEOUT' => self::TIMEOUT,
            'CURLOPT_CONNECTTIMEOUT' => 15,
        ]);

        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);

        if ($curl->get_errno() || $status < 200 || $status >= 300) {
            debugging('Certificate Pro artwork endpoint returned status ' . $status, DEBUG_DEVELOPER);
            return null;
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->extract_text($decoded);
    }

    /**
     * Pull the composed markup out of a response body.
     *
     * Handles both the message shape used by this endpoint and the choices
     * shape used by several compatible ones, so an administrator can point the
     * setting at whichever service they already pay for.
     *
     * @param array $decoded the decoded response body
     * @return string|null
     */
    protected function extract_text(array $decoded): ?string {
        if (!empty($decoded['content']) && is_array($decoded['content'])) {
            $parts = [];
            foreach ($decoded['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $parts[] = (string) $block['text'];
                }
            }
            if (!empty($parts)) {
                return implode('', $parts);
            }
        }

        if (!empty($decoded['choices'][0]['message']['content'])) {
            return (string) $decoded['choices'][0]['message']['content'];
        }

        if (!empty($decoded['output_text']) && is_string($decoded['output_text'])) {
            return $decoded['output_text'];
        }

        return null;
    }
}
