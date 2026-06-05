<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

use FrontPress\Updater;

class UpdateController
{
    /**
     * @param list<string>             $rest
     * @param array<string, mixed>     $config
     */
    public static function handle(string $method, array $config, array $rest = []): void
    {
        Router::requireAuth();
        $updater = new Updater($config['appRoot']);

        if ($method === 'GET') {
            $latest = $updater->checkLatest();
            \json_response([
                'ok'                  => true,
                'current'             => $updater->currentVersion(),
                'latest'              => $latest,
                'has_update'          => $latest ? version_compare($latest['version'], $updater->currentVersion(), '>') : false,
                'repo_configured'     => !str_starts_with($updater->repo(), 'your-'),
                'pending_migrations'  => array_map('basename', $updater->pendingMigrations()),
            ]);
        }

        Router::requireCsrf();

        if ($method !== 'POST') {
            \json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
        }

        // /admin/api/update/migrate — explicit, separate trigger for migrations.
        if (($rest[0] ?? '') === 'migrate') {
            $updater->runMigrations();
            \json_response(['ok' => true]);
        }

        // /admin/api/update/check — drop the 6h disk cache and re-fetch from
        // GitHub. The sidebar banner reads from that cache via /me, so this
        // is the "force check" button on Settings → Site for users who don't
        // want to wait out the TTL after a release just shipped.
        if (($rest[0] ?? '') === 'check') {
            $updater->clearUpdateCheckCache();
            $latest  = $updater->checkLatest();
            $current = $updater->currentVersion();
            \json_response([
                'ok'        => true,
                'current'   => $current,
                'latest'    => $latest['version'] ?? null,
                'available' => $latest
                    ? version_compare($latest['version'], $current, '>')
                    : false,
            ]);
        }

        // /admin/api/update — apply latest release. The ZIP URL is taken from
        // GitHub's release metadata, not from the client, and is host-checked
        // again inside Updater::apply().
        $latest = $updater->checkLatest();
        if (!$latest || empty($latest['zip_url'])) {
            \json_response(['ok' => false, 'error' => 'No release available'], 400);
        }
        $zipUrl = $latest['zip_url'];
        if (!Updater::isAllowedZipUrl($zipUrl)) {
            \json_response(['ok' => false, 'error' => 'Release URL host not allowed'], 400);
        }
        $result = $updater->apply($zipUrl, $config['appRoot'] . '/site/backups');
        if (!empty($result['ok'])) {
            \json_response([
                'ok'                  => true,
                'version'             => $result['version'] ?? '',
                'pending_migrations'  => $result['pending_migrations'] ?? [],
            ]);
        }
        \json_response(['ok' => false, 'error' => $result['error'] ?? 'Update failed'], 500);
    }
}
