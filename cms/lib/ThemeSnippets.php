<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Per-theme snippet store. Two files on disk per snippet:
 *   site/themes/{theme}/snippets/{id}.json   — metadata
 *   site/themes/{theme}/snippets/{id}.twig   — body (or .php)
 *
 * Why two files instead of one with front-matter: keeping the body in
 * its native extension means Monaco / IDE syntax highlighting works
 * the moment the author opens the file, and grep across the snippets
 * directory just works.
 *
 * MVP scope: list / add / delete. Editing is intentionally "delete +
 * re-add" until the feature earns its keep.
 */
class ThemeSnippets
{
    private const ALLOWED_LANGS = ['twig', 'php'];

    public function __construct(private string $themesDir) {}

    /**
     * @return list<array{
     *   id: string, name: string, category: string,
     *   description: string, language: string, content: string,
     * }>
     */
    public function list(string $theme): array
    {
        $dir = $this->dirFor($theme);
        if (!is_dir($dir)) return [];

        $out = [];
        foreach (glob($dir . '/*.json') ?: [] as $metaFile) {
            $id   = preg_replace('/\.json$/', '', basename($metaFile));
            $meta = json_decode((string)@file_get_contents($metaFile), true);
            if (!is_array($meta) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', (string)$id)) continue;

            $lang = (string)($meta['language'] ?? 'twig');
            if (!in_array($lang, self::ALLOWED_LANGS, true)) continue;

            $bodyFile = $dir . '/' . $id . '.' . $lang;
            $content  = is_file($bodyFile) ? (string)file_get_contents($bodyFile) : '';

            $out[] = [
                'id'          => (string)$id,
                'name'        => (string)($meta['name']        ?? $id),
                'category'    => self::normalizeCategory((string)($meta['category'] ?? '')),
                'description' => (string)($meta['description'] ?? ''),
                'language'    => $lang,
                'content'     => $content,
            ];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function add(string $theme, array $patch): array
    {
        $clean = $this->validate($patch);
        $dir   = $this->dirFor($theme);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException('Could not create snippets directory');
        }

        $metaFile = $dir . '/' . $clean['id'] . '.json';
        $bodyFile = $dir . '/' . $clean['id'] . '.' . $clean['language'];
        if (is_file($metaFile) || is_file($bodyFile)) {
            throw new \RuntimeException("Snippet `{$clean['id']}` already exists.");
        }

        $meta = $clean;
        $body = (string)$meta['content'];
        unset($meta['content']);

        $json = (string)json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!Fs::atomicWrite($metaFile, $json))   throw new \RuntimeException('Failed to write snippet metadata');
        if (!Fs::atomicWrite($bodyFile, $body))   throw new \RuntimeException('Failed to write snippet body');

        return $clean;
    }

    public function delete(string $theme, string $id): bool
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) return false;
        $dir = $this->dirFor($theme);
        $hit = false;
        foreach (['json', 'twig', 'php'] as $ext) {
            $f = $dir . '/' . $id . '.' . $ext;
            if (is_file($f)) { @unlink($f); $hit = true; }
        }
        return $hit;
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function validate(array $patch): array
    {
        $id = strtolower(trim((string)($patch['id'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            throw new \RuntimeException('Id must be lowercase letters, digits, dashes or underscores (no spaces).');
        }
        $language = strtolower(trim((string)($patch['language'] ?? 'twig')));
        if (!in_array($language, self::ALLOWED_LANGS, true)) {
            throw new \RuntimeException('Language must be twig or php.');
        }
        return [
            'id'          => $id,
            'name'        => trim((string)($patch['name'] ?? $id)),
            'category'    => self::normalizeCategory((string)($patch['category'] ?? '')),
            'description' => trim((string)($patch['description'] ?? '')),
            'language'    => $language,
            'content'     => (string)($patch['content'] ?? ''),
        ];
    }

    private function dirFor(string $theme): string
    {
        // Slug-safe — prevents `../` traversal even if a malicious caller
        // sneaks a path-shaped theme name past the controller's checks.
        $theme = preg_replace('/[^a-z0-9_-]/', '', strtolower($theme));
        return $this->themesDir . '/' . $theme . '/snippets';
    }

    private static function normalizeCategory(string $raw): string
    {
        $allowed = ['layout', 'navigation', 'content', 'media', 'forms', 'utility'];
        $raw     = strtolower(trim($raw));
        return in_array($raw, $allowed, true) ? $raw : 'utility';
    }
}
