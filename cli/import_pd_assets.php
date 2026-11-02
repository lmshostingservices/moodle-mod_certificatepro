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

/**
 * Imports public domain ornament into the bundled asset library.
 *
 * WHY THIS EXISTS
 *
 * Moodle's plugin contribution rules require bundled media to be
 * GPL-compatible, which rules out every stock vendor whose licence forbids
 * redistribution inside a design tool. Public domain and CC0 ornament has no
 * such problem, exists already in SVG, and exists in quantity. This script is
 * how it gets from those sites into assets/ornament/ with a provenance record
 * an assessor can audit.
 *
 * WHAT IT GUARANTEES
 *
 * - It refuses to touch any host on the deny list in assets/pd_sources.json.
 * - It refuses to overwrite a hand-curated asset, and it refuses to overwrite
 *   a file whose provenance it does not recognise, on the assumption that a
 *   human put it there.
 * - Everything fetched goes through the same sanitiser as generated artwork,
 *   so nothing scriptable survives into the bundle.
 * - Re-running it is a no-op for assets that have not changed, so it is safe
 *   to run on every build.
 *
 * USAGE
 *
 *   php mod/certificatepro/cli/import_pd_assets.php --help
 *   php mod/certificatepro/cli/import_pd_assets.php --dry-run
 *   php mod/certificatepro/cli/import_pd_assets.php --source=openclipart
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

use mod_certificatepro\local\ai\svg_sanitiser;
use mod_certificatepro\local\asset\pd_library;

/** @var int The largest response we will even look at, before sanitising. */
const CERTIFICATEPRO_PD_MAX_FETCH_BYTES = 1048576;

/** @var int How long a single fetch may take, in seconds. */
const CERTIFICATEPRO_PD_DEFAULT_TIMEOUT = 30;

/** @var string The identity we present to the sites we are borrowing from. */
const CERTIFICATEPRO_PD_USER_AGENT =
    'CertificateProAssetImporter/1.0 (+https://lms-labs.com; Moodle mod_certificatepro)';

[$options, $unrecognised] = cli_get_params(
    [
        'help' => false,
        'sources' => null,
        'manifest' => null,
        'assetdir' => null,
        'source' => '',
        'role' => '',
        'id' => '',
        'limit' => 0,
        'timeout' => CERTIFICATEPRO_PD_DEFAULT_TIMEOUT,
        'delay' => 500,
        'dry-run' => false,
        'force' => false,
        'prune' => false,
    ],
    [
        'h' => 'help',
        'n' => 'dry-run',
        'f' => 'force',
    ]
);

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    cli_writeln(certificatepro_pd_help());
    exit(0);
}

$assetroot = rtrim((string)($options['assetdir'] ?? ''), '/') ?: ($CFG->dirroot . '/mod/certificatepro/assets');
$sourcespath = (string)($options['sources'] ?? '') ?: ($assetroot . '/pd_sources.json');
$manifestpath = (string)($options['manifest'] ?? '') ?: ($assetroot . '/pd_manifest.json');
$ornamentroot = $assetroot . '/ornament';

$dryrun = (bool)$options['dry-run'];
$force = (bool)$options['force'];
$timeout = max(5, (int)$options['timeout']);
$delayms = max(0, (int)$options['delay']);
$limit = max(0, (int)$options['limit']);

cli_writeln('Certificate Pro -- public domain ornament import');
cli_writeln('  sources   ' . $sourcespath);
cli_writeln('  manifest  ' . $manifestpath);
cli_writeln('  assets    ' . $ornamentroot);
if ($dryrun) {
    cli_writeln('  MODE      dry run, nothing will be written');
}
cli_writeln('');

$sources = certificatepro_pd_read_json($sourcespath);
if ($sources === null) {
    cli_error('Cannot read the source manifest at ' . $sourcespath);
}

$registry = $sources['sources'] ?? [];
$queue = $sources['queue'] ?? [];
$denied = $sources['denied_hosts'] ?? [];

if (!is_array($registry) || $registry === []) {
    cli_error('The source manifest declares no sources.');
}

$manifest = certificatepro_pd_read_json($manifestpath) ?? [];
$existing = [];
foreach (($manifest['items'] ?? []) as $item) {
    if (isset($item['id'])) {
        $existing[(string)$item['id']] = $item;
    }
}

cli_writeln(
    sprintf(
        'Queue holds %d entr%s across %d source%s.',
        count($queue),
        count($queue) === 1 ? 'y' : 'ies',
        count($registry),
        count($registry) === 1 ? '' : 's'
    )
);
cli_writeln(
    sprintf(
        'Manifest holds %d existing record%s.',
        count($existing),
        count($existing) === 1 ? '' : 's'
    )
);
cli_writeln('');

$stats = [
    'imported' => 0,
    'updated' => 0,
    'unchanged' => 0,
    'protected' => 0,
    'skipped' => 0,
    'failed' => 0,
];
$problems = [];
$seen = [];
$processed = 0;

foreach ($queue as $position => $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $id = (string)($entry['id'] ?? '');
    $sourcekey = (string)($entry['source'] ?? '');

    if ($id === '' || $sourcekey === '') {
        $stats['failed']++;
        $problems[] = sprintf('queue[%d] has no id or no source', $position);
        cli_writeln(sprintf('  FAIL  queue[%d]  missing id or source', $position));
        continue;
    }

    if (isset($seen[$id])) {
        $stats['failed']++;
        $problems[] = $id . ' appears more than once in the queue';
        cli_writeln('  FAIL  ' . $id . '  duplicate id in queue');
        continue;
    }
    $seen[$id] = true;

    // Narrowing options. These filter, they do not prune: an entry skipped by a
    // filter keeps whatever record it already has.
    if ($options['source'] !== '' && $sourcekey !== $options['source']) {
        continue;
    }
    if ($options['role'] !== '' && (string)($entry['role'] ?? '') !== $options['role']) {
        continue;
    }
    if ($options['id'] !== '' && $id !== $options['id']) {
        continue;
    }
    if ($limit > 0 && $processed >= $limit) {
        continue;
    }
    $processed++;

    $result = certificatepro_pd_process(
        $entry,
        $registry[$sourcekey] ?? null,
        $sourcekey,
        $denied,
        $existing[$id] ?? null,
        $ornamentroot,
        $timeout,
        $dryrun,
        $force
    );

    $stats[$result['status']] = ($stats[$result['status']] ?? 0) + 1;

    cli_writeln(
        sprintf(
            '  %-9s %-52s %s',
            strtoupper($result['status']),
            $id,
            $result['detail']
        )
    );

    if ($result['record'] !== null) {
        $existing[$id] = $result['record'];
    }
    if (in_array($result['status'], ['failed', 'protected'], true)) {
        $problems[] = $id . ': ' . $result['detail'];
    }

    if ($delayms > 0 && $result['fetched']) {
        usleep($delayms * 1000);
    }
}

// Dropping records for queue entries that no longer exist is destructive, so it
// is opt in and it never deletes a hand-curated record.
if ($options['prune']) {
    cli_writeln('');
    cli_writeln('Pruning records with no queue entry:');
    $queueids = [];
    foreach ($queue as $entry) {
        if (is_array($entry) && isset($entry['id'])) {
            $queueids[(string)$entry['id']] = true;
        }
    }
    foreach ($existing as $id => $record) {
        if (isset($queueids[$id])) {
            continue;
        }
        if (!empty($record['hand_curated'])) {
            cli_writeln('  keep      ' . $id . '  hand curated, never pruned');
            continue;
        }
        unset($existing[$id]);
        cli_writeln('  pruned    ' . $id);
        if (!$dryrun && !empty($record['file'])) {
            $path = $ornamentroot . '/' . ltrim((string)$record['file'], '/');
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

// ------------------------------------------------------------------ write out.

$out = [
    'manifest_version' => 1,
    'generator' => 'mod/certificatepro/cli/import_pd_assets.php',
    'generated' => date('c'),
    'roles' => pd_library::roles(),
    'items' => array_values($existing),
];
usort($out['items'], fn($a, $b) => strcmp((string)($a['id'] ?? ''), (string)($b['id'] ?? '')));

if ($dryrun) {
    cli_writeln('');
    cli_writeln('Dry run: manifest not written.');
} else {
    if (!certificatepro_pd_write_atomic(
        $manifestpath,
        json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    )) {
        cli_error('Could not write the manifest to ' . $manifestpath);
    }
    cli_writeln('');
    cli_writeln('Manifest written: ' . $manifestpath);
}

cli_writeln('');
cli_writeln(str_repeat('-', 70));
cli_writeln(
    sprintf(
        'imported %d   updated %d   unchanged %d   protected %d   skipped %d   failed %d',
        $stats['imported'],
        $stats['updated'],
        $stats['unchanged'],
        $stats['protected'],
        $stats['skipped'],
        $stats['failed']
    )
);

$bycount = [];
foreach ($out['items'] as $item) {
    $role = (string)($item['role'] ?? 'unknown');
    $bycount[$role] = ($bycount[$role] ?? 0) + 1;
}
ksort($bycount);
foreach ($bycount as $role => $count) {
    cli_writeln(sprintf('  %-10s %d', $role, $count));
}

if ($problems !== []) {
    cli_writeln('');
    cli_writeln('Needs a human:');
    foreach ($problems as $problem) {
        cli_writeln('  - ' . $problem);
    }
}
cli_writeln(str_repeat('-', 70));

exit($stats['failed'] > 0 ? 1 : 0);

// ------------------------------------------------------------------ helpers.

/**
 * The text shown for --help.
 *
 * @return string The help block.
 */
function certificatepro_pd_help(): string {
    return <<<'EOT'
Import public domain ornament into the bundled asset library.

Reads assets/pd_sources.json, fetches each queued asset over HTTP, checks that
it really is SVG, runs it through the plugin's SVG sanitiser, normalises the
viewBox, strips authoring metadata, and writes it to assets/ornament/ with a
provenance record in assets/pd_manifest.json.

The script is safe to re-run. An asset whose bytes have not changed is left
alone, and a hand-curated asset is never overwritten.

Options:
  -h, --help          Show this help and exit.
  -n, --dry-run       Fetch and validate, but write nothing.
  -f, --force         Re-fetch and rewrite even when the asset is unchanged.
                      Still refuses to touch hand-curated assets.
      --source=KEY    Only process queue entries from this source key.
      --role=ROLE     Only process entries with this role.
      --id=ID         Only process the single entry with this manifest id.
      --limit=N       Stop after N entries have been considered.
      --timeout=N     Seconds allowed for one fetch. Default 30.
      --delay=N       Milliseconds to wait between fetches. Default 500.
      --prune         Remove manifest records that no longer have a queue
                      entry, and delete their files. Hand-curated records are
                      kept regardless.
      --sources=PATH  Override the source manifest path.
      --manifest=PATH Override the output manifest path.
      --assetdir=PATH Override the assets directory root.

Examples:
  php import_pd_assets.php --dry-run
  php import_pd_assets.php --source=openclipart --limit=20
  php import_pd_assets.php --id=pdv-22864-printing-ornament-border --force

Before adding anything to the queue, read tools/README.md. It carries the
licence rules and the list of sources that must never be imported.

EOT;
}

/**
 * Read and decode a JSON file.
 *
 * @param string $path Absolute path to the file.
 * @return array|null The decoded array, or null when the file is missing or not JSON.
 */
function certificatepro_pd_read_json(string $path): ?array {
    if (!is_readable($path)) {
        return null;
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Write a file by way of a temporary file and a rename.
 *
 * A half-written manifest is worse than no manifest, and a build that is
 * interrupted mid-write should leave the previous one intact.
 *
 * @param string $path Absolute destination path.
 * @param string $contents What to write.
 * @return bool True on success.
 */
function certificatepro_pd_write_atomic(string $path, string $contents): bool {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $temp = $path . '.tmp' . getmypid();
    if (file_put_contents($temp, $contents) === false) {
        return false;
    }
    if (!rename($temp, $path)) {
        @unlink($temp);
        return false;
    }
    return true;
}

/**
 * Handle one queue entry end to end.
 *
 * @param array $entry The queue entry.
 * @param array|null $source The source definition, or null when the key is unknown.
 * @param string $sourcekey The source key as written in the queue.
 * @param array $denied The deny list from the source manifest.
 * @param array|null $previous The manifest record from a previous run, if any.
 * @param string $ornamentroot Absolute path to assets/ornament.
 * @param int $timeout Seconds allowed for the fetch.
 * @param bool $dryrun Whether to suppress writes.
 * @param bool $force Whether to rewrite unchanged assets.
 * @return array Keys: status, detail, record, fetched.
 */
function certificatepro_pd_process(array $entry, ?array $source, string $sourcekey,
        array $denied, ?array $previous, string $ornamentroot, int $timeout,
        bool $dryrun, bool $force): array {

    $id = (string)$entry['id'];

    if ($source === null) {
        return certificatepro_pd_result('failed', 'unknown source key "' . $sourcekey . '"', $previous);
    }
    if (empty($source['enabled_for_import'])) {
        return certificatepro_pd_result(
            'skipped',
            'source is not enabled for automated import; import this one by hand',
            $previous
        );
    }
    if (($source['format'] ?? 'vector') !== 'vector') {
        return certificatepro_pd_result(
            'skipped',
            'source is raster and needs tracing, which is a human step',
            $previous
        );
    }

    // A hand-curated asset is the output of somebody's afternoon. Nothing this
    // script does is worth losing it.
    if ($previous !== null && !empty($previous['hand_curated'])) {
        return certificatepro_pd_result(
            'protected',
            'hand curated, refusing to overwrite',
            $previous
        );
    }

    $role = (string)($entry['role'] ?? '');
    if (!in_array($role, pd_library::roles(), true)) {
        return certificatepro_pd_result(
            'failed',
            'role "' . $role . '" is not one of: ' . implode(', ', pd_library::roles()),
            $previous
        );
    }

    if (empty($entry['licence_verified'])) {
        return certificatepro_pd_result(
            'skipped',
            'licence not verified; open the item page, read the licence, set licence_verified',
            $previous
        );
    }

    $url = certificatepro_pd_download_url($entry, $source);
    if ($url === null) {
        return certificatepro_pd_result(
            'failed',
            'cannot build a download URL; source has no download_url_template',
            $previous
        );
    }

    $denial = certificatepro_pd_denial($url, $denied);
    if ($denial !== null) {
        return certificatepro_pd_result('failed', 'DENIED HOST -- ' . $denial, $previous);
    }

    $relative = $sourcekey . '/' . certificatepro_pd_filename($id) . '.svg';
    $target = $ornamentroot . '/' . $relative;

    // A file on disk that this manifest has never heard of was put there by a
    // person. Treat that as hand curation whether or not it was flagged.
    if ($previous === null && is_file($target)) {
        return certificatepro_pd_result(
            'protected',
            'a file already exists at ' . $relative . ' with no manifest record; refusing to overwrite',
            null
        );
    }

    if (!$force && $previous !== null && is_file($target)) {
        $ondisk = hash_file('sha256', $target);
        if ($ondisk === ($previous['sha256'] ?? null)) {
            return certificatepro_pd_result('unchanged', 'already imported and intact', $previous);
        }
    }

    $fetch = certificatepro_pd_fetch($url, $timeout);
    if ($fetch['error'] !== null) {
        return array_merge(
            certificatepro_pd_result('failed', $fetch['error'], $previous),
            ['fetched' => true]
        );
    }

    $prepared = certificatepro_pd_prepare($fetch['body'], $entry, $source, $url);
    if ($prepared['error'] !== null) {
        return array_merge(
            certificatepro_pd_result('failed', $prepared['error'], $previous),
            ['fetched' => true]
        );
    }

    $clean = $prepared['svg'];
    $sha = hash('sha256', $clean);

    if (!$force && $previous !== null && ($previous['sha256'] ?? null) === $sha && is_file($target)) {
        return array_merge(
            certificatepro_pd_result('unchanged', 'remote copy matches what we hold', $previous),
            ['fetched' => true]
        );
    }

    $record = certificatepro_pd_record(
        $entry,
        $source,
        $sourcekey,
        $url,
        $relative,
        $clean,
        $sha,
        $prepared['geometry'],
        $previous
    );

    if ($dryrun) {
        return array_merge(
            certificatepro_pd_result(
                $previous === null ? 'imported' : 'updated',
                sprintf(
                    'would write %s (%d bytes, viewBox %s)',
                    $relative,
                    strlen($clean),
                    $record['viewbox']
                ),
                $record
            ),
            ['fetched' => true]
        );
    }

    if (!certificatepro_pd_write_atomic($target, $clean)) {
        return array_merge(
            certificatepro_pd_result('failed', 'could not write ' . $target, $previous),
            ['fetched' => true]
        );
    }

    return array_merge(
        certificatepro_pd_result(
            $previous === null ? 'imported' : 'updated',
            sprintf('%s (%d bytes, viewBox %s)', $relative, strlen($clean), $record['viewbox']),
            $record
        ),
        ['fetched' => true]
    );
}

/**
 * Build a result tuple.
 *
 * @param string $status One of imported, updated, unchanged, protected, skipped, failed.
 * @param string $detail Human readable explanation.
 * @param array|null $record The manifest record to keep, or null to keep none.
 * @return array Keys: status, detail, record, fetched.
 */
function certificatepro_pd_result(string $status, string $detail, ?array $record): array {
    return [
        'status' => $status,
        'detail' => $detail,
        'record' => $record,
        'fetched' => false,
    ];
}

/**
 * Work out where an asset is downloaded from.
 *
 * @param array $entry The queue entry.
 * @param array $source The source definition.
 * @return string|null The absolute URL, or null when it cannot be built.
 */
function certificatepro_pd_download_url(array $entry, array $source): ?string {
    if (!empty($entry['download_url'])) {
        return (string)$entry['download_url'];
    }
    $template = (string)($source['download_url_template'] ?? '');
    if ($template === '') {
        return null;
    }
    return strtr(
        $template,
        [
            '{ref}' => rawurlencode((string)($entry['ref'] ?? '')),
            '{slug}' => rawurlencode((string)($entry['slug'] ?? '')),
        ]
    );
}

/**
 * The page a human should read to check an asset's licence.
 *
 * @param array $entry The queue entry.
 * @param array $source The source definition.
 * @return string The item page URL, or the source base URL when there is no template.
 */
function certificatepro_pd_item_url(array $entry, array $source): string {
    $template = (string)($source['item_page_url_template'] ?? '');
    if ($template === '') {
        return (string)($source['base_url'] ?? '');
    }
    return strtr(
        $template,
        [
            '{ref}' => (string)($entry['ref'] ?? ''),
            '{slug}' => (string)($entry['slug'] ?? ''),
        ]
    );
}

/**
 * Whether a URL points at a host we have undertaken never to import from.
 *
 * This is the last line of the licence policy, and it runs before any request
 * is made rather than after.
 *
 * @param string $url The URL about to be fetched.
 * @param array $denied The deny list, each entry with host and reason.
 * @return string|null The reason when denied, null when allowed.
 */
function certificatepro_pd_denial(string $url, array $denied): ?string {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return 'the URL has no host';
    }
    foreach ($denied as $rule) {
        $blocked = strtolower((string)($rule['host'] ?? ''));
        if ($blocked === '') {
            continue;
        }
        if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
            return (string)($rule['reason'] ?? 'listed in denied_hosts');
        }
    }
    return null;
}

/**
 * Turn a manifest id into a safe file name.
 *
 * @param string $id The manifest id.
 * @return string A lowercase name of word characters and hyphens only.
 */
function certificatepro_pd_filename(string $id): string {
    $name = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $id) ?? '');
    $name = trim($name, '-');
    return $name === '' ? 'asset' : substr($name, 0, 120);
}

/**
 * Fetch a URL and return its body.
 *
 * Uses Moodle's curl wrapper so that proxy settings and the site's blocked
 * host rules apply. Redirects are followed, but only a handful.
 *
 * @param string $url The URL to fetch.
 * @param int $timeout Seconds allowed.
 * @return array Keys: body (string), error (string|null).
 */
function certificatepro_pd_fetch(string $url, int $timeout): array {
    $curl = new \curl();
    $body = $curl->get(
        $url,
        [],
        [
            'CURLOPT_FOLLOWLOCATION' => 1,
            'CURLOPT_MAXREDIRS' => 5,
            'CURLOPT_TIMEOUT' => $timeout,
            'CURLOPT_CONNECTTIMEOUT' => min(15, $timeout),
            'CURLOPT_USERAGENT' => CERTIFICATEPRO_PD_USER_AGENT,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ]
    );

    if ($curl->get_errno()) {
        return ['body' => '', 'error' => 'fetch failed: ' . $curl->error];
    }

    $info = $curl->get_info();
    $code = (int)($info['http_code'] ?? 0);
    if ($code < 200 || $code >= 300) {
        return ['body' => '', 'error' => 'fetch failed: HTTP ' . $code];
    }

    $body = (string)$body;
    if ($body === '') {
        return ['body' => '', 'error' => 'fetch returned an empty body'];
    }
    if (strlen($body) > CERTIFICATEPRO_PD_MAX_FETCH_BYTES) {
        return ['body' => '', 'error' => sprintf(
            'response is %d bytes, over the %d byte ceiling',
            strlen($body),
            CERTIFICATEPRO_PD_MAX_FETCH_BYTES
        )];
    }

    return ['body' => $body, 'error' => null];
}

/**
 * Validate, sanitise and normalise fetched bytes into a storable SVG.
 *
 * @param string $body The raw response body.
 * @param array $entry The queue entry, used for the injected title.
 * @param array $source The source definition, used for the injected provenance.
 * @param string $url The URL the bytes came from.
 * @return array Keys: svg (string), geometry (array), error (string|null).
 */
function certificatepro_pd_prepare(string $body, array $entry, array $source, string $url): array {
    $fail = fn(string $why) => ['svg' => '', 'geometry' => [], 'error' => $why];

    // Strip a byte order mark before anything tries to parse this.
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    $head = ltrim(substr($body, 0, 512));

    if (str_starts_with($body, "PK\x03\x04")) {
        return $fail(
            'the download is a ZIP archive, not a bare SVG; extract it by hand '
                . 'and set download_url to the extracted file'
        );
    }
    if (str_starts_with($body, "\x89PNG") || str_starts_with($body, "\xFF\xD8\xFF")
            || str_starts_with($body, 'GIF8') || str_starts_with($body, '%PDF')) {
        return $fail('the download is a raster image or PDF, not SVG');
    }
    if (stripos($head, '<svg') === false && stripos($head, '<?xml') === false) {
        return $fail(
            'the download does not look like SVG; the first bytes are '
                . json_encode(substr($head, 0, 60))
        );
    }

    $clean = svg_sanitiser::clean($body);
    if ($clean === null) {
        return $fail(
            'the sanitiser rejected the document; it is malformed, oversized, '
                . 'or carries an entity declaration'
        );
    }
    if (!svg_sanitiser::is_drawable($clean)) {
        return $fail('nothing is drawn once the document is sanitised');
    }

    return certificatepro_pd_normalise($clean, $entry, $source, $url);
}

/**
 * Normalise the viewBox and replace authoring metadata with our own provenance.
 *
 * Assets arrive with a wild variety of geometry: absolute millimetre widths, no
 * viewBox at all, viewBoxes with a non-zero origin. The painter wants one
 * shape, so everything is rewritten to a viewBox rooted at the origin, with
 * width and height as plain user units.
 *
 * @param string $svg The sanitised document.
 * @param array $entry The queue entry.
 * @param array $source The source definition.
 * @param string $url The URL the document came from.
 * @return array Keys: svg (string), geometry (array), error (string|null).
 */
function certificatepro_pd_normalise(string $svg, array $entry, array $source, string $url): array {
    $fail = fn(string $why) => ['svg' => '', 'geometry' => [], 'error' => $why];

    $previous = libxml_use_internal_errors(true);
    $document = new \DOMDocument();
    $document->preserveWhiteSpace = false;
    $document->formatOutput = false;
    $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded || $document->documentElement === null) {
        return $fail('the sanitised document no longer parses');
    }

    $root = $document->documentElement;

    $box = certificatepro_pd_view_box($root);
    if ($box === null) {
        return $fail('no viewBox and no usable width and height, so the geometry is unknown');
    }
    [$minx, $miny, $width, $height] = $box;

    if ($width <= 0.0 || $height <= 0.0) {
        return $fail(sprintf('the geometry is degenerate: %g x %g', $width, $height));
    }

    // Move the drawing to the origin rather than shipping a shifted viewBox,
    // so that every asset in the library can be placed the same way. The group
    // is created in the SVG namespace on purpose: an unnamespaced wrapper makes
    // libxml stamp a redundant xmlns:default onto every child it adopts.
    if (abs($minx) > 0.0001 || abs($miny) > 0.0001) {
        $group = $document->createElementNS('http://www.w3.org/2000/svg', 'g');
        $group->setAttribute(
            'transform',
            sprintf(
                'translate(%s,%s)',
                certificatepro_pd_number(-$minx),
                certificatepro_pd_number(-$miny)
            )
        );
        while ($root->firstChild !== null) {
            $group->appendChild($root->firstChild);
        }
        $root->appendChild($group);
    }

    // Drop the source's own title and description. They are frequently the
    // uploader's filename and they are not ours to republish as description.
    foreach (['title', 'desc'] as $tag) {
        $nodes = $root->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $node = $nodes->item($i);
            if ($node !== null && $node->parentNode !== null) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    certificatepro_pd_strip_namespaces($document);

    $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
    $root->setAttribute(
        'viewBox',
        sprintf(
            '0 0 %s %s',
            certificatepro_pd_number($width),
            certificatepro_pd_number($height)
        )
    );
    $root->setAttribute('width', certificatepro_pd_number($width));
    $root->setAttribute('height', certificatepro_pd_number($height));
    $root->setAttribute('preserveAspectRatio', 'xMidYMid meet');

    // Provenance travels inside the file as well as in the manifest, because a
    // single SVG lifted out of the bundle should still say where it came from.
    $desc = $document->createElement('desc');
    $desc->appendChild(
        $document->createTextNode(
            sprintf(
                'Source: %s. Licence: %s. From: %s. Imported for mod_certificatepro.',
                (string)($source['name'] ?? 'unknown'),
                (string)($source['licence'] ?? 'unknown'),
                $url
            )
        )
    );

    $title = $document->createElement('title');
    $title->appendChild($document->createTextNode((string)($entry['title'] ?? $entry['id'])));

    if ($root->firstChild !== null) {
        $root->insertBefore($desc, $root->firstChild);
        $root->insertBefore($title, $desc);
    } else {
        $root->appendChild($title);
        $root->appendChild($desc);
    }

    $out = $document->saveXML($root);
    if ($out === false) {
        return $fail('the normalised document could not be serialised');
    }

    return [
        'svg' => $out . "\n",
        'geometry' => [
            'width' => $width,
            'height' => $height,
            'aspect' => round($width / $height, 4),
            'viewbox' => sprintf(
                '0 0 %s %s',
                certificatepro_pd_number($width),
                certificatepro_pd_number($height)
            ),
        ],
        'error' => null,
    ];
}

/**
 * Remove namespace declarations for anything that is not plain SVG.
 *
 * The sanitiser strips prefixed elements and attributes by allow list, but a
 * declaration such as xmlns:inkscape is not an ordinary attribute and survives
 * removeAttribute untouched. What is left is inert, but it is still the
 * authoring tool's fingerprint on a file we are republishing, and a bundled
 * library should not carry three editors' worth of dead namespaces.
 *
 * Safe to run only after sanitising, when no prefixed node remains to orphan.
 *
 * @param \DOMDocument $document The document to clean, modified in place.
 * @return void
 */
function certificatepro_pd_strip_namespaces(\DOMDocument $document): void {
    $xpath = new \DOMXPath($document);
    $nodes = $xpath->query('//namespace::*');
    if ($nodes === false) {
        return;
    }

    $doomed = [];
    foreach ($nodes as $node) {
        $prefix = (string)$node->localName;
        $uri = (string)$node->nodeValue;

        // The default declaration and the reserved xml prefix both stay.
        if ($prefix === '' || $prefix === 'xmlns' || $prefix === 'xml') {
            continue;
        }
        if ($uri === 'http://www.w3.org/2000/svg') {
            continue;
        }
        $owner = $node->parentNode;
        if ($owner instanceof \DOMElement) {
            $doomed[] = [$owner, $uri, $prefix];
        }
    }

    // Collected first, because removing while iterating a namespace axis is
    // not something libxml enjoys.
    foreach ($doomed as [$owner, $uri, $prefix]) {
        $owner->removeAttributeNS($uri, $prefix);
    }
}

/**
 * Work out an element's viewBox, falling back to its width and height.
 *
 * @param \DOMElement $root The svg root element.
 * @return array|null [minx, miny, width, height], or null when it cannot be determined.
 */
function certificatepro_pd_view_box(\DOMElement $root): ?array {
    $raw = trim($root->getAttribute('viewBox'));
    if ($raw !== '') {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        if (count($parts) === 4) {
            $numbers = array_map('floatval', $parts);
            if ($numbers[2] > 0.0 && $numbers[3] > 0.0) {
                return $numbers;
            }
        }
    }

    $width = certificatepro_pd_length($root->getAttribute('width'));
    $height = certificatepro_pd_length($root->getAttribute('height'));
    if ($width !== null && $height !== null && $width > 0.0 && $height > 0.0) {
        return [0.0, 0.0, $width, $height];
    }

    return null;
}

/**
 * Convert an SVG length to user units.
 *
 * Percentages are refused: a root width of 100% carries no information about
 * how big the drawing is.
 *
 * @param string $value The raw attribute value.
 * @return float|null The length in user units, or null when it is not usable.
 */
function certificatepro_pd_length(string $value): ?float {
    $value = trim($value);
    if ($value === '' || str_contains($value, '%')) {
        return null;
    }
    if (!preg_match('/^([+-]?[0-9]*\.?[0-9]+(?:[eE][+-]?[0-9]+)?)\s*([a-z]*)$/i', $value, $match)) {
        return null;
    }
    $number = (float)$match[1];

    // The CSS reference pixel is 96 to the inch, and these are the units the
    // SVG specification defines against it.
    $factors = [
        '' => 1.0,
        'px' => 1.0,
        'pt' => 96.0 / 72.0,
        'pc' => 16.0,
        'in' => 96.0,
        'mm' => 96.0 / 25.4,
        'cm' => 96.0 / 2.54,
    ];
    $unit = strtolower($match[2]);

    return array_key_exists($unit, $factors) ? $number * $factors[$unit] : null;
}

/**
 * Format a number for an attribute without exponent notation or trailing zeros.
 *
 * @param float $number The value.
 * @return string The formatted value.
 */
function certificatepro_pd_number(float $number): string {
    $formatted = rtrim(rtrim(number_format($number, 4, '.', ''), '0'), '.');
    return $formatted === '' || $formatted === '-' ? '0' : $formatted;
}

/**
 * Build the provenance record stored in the manifest.
 *
 * @param array $entry The queue entry.
 * @param array $source The source definition.
 * @param string $sourcekey The source key.
 * @param string $url The download URL used.
 * @param string $relative The path under assets/ornament.
 * @param string $svg The stored document.
 * @param string $sha The sha256 of the stored document.
 * @param array $geometry Geometry from normalisation.
 * @param array|null $previous The record from a previous run, if any.
 * @return array The manifest record.
 */
function certificatepro_pd_record(array $entry, array $source, string $sourcekey, string $url,
        string $relative, string $svg, string $sha, array $geometry, ?array $previous): array {

    $palette = $entry['palette_affinity'] ?? ['any'];
    if (!is_array($palette) || $palette === []) {
        $palette = ['any'];
    }

    return [
        'id' => (string)$entry['id'],
        'title' => (string)($entry['title'] ?? $entry['id']),
        'file' => $relative,
        'role' => (string)$entry['role'],
        'style' => (string)($entry['style'] ?? 'unclassified'),
        'orientation' => (string)($entry['orientation'] ?? 'both'),
        'palette_affinity' => array_values($palette),
        'recolourable' => (bool)($entry['recolourable'] ?? true),
        'source' => $sourcekey,
        'source_name' => (string)($source['name'] ?? $sourcekey),
        'source_url' => certificatepro_pd_item_url($entry, $source),
        'download_url' => $url,
        'author' => (string)($entry['author'] ?? ''),
        'licence' => (string)($entry['licence'] ?? $source['licence'] ?? 'unknown'),
        'licence_url' => (string)($entry['licence_url'] ?? $source['licence_url'] ?? ''),
        'attribution_required' => (bool)(
            $entry['attribution_required']
                ?? $source['attribution_required'] ?? false
        ),
        'attribution' => (string)($entry['attribution'] ?? ''),
        'licence_verified_on' => (string)($entry['verified_on'] ?? ''),
        'licence_quote' => (string)($entry['verified_quote'] ?? ''),
        'hand_curated' => (bool)($previous['hand_curated'] ?? false),
        'bytes' => strlen($svg),
        'sha256' => $sha,
        'width' => $geometry['width'] ?? null,
        'height' => $geometry['height'] ?? null,
        'aspect' => $geometry['aspect'] ?? null,
        'viewbox' => $geometry['viewbox'] ?? '',
        'imported' => date('c'),
        'notes' => (string)($entry['notes'] ?? ''),
    ];
}
