<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

use FrontPress\MediaService;
use FrontPress\PathResolver;


class MediaController
{
    /**
     * @param string[] $pathParts
     * @param array<string, mixed> $config
     */
    public static function handle(array $pathParts, string $method, array $config): void
    {
        Router::requireAuth();

        $name  = isset($pathParts[0]) ? basename($pathParts[0]) : '';
        $paths = ServiceFactory::paths($config);
        $media = ServiceFactory::media($config);

        if ($method === 'GET' && $name === '') {
            self::list($media, $paths, $config);
            return;
        }

        Router::requireCsrf();

        if ($method === 'POST' && $name === '') {
            self::upload($media);
            return;
        }
        if ($method === 'PATCH' && $name !== '') {
            self::updateMeta($media, $name);
            return;
        }
        if ($method === 'DELETE' && $name !== '') {
            self::delete($media, $name, $config);
            return;
        }

        \json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    /** @param array<string, mixed> $config */
    private static function list(MediaService $media, PathResolver $paths, array $config): void
    {
        $files = array_map(static function ($f) {
            $f['source'] = 'media';
            return $f;
        }, $media->list());

        // Per-post images live next to the post's .md file under
        // `site/content/<pagePath>/`. The browser still fetches them at
        // `/uploads/<pagePath>/<file>` — the route in `index.php`
        // resolves that URL back to the content dir on disk.
        $pagePath = trim((string)($_GET['page_path'] ?? ''), '/');
        if ($pagePath !== '' && $paths->isValidRelPath($pagePath)) {
            $pageDir   = $config['contentDir'] . '/' . $pagePath;
            $realDir   = is_dir($pageDir) ? realpath($pageDir) : false;
            $contentBs = realpath($config['contentDir']);
            $insideContent = $realDir && $contentBs && str_starts_with($realDir . '/', $contentBs . '/');
            if ($insideContent) {
                foreach (self::pageImages($pagePath, $pageDir, $config['cacheDir']) as $row) {
                    $files[] = $row;
                }
            }
        }

        \json_response(['ok' => true, 'files' => $files]);
    }

    /**
     * List the images sitting next to a post's .md file. Cached at
     * `site/cache/page-images/<md5(pagePath)>.json`; the cache is invalidated
     * on a directory mtime mismatch (file added, removed, or replaced) so a
     * stale entry self-heals on the next request.
     *
     * @return list<array<string, mixed>>
     */
    private static function pageImages(string $pagePath, string $pageDir, string $cacheDir): array
    {
        $cacheRoot = $cacheDir . '/page-images';
        $cacheFile = $cacheRoot . '/' . md5($pagePath) . '.json';
        $dirMtime  = (int)@filemtime($pageDir);

        if (is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && ($cached['dir_mtime'] ?? 0) === $dirMtime) {
                return $cached['files'] ?? [];
            }
        }

        $rows = [];
        foreach (array_diff(scandir($pageDir) ?: [], ['.', '..']) as $file) {
            if (str_contains($file, '.thumb.') || str_ends_with($file, '.meta.json')) continue;
            if (!MediaService::isImageFile($file)) continue;
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $stem      = pathinfo($file, PATHINFO_FILENAME);
            $thumbFile = $pageDir . '/' . $stem . '.thumb.' . $ext;
            $metaFile  = $pageDir . '/' . $stem . '.meta.json';
            $meta      = is_file($metaFile) ? (json_decode((string)file_get_contents($metaFile), true) ?? []) : [];
            $rows[]    = [
                'name'      => $file,
                'url'       => '/uploads/' . $pagePath . '/' . $file,
                'thumb_url' => is_file($thumbFile) ? '/uploads/' . $pagePath . '/' . $stem . '.thumb.' . $ext : null,
                'alt'       => $meta['alt']     ?? '',
                'caption'   => $meta['caption'] ?? '',
                'source'    => 'page',
            ];
        }

        if (!is_dir($cacheRoot)) {
            @mkdir($cacheRoot, 0755, true);
        }
        @file_put_contents($cacheFile, json_encode([
            'dir_mtime' => $dirMtime,
            'files'     => $rows,
        ]));

        return $rows;
    }

    private static function upload(MediaService $media): void
    {
        $key  = array_key_first($_FILES ?? []) ?? '';
        $file = $_FILES[$key] ?? null;
        if (!$file) {
            \json_response(['ok' => false, 'error' => 'No file'], 400);
        }
        $pagePath = (string)($_POST['page_path'] ?? '');
        $result   = $media->upload($file, $pagePath);
        if (!empty($result['error'])) {
            \json_response(['ok' => false, 'error' => $result['error']], (int)($result['code'] ?? 400));
        }
        \json_response([
            'ok'   => true,
            'name' => $result['name'] ?? '',
            'url'  => $result['url']  ?? '',
            'size' => $result['size'] ?? 0,
        ]);
    }

    private static function updateMeta(MediaService $media, string $name): void
    {
        $body = Router::jsonBody();
        $ok   = $media->updateMeta($name, [
            'alt'     => (string)($body['alt']     ?? ''),
            'caption' => (string)($body['caption'] ?? ''),
        ]);
        if (!$ok) {
            \json_response(['ok' => false, 'error' => 'Could not update'], 400);
        }
        \json_response(['ok' => true]);
    }

    /** @param array<string, mixed> $config */
    private static function delete(MediaService $media, string $name, array $config): void
    {
        // When the caller knows the file is a per-post attachment, they pass
        // page_path so we delete from `site/content/<pagePath>/` rather than
        // the global uploads dir. Falls back to the global delete otherwise.
        $pagePath = trim((string)($_GET['page_path'] ?? ''), '/');
        $ok = $pagePath !== ''
            ? $media->deletePostAttachment($pagePath, $name, (string)$config['contentDir'])
            : $media->delete($name);
        if (!$ok) {
            \json_response(['ok' => false, 'error' => 'Not found'], 404);
        }
        \json_response(['ok' => true]);
    }
}
