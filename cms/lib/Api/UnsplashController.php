<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

use FrontPress\Config;
use FrontPress\UnsplashHttp;
use FrontPress\UnsplashImporter;

/**
 * Unsplash integration. The Access Key is per-install (Settings →
 * Integrations) and stored in site/config.json under `integrations.unsplash`.
 * Never exposed to the browser; all calls to api.unsplash.com proxy through
 * this controller so CORS + the secret-ish Access Key stay server-side.
 *
 * Endpoints:
 *   GET    /admin/api/unsplash/key      → { configured: bool, masked: '...xxxx' }
 *   PUT    /admin/api/unsplash/key      → { access_key: '...' } saves; '' or '__SAVED__' is a no-op
 *   DELETE /admin/api/unsplash/key      → clears the integration
 *   GET    /admin/api/unsplash/search   → ?q=...&page=1&per_page=24 (proxies Unsplash search)
 *   POST   /admin/api/unsplash/pick     → { photo_id, download_location, page_path?, alt? }
 *                                          downloads the image, pings Unsplash's
 *                                          mandatory download-tracking endpoint,
 *                                          saves to site/uploads/, returns
 *                                          { url, name, attribution }.
 *
 * Compliance with Unsplash API guidelines (https://help.unsplash.com/en/articles/2511245):
 *   - "Trigger a download" event is fired against `download_location` on every
 *     pick. Without it the app gets rate-limited or banned.
 *   - Attribution payload is returned to the client so the editor can drop a
 *     "Photo by X on Unsplash" caption next to the image.
 */
class UnsplashController
{
    /**
     * Default Unsplash Access Key bundled with FrontPress so the integration
     * works out of the box. Operators who want their own quota / TOS scope
     * override it via Settings → Integrations (writes to site/config.json)
     * or by defining `FPS_UNSPLASH_ACCESS_KEY` in config.php.
     *
     * ⚠ This key sits in public source. Trade-offs you've accepted by
     * shipping it this way:
     *   - Unsplash's bot scanners may flag and revoke leaked keys; if that
     *     happens, ship a new release with a fresh key (and rotate the old
     *     one on https://unsplash.com/oauth/applications).
     *   - The 50 req/hour free-tier quota is shared by every install. Apply
     *     for the production tier (5,000/hour) to make this less painful.
     *   - As the app owner you're responsible under Unsplash's TOS for what
     *     installs do with this key. Abusive usage gets the key (and your
     *     Unsplash account) suspended.
     * If any of these matter, replace this with an empty string and switch
     * back to "per-install setup" via the Settings UI.
     */
    private const DEFAULT_ACCESS_KEY = 'byCToi14SRXrvzZ7wNTdS3c2qnrjagagn93AlhRdcAA';

    /**
     * @param string[] $pathParts
     * @param array<string, mixed> $config
     */
    public static function handle(array $pathParts, string $method, array $config): void
    {
        Router::requireAuth();

        $action = $pathParts[0] ?? '';

        switch ("$method $action") {
            case 'GET key':
                self::keyStatus($config);
                return;
            case 'PUT key':
                Router::requireCsrf();
                self::saveKey($config);
                return;
            case 'DELETE key':
                Router::requireCsrf();
                self::deleteKey($config);
                return;
            case 'GET search':
                self::search($config);
                return;
            case 'POST pick':
                Router::requireCsrf();
                self::pick($config);
                return;
        }

        \json_response(['ok' => false, 'error' => 'Unknown unsplash endpoint'], 404);
    }

    /** @param array<string, mixed> $config */
    private static function keyStatus(array $config): void
    {
        $key    = self::accessKey($config);
        $source = self::accessKeySource($config);
        \json_response([
            'ok'         => true,
            'configured' => $key !== '',
            'source'     => $source,
            // Mask only the user-supplied keys. The bundled default key is
            // public in source already, but echoing it from a privileged
            // endpoint would still make it the obvious thing to grep for.
            'masked'     => ($source === 'own' || $source === 'config_php') && $key !== ''
                ? '••••' . substr($key, -4)
                : '',
        ]);
    }

    /** @param array<string, mixed> $config */
    private static function saveKey(array $config): void
    {
        $body = Router::jsonBody();
        $raw  = trim((string)($body['access_key'] ?? ''));
        if ($raw === '' || $raw === '__SAVED__') {
            \json_response(['ok' => false, 'error' => 'No key supplied'], 400);
        }
        // Unsplash Access Keys are 40+ char URL-safe strings. Loose validation
        // so we catch obvious paste mistakes without false-rejecting any
        // legitimate key format Unsplash might roll out in the future.
        if (!preg_match('/^[A-Za-z0-9_-]{20,}$/', $raw)) {
            \json_response(['ok' => false, 'error' => 'Key looks malformed'], 400);
        }

        /** @var Config $cfg */
        $cfg  = $config['config'];
        $data = $cfg->all();
        $data['integrations'] = (array)($data['integrations'] ?? []);
        $data['integrations']['unsplash'] = [
            'access_key'   => $raw,
            'connected_at' => date('c'),
        ];
        $cfg->save($data);

        \json_response(['ok' => true, 'configured' => true, 'masked' => '••••' . substr($raw, -4)]);
    }

    /** @param array<string, mixed> $config */
    private static function deleteKey(array $config): void
    {
        /** @var Config $cfg */
        $cfg  = $config['config'];
        $data = $cfg->all();
        if (isset($data['integrations']['unsplash'])) {
            unset($data['integrations']['unsplash']);
            $cfg->save($data);
        }
        \json_response(['ok' => true, 'configured' => false]);
    }

    /** @param array<string, mixed> $config */
    private static function search(array $config): void
    {
        $key = self::accessKey($config);
        if ($key === '') {
            \json_response(['ok' => false, 'error' => 'Unsplash not configured'], 400);
        }
        $q       = trim((string)($_GET['q']        ?? ''));
        $page    = max(1, min(50, (int)($_GET['page']     ?? 1)));
        $perPage = max(1, min(30, (int)($_GET['per_page'] ?? 24)));
        if ($q === '') {
            \json_response(['ok' => true, 'total' => 0, 'results' => []]);
        }

        // Optional filters. Whitelist the values rather than passing
        // raw client input — Unsplash returns 400 for unknown enums
        // and we don't want a typo on our side to swallow every search.
        $params = [
            'query'          => $q,
            'page'           => $page,
            'per_page'       => $perPage,
            'content_filter' => 'high', // exclude content flagged not-safe
        ];
        $orientation = (string)($_GET['orientation'] ?? '');
        if (in_array($orientation, ['landscape', 'portrait', 'squarish'], true)) {
            $params['orientation'] = $orientation;
        }
        $orderBy = (string)($_GET['order_by'] ?? '');
        if (in_array($orderBy, ['latest', 'relevant'], true)) {
            $params['order_by'] = $orderBy;
        }

        $url = UnsplashHttp::API_BASE . '/search/photos?' . http_build_query($params);
        [$status, $body, $curlErr] = UnsplashHttp::getJson($url, $key);
        if ($status !== 200) {
            // Surface upstream status + curl error so the browser console
            // points at the real cause (bad key → 401, rate limit → 403,
            // TLS misconfig / no curl → status 0 with a curl[n] message).
            $hint = $curlErr !== '' ? $curlErr : ('Unsplash returned ' . substr($body, 0, 200));
            \json_response([
                'ok'        => false,
                'error'     => 'Unsplash search failed',
                'status'    => $status,
                'detail'    => $hint,
            ], 502);
        }

        $json = json_decode((string)$body, true);
        if (!is_array($json)) {
            \json_response(['ok' => false, 'error' => 'Unsplash returned invalid JSON'], 502);
        }

        // Trim the response to what the picker actually needs. Avoids ferrying
        // tens of kilobytes of unused fields per query.
        $results = [];
        foreach ((array)($json['results'] ?? []) as $p) {
            $results[] = [
                'id'                => (string)($p['id'] ?? ''),
                'description'       => (string)($p['alt_description'] ?? $p['description'] ?? ''),
                'thumb'             => (string)($p['urls']['thumb'] ?? ''),
                'small'             => (string)($p['urls']['small'] ?? ''),
                'download_location' => (string)($p['links']['download_location'] ?? ''),
                'author'            => [
                    'name'     => (string)($p['user']['name'] ?? ''),
                    'username' => (string)($p['user']['username'] ?? ''),
                    'link'     => (string)($p['user']['links']['html'] ?? ''),
                ],
            ];
        }

        \json_response([
            'ok'       => true,
            'total'    => (int)($json['total'] ?? count($results)),
            'page'     => $page,
            'per_page' => $perPage,
            'results'  => $results,
        ]);
    }

    /** @param array<string, mixed> $config */
    private static function pick(array $config): void
    {
        $key = self::accessKey($config);
        if ($key === '') {
            \json_response(['ok' => false, 'error' => 'Unsplash not configured'], 400);
        }

        $body             = Router::jsonBody();
        $photoId          = trim((string)($body['photo_id']          ?? ''));
        $downloadLocation = trim((string)($body['download_location'] ?? ''));

        if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $photoId)) {
            \json_response(['ok' => false, 'error' => 'Invalid photo_id'], 400);
        }
        // download_location must point at Unsplash's API — never trust a
        // client-supplied URL to fetch arbitrary internet content.
        if (!str_starts_with($downloadLocation, UnsplashHttp::API_BASE . '/')) {
            \json_response(['ok' => false, 'error' => 'Invalid download_location'], 400);
        }

        $res = UnsplashImporter::import($key, [
            'photo_id'          => $photoId,
            'download_location' => $downloadLocation,
            'page_path'         => trim((string)($body['page_path']      ?? '')),
            'alt'               => trim((string)($body['alt']            ?? '')),
            'author_name'       => $body['author_name']     ?? '',
            'author_username'   => $body['author_username'] ?? '',
            'author_link'       => $body['author_link']     ?? '',
        ], $config);

        if (empty($res['ok'])) {
            \json_response(['ok' => false, 'error' => $res['error'] ?? 'Import failed'], (int)($res['code'] ?? 500));
        }
        unset($res['code']);
        \json_response($res);
    }

    /**
     * Resolve the Access Key, in priority order:
     *
     *   1. `site/config.json` → `integrations.unsplash.access_key`
     *      (per-install, set via Settings → Integrations UI).
     *   2. `FPS_UNSPLASH_ACCESS_KEY` constant from `config.php`
     *      (per-server fallback; `config.php` is gitignored).
     *   3. `DEFAULT_ACCESS_KEY` baked into this class
     *      (ships with FrontPress so the integration works out of the
     *      box; see the warning above for the trade-offs).
     *
     * UI-entered key wins so installs that did configure via the admin
     * don't get silently shadowed by a server-wide or bundled fallback.
     *
     * @param array<string, mixed> $config
     */
    private static function accessKey(array $config): string
    {
        /** @var Config $cfg */
        $cfg = $config['config'];
        $u   = (array)$cfg->get('integrations', []);
        $key = trim((string)($u['unsplash']['access_key'] ?? ''));
        if ($key !== '') {
            return $key;
        }
        if (defined('FPS_UNSPLASH_ACCESS_KEY')) {
            $env = trim((string)\FPS_UNSPLASH_ACCESS_KEY);
            if ($env !== '') {
                return $env;
            }
        }
        return trim(self::DEFAULT_ACCESS_KEY);
    }

    /**
     * Identify which of the three sources produced the active key.
     * Surfaced by `keyStatus()` so the Settings UI can render an
     * accurate "Connected via ..." message + show a "Use your own key"
     * affordance when the install is currently riding the bundled key.
     *
     * @param array<string, mixed> $config
     * @return 'own'|'config_php'|'default'|'none'
     */
    private static function accessKeySource(array $config): string
    {
        /** @var Config $cfg */
        $cfg = $config['config'];
        $u   = (array)$cfg->get('integrations', []);
        if (trim((string)($u['unsplash']['access_key'] ?? '')) !== '') {
            return 'own';
        }
        if (defined('FPS_UNSPLASH_ACCESS_KEY') && trim((string)\FPS_UNSPLASH_ACCESS_KEY) !== '') {
            return 'config_php';
        }
        if (trim(self::DEFAULT_ACCESS_KEY) !== '') {
            return 'default';
        }
        return 'none';
    }
}
