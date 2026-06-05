<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Inject `width`, `height`, and `loading="lazy"` onto every local
 * `<img src="/uploads/…">` in rendered HTML so browsers can reserve layout
 * space the moment they parse the page (no CLS when the image streams in).
 *
 * Run at parse time so the result lives in `cache/html/<hash>.json` and
 * `getimagesize()` only runs on content edit, never per request. Hand-
 * authored `<img>` tags that already specify `width=` / `height=` win.
 */
final class ImageAnnotator
{
    private string $contentDir;

    public function __construct(string $contentDir)
    {
        $this->contentDir = rtrim($contentDir, '/');
    }

    public function annotate(string $html): string
    {
        if (!str_contains($html, '<img')) {
            return $html;
        }

        $first = true;
        return (string)preg_replace_callback(
            '#<img\b([^>]*)>#i',
            function (array $m) use (&$first): string {
                $attrs = $m[1];
                if (preg_match('/\s(width|height)\s*=/i', $attrs)) {
                    return $m[0];
                }
                if (!preg_match('/\bsrc\s*=\s*"([^"]+)"/i', $attrs, $sm)) {
                    return $m[0];
                }
                $src = $sm[1];
                if (!str_starts_with($src, '/uploads/')) {
                    return $m[0];
                }

                $diskPath = $this->resolveUploadPath($src);
                if (!$diskPath) {
                    return $m[0];
                }

                $info = @getimagesize($diskPath);
                if (!$info) {
                    return $m[0];
                }

                [$w, $h] = $info;
                $attrs  = rtrim($attrs, " /");
                $extras = sprintf(' width="%d" height="%d"', $w, $h);
                if (!$first && !preg_match('/\bloading\s*=/i', $attrs)) {
                    $extras .= ' loading="lazy" decoding="async"';
                }
                $first = false;
                return '<img' . $attrs . $extras . '>';
            },
            $html
        );
    }

    /**
     * Map a `/uploads/<rel>` URL back to its file on disk. Tries the per-post
     * location (`site/content/<rel>`) first, then the global pool
     * (`site/uploads/<rel>`).
     */
    private function resolveUploadPath(string $src): ?string
    {
        $rel = ltrim(substr($src, strlen('/uploads/')), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }

        $bases = [
            $this->contentDir,
            dirname($this->contentDir) . '/uploads',
        ];
        foreach ($bases as $base) {
            $abs      = $base . '/' . $rel;
            $real     = realpath($abs);
            $baseReal = realpath($base);
            if ($real && $baseReal && str_starts_with($real, $baseReal . '/') && is_file($real)) {
                return $real;
            }
        }
        return null;
    }
}
