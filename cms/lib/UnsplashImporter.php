<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Downloads a single Unsplash photo into the install's uploads tree.
 *
 * Lives outside the controller so the API layer stays thin (just routing
 * + validation + JSON shaping). The pipeline:
 *
 *   1. POST the photo's `download_location` (Unsplash's mandatory
 *      download-tracking endpoint) to get the actual binary URL.
 *   2. Fetch the binary, sniffing Content-Type to pick a file extension.
 *   3. Enforce the install's `uploads.max_mb` limit (same as direct
 *      uploads — no special privilege for Unsplash imports).
 *   4. Write the bytes into `site/uploads/` (or the per-post folder when
 *      `pagePath` is set).
 *   5. Generate a thumbnail (raster only — always true here).
 *   6. Write a sidecar `.meta.json` recording the photographer + the
 *      `source: "unsplash"` flag so themes can render credits.
 *
 * Returns a `['ok' => bool, ...]` result mirroring MediaService::upload
 * so the controller can json_response it verbatim. Errors carry an
 * `error` string + optional `code` (HTTP status) so the controller can
 * forward the right HTTP code.
 */
class UnsplashImporter
{
    /** Accept-list for the binary host. Anything else is a 502. */
    private const BINARY_HOST_RE = '#^https://(images\.unsplash\.com/|[a-z0-9.-]*unsplash\.com/)#i';

    /**
     * @param  array<string,mixed> $input  validated request body
     * @param  array<string,mixed> $config app config
     * @return array<string,mixed>
     */
    public static function import(string $accessKey, array $input, array $config): array
    {
        $downloadLocation = (string)$input['download_location'];
        $pagePath         = (string)($input['page_path'] ?? '');
        $altText          = (string)($input['alt'] ?? '');
        $photoId          = (string)$input['photo_id'];

        // 1) Fire the download-tracking ping and read the binary URL.
        [$status, $resp, $curlErr] = UnsplashHttp::getJson($downloadLocation, $accessKey);
        if ($status !== 200) {
            return [
                'ok'     => false,
                'error'  => 'Unsplash download trigger failed',
                'code'   => 502,
                'status' => $status,
                'detail' => $curlErr !== '' ? $curlErr : substr((string)$resp, 0, 200),
            ];
        }
        $dl = json_decode((string)$resp, true);
        $binaryUrl = is_array($dl) ? (string)($dl['url'] ?? '') : '';
        if (!preg_match(self::BINARY_HOST_RE, $binaryUrl)) {
            return ['ok' => false, 'error' => 'Unexpected binary URL', 'code' => 502];
        }

        // 2) Fetch the actual bytes.
        [$status, $bytes, $headers] = UnsplashHttp::getBinary($binaryUrl);
        if ($status !== 200 || !is_string($bytes) || $bytes === '') {
            return ['ok' => false, 'error' => 'Image fetch failed', 'code' => 502, 'status' => $status];
        }
        $ext = self::pickExt($headers['content-type'] ?? 'image/jpeg');

        // 3) Size limit (parity with MediaService::upload).
        $cfg     = (array)$config['config']->all();
        $maxMb   = max(1, (int)($cfg['uploads']['max_mb'] ?? 5));
        if (strlen($bytes) > $maxMb * 1024 * 1024) {
            return ['ok' => false, 'error' => "Image exceeds {$maxMb} MB install limit", 'code' => 400];
        }

        // 4) Pick a destination directory (global vs per-post).
        $paths = Api\ServiceFactory::paths($config);
        ['dir' => $subDir, 'prefix' => $urlPrefix] = $paths->uploadsSubDir(
            $pagePath !== '' && $paths->isValidRelPath($pagePath) ? $pagePath : ''
        );
        if (!is_dir($subDir)) {
            mkdir($subDir, 0755, true);
        }
        $name   = bin2hex(random_bytes(12)) . '.' . $ext;
        $target = $subDir . '/' . $name;
        if (file_put_contents($target, $bytes) === false) {
            return ['ok' => false, 'error' => 'Could not save image', 'code' => 500];
        }

        // 5) Thumbnail.
        $thumbUrl = ThumbnailGenerator::generate($target, $ext, $urlPrefix);

        // 6) Sidecar meta with attribution.
        $author = [
            'name'     => trim((string)($input['author_name']     ?? '')),
            'username' => trim((string)($input['author_username'] ?? '')),
            'link'     => trim((string)($input['author_link']     ?? '')),
        ];
        $stem = pathinfo($name, PATHINFO_FILENAME);
        Fs::atomicWrite($subDir . '/' . $stem . '.meta.json', json_encode([
            'alt'         => $altText !== '' ? $altText : ('Photo by ' . ($author['name'] ?: 'Unsplash')),
            'caption'     => '',
            'attached_to' => [],
            'uploaded_at' => date('c'),
            'source'      => 'unsplash',
            'unsplash'    => ['photo_id' => $photoId, 'author' => $author],
        ], JSON_UNESCAPED_UNICODE));

        return [
            'ok'        => true,
            'url'       => $urlPrefix . $name,
            'name'      => $name,
            'size'      => strlen($bytes),
            'thumb_url' => $thumbUrl,
            'attribution' => [
                'author_name'  => $author['name'],
                'author_link'  => $author['link'] !== ''
                    ? $author['link'] . '?utm_source=frontpress-studio&utm_medium=referral'
                    : '',
                'unsplash_link' => 'https://unsplash.com/?utm_source=frontpress-studio&utm_medium=referral',
            ],
        ];
    }

    private static function pickExt(string $contentType): string
    {
        $ct = strtolower($contentType);
        return match (true) {
            str_starts_with($ct, 'image/png')  => 'png',
            str_starts_with($ct, 'image/webp') => 'webp',
            str_starts_with($ct, 'image/gif')  => 'gif',
            default                            => 'jpg',
        };
    }
}
