<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

class MarkdownEmbeds
{
    public static function expand(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $out = [];
        $fence = null;

        foreach ($lines as $line) {
            $marker = self::fenceMarker($line);
            if ($marker !== null) {
                $fence = $fence === null
                    ? $marker
                    : (self::closesFence($marker, $fence) ? null : $fence);
                $out[] = $line;
                continue;
            }

            $id = $fence === null && !self::isIndentedCode($line)
                ? self::youtubeId(trim($line))
                : null;
            $out[] = $id ? self::youtubeEmbed($id) : $line;
        }

        return implode("\n", $out);
    }

    /**
     * @return array{char:string,length:int}|null
     */
    private static function fenceMarker(string $line): ?array
    {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $m)) {
            return ['char' => $m[1][0], 'length' => strlen($m[1])];
        }
        return null;
    }

    /**
     * @param array{char:string,length:int} $marker
     * @param array{char:string,length:int} $fence
     */
    private static function closesFence(array $marker, array $fence): bool
    {
        return $marker['char'] === $fence['char'] && $marker['length'] >= $fence['length'];
    }

    private static function isIndentedCode(string $line): bool
    {
        return (bool)preg_match('/^(?: {4}|\t)/', $line);
    }

    private static function youtubeId(string $value): ?string
    {
        if ($value === '' || !preg_match('#^https?://#i', $value)) return null;

        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['host'])) return null;

        $host = strtolower((string)$parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        $path = (string)($parts['path'] ?? '');

        if ($host === 'youtu.be') {
            return self::validId(explode('/', trim($path, '/'))[0] ?? '');
        }

        if (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true)) {
            parse_str((string)($parts['query'] ?? ''), $query);
            $id = isset($query['v']) ? self::validId((string)$query['v']) : null;
            if ($id !== null) return $id;
            if (preg_match('#^/(?:embed|shorts|v|live)/([A-Za-z0-9_-]{11})#', $path, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private static function validId(string $id): ?string
    {
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }

    private static function youtubeEmbed(string $id): string
    {
        $src = 'https://www.youtube-nocookie.com/embed/' . $id;
        return '<figure class="fp-embed fp-embed-youtube" style="position:relative;padding-top:56.25%;margin:1rem 0;">' . "\n"
            . '  <iframe src="' . $src . '" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen loading="lazy" style="position:absolute;inset:0;width:100%;height:100%;border:0;"></iframe>' . "\n"
            . '</figure>';
    }
}
