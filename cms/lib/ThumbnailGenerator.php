<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * GD-backed thumbnail rasteriser. Lives outside MediaService so the upload
 * pipeline reads as a sequence of small steps rather than one 200-line method
 * that also happens to know how `imagecreatefromwebp` behaves.
 *
 * Returns a public URL (relative to `/uploads/`) on success, or null when the
 * image is already smaller than the target width or GD can't decode the
 * source. Callers treat null as "skip thumb, use the original".
 */
final class ThumbnailGenerator
{
    public const TARGET_WIDTH = 400;

    public static function generate(string $src, string $ext, string $urlPrefix): ?string
    {
        [$w, $h] = getimagesize($src) ?: [0, 0];
        if ($w <= 0 || $h <= 0 || $w <= self::TARGET_WIDTH) {
            return null;
        }

        $newH = (int)round($h * (self::TARGET_WIDTH / $w));
        $orig = match ($ext) {
            'jpg'   => @imagecreatefromjpeg($src),
            'png'   => @imagecreatefrompng($src),
            'gif'   => @imagecreatefromgif($src),
            'webp'  => @imagecreatefromwebp($src),
            default => null,
        };
        if (!$orig) {
            return null;
        }

        $thumb = imagecreatetruecolor(self::TARGET_WIDTH, $newH);
        if ($ext === 'png') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            imagefilledrectangle(
                $thumb,
                0,
                0,
                self::TARGET_WIDTH,
                $newH,
                imagecolorallocatealpha($thumb, 0, 0, 0, 127)
            );
        }
        imagecopyresampled($thumb, $orig, 0, 0, 0, 0, self::TARGET_WIDTH, $newH, $w, $h);

        $stem      = pathinfo($src, PATHINFO_FILENAME);
        $thumbFile = dirname($src) . '/' . $stem . '.thumb.' . $ext;
        $ok        = match ($ext) {
            'jpg'   => imagejpeg($thumb, $thumbFile, 82),
            'png'   => imagepng($thumb, $thumbFile, 6),
            'gif'   => imagegif($thumb, $thumbFile),
            'webp'  => imagewebp($thumb, $thumbFile, 82),
            default => false,
        };
        imagedestroy($orig);
        imagedestroy($thumb);

        return $ok ? $urlPrefix . $stem . '.thumb.' . $ext : null;
    }
}
