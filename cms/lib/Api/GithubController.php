<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

use FrontPress\GithubClient;
use FrontPress\GithubPusher;
use FrontPress\GithubSources;

/**
 * GitHub JSON API — status, repo list & selection, push trigger,
 * disconnect. The browser-facing OAuth flow (connect/receive redirects)
 * lives in {@see GithubOAuth} so each file stays under the size budget.
 */
class GithubController
{

    /**
     * @param string[]             $rest
     * @param array<string, mixed> $config
     */
    public static function handle(array $rest, string $method, array $config): void
    {
        $route = $method . ' ' . ($rest[0] ?? '');
        // GET routes need auth only; POST also needs CSRF (state-changing).
        Router::requireAuth();
        if ($method === 'POST') Router::requireCsrf();

        switch ($route) {
            case 'GET status':        self::status($config);       return;
            case 'POST disconnect':   self::disconnect($config);   return;
            case 'GET repos':         self::repos($config);        return;
            case 'POST select-repo':  self::selectRepo($config);   return;
            case 'POST push':         self::push($config);         return;
            case 'GET sources':       self::sources($config);      return;
            case 'POST save-sources': self::saveSources($config);  return;
            case 'GET push-status':
                session_write_close(); // release lock so we don't block on push().
                self::pushStatus($config);
                return;
        }

        \json_response(['ok' => false, 'error' => 'Unknown github endpoint'], 404);
    }

    /** @param array<string, mixed> $config */
    private static function pushStatus(array $config): void
    {
        $file = (string)$config['cacheDir'] . '/github-push-status.json';
        if (!is_file($file)) {
            \json_response(['ok' => true, 'active' => false]);
        }
        $raw  = (string)@file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            \json_response(['ok' => true, 'active' => false]);
        }
        \json_response(['ok' => true, 'active' => true] + $data);
    }

    /** @param array<string, mixed> $config */
    private static function sources(array $config): void
    {
        /** @var \FrontPress\Config $cfg */
        $cfg    = $config['config'];
        $gh     = (array)$cfg->get('github', []);
        $picked = (array)($gh['sources'] ?? []);
        \json_response([
            'ok'      => true,
            'sources' => GithubSources::listForUi((string)$config['appRoot'], $picked),
        ]);
    }

    /** @param array<string, mixed> $config */
    private static function saveSources(array $config): void
    {
        $body = Router::jsonBody();
        $raw  = $body['sources'] ?? [];
        if (!is_array($raw)) {
            \json_response(['ok' => false, 'error' => 'sources must be an array'], 400);
        }
        $clean = GithubSources::sanitizeKeys($raw);

        /** @var \FrontPress\Config $cfg */
        $cfg  = $config['config'];
        $data = $cfg->all();
        $data['github']            = (array)($data['github'] ?? []);
        $data['github']['sources'] = $clean;
        $cfg->save($data);

        \json_response(['ok' => true, 'sources' => $clean]);
    }

    /** @param array<string, mixed> $config */
    private static function status(array $config): void
    {
        /** @var \FrontPress\Config $cfg */
        $cfg = $config['config'];
        $gh  = (array)$cfg->get('github', []);
        \json_response([
            'ok'              => true,
            'connected'       => !empty($gh['token']),
            'user'            => isset($gh['user'])             ? (string)$gh['user']             : null,
            'repo'            => isset($gh['repo'])             ? (string)$gh['repo']             : null,
            'branch'          => isset($gh['branch'])           ? (string)$gh['branch']           : null,
            'last_pushed_at'  => isset($gh['last_pushed_at'])   ? (string)$gh['last_pushed_at']   : null,
            'last_pushed_sha' => isset($gh['last_pushed_commit']) ? (string)$gh['last_pushed_commit'] : null,
        ]);
    }

    /** @param array<string, mixed> $config */
    private static function disconnect(array $config): void
    {
        /** @var \FrontPress\Config $cfg */
        $cfg  = $config['config'];
        $data = $cfg->all();
        $prev = (array)($data['github'] ?? []);
        unset($data['github']);
        $cfg->save($data);

        ServiceFactory::audit($config)->record('github.disconnect', (string)($prev['user'] ?? ''), []);

        // We deliberately don't call GitHub's revoke-grant API — the user
        // can do that from github.com/settings/applications if they want
        // the OAuth grant fully removed. Disconnecting locally just drops
        // our copy of the token so further pushes fail closed.
        \json_response(['ok' => true]);
    }

    /**
     * GET /admin/api/github/repos — trimmed /user/repos, newest first.
     * @param array<string, mixed> $config
     */
    private static function repos(array $config): void
    {
        $token  = self::tokenOrAbort($config);
        $client = new GithubClient($token);
        $repos  = [];
        // Paginate until short page or safety cap (1000 repos).
        for ($page = 1; $page <= 10; $page++) {
            $res = $client->get('/user/repos?per_page=100&sort=updated'
                . '&affiliation=owner,collaborator,organization_member'
                . '&page=' . $page);
            if (!$res['ok']) {
                \json_response([
                    'ok'    => false,
                    'error' => 'GitHub call failed (' . ($res['reason'] ?? 'unknown') . ')',
                ], 502);
            }
            $raw = is_array($res['data']) ? $res['data'] : [];
            foreach ($raw as $r) {
                if (!is_array($r) || empty($r['full_name'])) continue;
                $repos[] = [
                    'full_name'      => (string)$r['full_name'],
                    'default_branch' => (string)($r['default_branch'] ?? 'main'),
                    'private'        => (bool)($r['private'] ?? false),
                ];
            }
            if (count($raw) < 100) break; // last page reached
        }
        \json_response(['ok' => true, 'repos' => $repos]);
    }

    /** POST /admin/api/github/select-repo — `{full_name, branch?}` → config. */
    private static function selectRepo(array $config): void
    {
        $body   = Router::jsonBody();
        $full   = trim((string)($body['full_name'] ?? ''));
        $branch = trim((string)($body['branch'] ?? ''));
        if (!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $full)) {
            \json_response(['ok' => false, 'error' => 'Invalid repo name'], 400);
        }

        /** @var \FrontPress\Config $cfg */
        $cfg  = $config['config'];
        $data = $cfg->all();
        $gh   = (array)($data['github'] ?? []);
        if (empty($gh['token'])) {
            \json_response(['ok' => false, 'error' => 'Not connected'], 400);
        }
        $gh['repo']   = $full;
        $gh['branch'] = $branch !== '' ? $branch : 'main';
        $data['github'] = $gh;
        $cfg->save($data);

        ServiceFactory::audit($config)->record('github.select_repo', $full, ['branch' => $gh['branch']]);

        \json_response(['ok' => true, 'repo' => $full, 'branch' => $gh['branch']]);
    }

    /** POST /admin/api/github/push — `{message?}` → atomic commit. */
    private static function push(array $config): void
    {
        $token = self::tokenOrAbort($config);
        /** @var \FrontPress\Config $cfg */
        $cfg = $config['config'];
        $gh  = (array)$cfg->get('github', []);
        $repo   = (string)($gh['repo']   ?? '');
        $branch = (string)($gh['branch'] ?? 'main');
        if ($repo === '' || !str_contains($repo, '/')) {
            \json_response(['ok' => false, 'error' => 'No repo selected'], 400);
        }
        [$owner, $name] = explode('/', $repo, 2);

        $picked = (array)($gh['sources'] ?? []);
        if (empty($picked)) {
            \json_response(['ok' => false, 'error' => 'No sources selected — tick at least one folder to push.'], 400);
        }

        $files = GithubSources::gather((string)$config['appRoot'], $picked);
        if (empty($files)) {
            \json_response(['ok' => false, 'error' => 'Selected sources are empty (nothing to push).'], 400);
        }

        $body    = Router::jsonBody();
        $message = trim((string)($body['message'] ?? ''));
        if ($message === '') {
            $message = 'Sync from FrontPress Studio at ' . date(\DATE_ATOM);
        }

        // Release session lock so /push-status polls don't queue behind us.
        $statusFile = (string)$config['cacheDir'] . '/github-push-status.json';
        @unlink($statusFile);
        session_write_close();

        $pusher = new GithubPusher(new GithubClient($token), $owner, $name, $branch);
        $res    = $pusher->push($files, $message, function (int $done, int $total, string $current) use ($statusFile): void {
            @file_put_contents($statusFile, (string)json_encode([
                'done'    => $done,
                'total'   => $total,
                'current' => $current,
            ]));
        });

        @unlink($statusFile);

        if (!$res['ok']) {
            ServiceFactory::audit($config)->record('github.push', $repo, ['ok' => false, 'error' => $res['error'] ?? '']);
            $status = (int)($res['status'] ?? 502);
            \json_response(['ok' => false, 'error' => $res['error'] ?? 'Push failed'], $status);
        }

        $data = $cfg->all();
        $data['github']['last_pushed_at']     = date(\DATE_ATOM);
        $data['github']['last_pushed_commit'] = $res['commit'];
        $cfg->save($data);

        ServiceFactory::audit($config)->record('github.push', $repo, [
            'ok'      => true,
            'commit'  => $res['commit'],
            'files'   => $res['files'],
            'sources' => $picked,
        ]);

        \json_response($res);
    }
    /** @param array<string, mixed> $config */
    private static function tokenOrAbort(array $config): string
    {
        /** @var \FrontPress\Config $cfg */
        $cfg   = $config['config'];
        $gh    = (array)$cfg->get('github', []);
        $token = (string)($gh['token'] ?? '');
        if ($token === '') {
            \json_response(['ok' => false, 'error' => 'Not connected'], 400);
        }
        return $token;
    }
}
