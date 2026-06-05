<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Reads `site/themes/<theme>/theme.components.json` — the per-theme
 * component registry that powers the Pattern Library and (later) the
 * Inspector panel's "what did I just click on" lookup.
 *
 * Shape on disk:
 *   {
 *     "components": [
 *       {
 *         "id":          "header",            // stable slug, used by data-fp-component-id
 *         "name":        "Site header",        // display label
 *         "template":    "templates/_header.twig",  // path within the theme
 *         "description": "Top nav and logo.",  // optional
 *         "category":    "layout",             // optional — layout|navigation|content|media|forms|utility
 *         "sample":      { "title": "Demo" }   // optional — sample data for Pattern Library preview
 *       }
 *     ]
 *   }
 *
 * The file is optional — missing or malformed registries return an empty
 * list, never an exception. Theme authors opt in by shipping the JSON.
 *
 * Why a separate JSON rather than annotations in theme.json: theme.json
 * is for global settings (engine, name, version). The component list is
 * a sibling artifact that can grow long; keeping it separate avoids
 * bloating theme.json and makes it ergonomic to lint independently.
 */
class ThemeComponentRegistry
{
    public function __construct(private string $themesDir) {}

    /**
     * List components for a theme. Each entry is normalized — missing
     * optional fields are filled with defaults so the front-end can
     * trust the shape.
     *
     * @return list<array{
     *   id: string,
     *   name: string,
     *   template: string,
     *   description: string,
     *   category: string,
     *   sample: array<string, mixed>,
     *   template_exists: bool
     * }>
     */
    public function list(string $theme): array
    {
        $themeDir = $this->themesDir . '/' . $theme;
        $file     = $themeDir . '/theme.components.json';
        if (!is_file($file)) return [];

        $raw  = (string)@file_get_contents($file);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['components']) || !is_array($data['components'])) {
            return [];
        }

        $seen   = [];
        $out    = [];
        foreach ($data['components'] as $c) {
            if (!is_array($c)) continue;
            $id = (string)($c['id'] ?? '');
            // Slug-safe id and de-dupe — protects the data-fp-component-id
            // attribute from accepting whitespace / quotes / repeats.
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) continue;
            if (isset($seen[$id])) continue;
            $seen[$id] = true;

            $template = (string)($c['template'] ?? '');
            // Path must be a relative template inside the theme — block
            // `../` traversal and absolute paths.
            if ($template === '' || str_contains($template, '..') || $template[0] === '/') continue;

            $absTpl = $themeDir . '/' . $template;
            $out[] = [
                'id'              => $id,
                'name'            => (string)($c['name'] ?? $id),
                'template'        => $template,
                'description'     => (string)($c['description'] ?? ''),
                'category'        => self::normalizeCategory((string)($c['category'] ?? '')),
                'sample'          => is_array($c['sample'] ?? null) ? $c['sample'] : [],
                'template_exists' => is_file($absTpl),
            ];
        }
        return $out;
    }

    /** Find a single component by id, or null if not registered. */
    public function find(string $theme, string $id): ?array
    {
        foreach ($this->list($theme) as $c) {
            if ($c['id'] === $id) return $c;
        }
        return null;
    }

    /**
     * Add a new component to the registry. Returns the persisted entry
     * (normalized), or throws if id is taken / invalid / template missing.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function add(string $theme, array $patch): array
    {
        $clean   = $this->validate($theme, $patch, null);
        $current = $this->readRaw($theme);
        foreach ($current as $c) {
            if (is_array($c) && (string)($c['id'] ?? '') === $clean['id']) {
                throw new \RuntimeException("A component with id `{$clean['id']}` already exists.");
            }
        }
        $current[] = $clean;
        $this->writeRaw($theme, $current);
        return $this->find($theme, $clean['id']) ?? $clean;
    }

    /**
     * Update an existing component. `id` may change if `patch['id']` differs
     * from `$existingId`; the new id must still be unique.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function update(string $theme, string $existingId, array $patch): array
    {
        $clean   = $this->validate($theme, $patch, $existingId);
        $current = $this->readRaw($theme);
        $found   = false;
        foreach ($current as $i => $c) {
            if (!is_array($c)) continue;
            if ((string)($c['id'] ?? '') === $existingId) {
                $current[$i] = $clean;
                $found = true;
                continue;
            }
            // Block id collision against OTHER entries.
            if ((string)($c['id'] ?? '') === $clean['id']) {
                throw new \RuntimeException("Another component already uses id `{$clean['id']}`.");
            }
        }
        if (!$found) {
            throw new \RuntimeException("No component with id `{$existingId}` to update.");
        }
        $this->writeRaw($theme, $current);
        return $this->find($theme, $clean['id']) ?? $clean;
    }

    public function delete(string $theme, string $id): bool
    {
        $current = $this->readRaw($theme);
        $next    = array_values(array_filter(
            $current,
            fn ($c) => !is_array($c) || (string)($c['id'] ?? '') !== $id,
        ));
        if (count($next) === count($current)) return false;
        $this->writeRaw($theme, $next);
        return true;
    }

    /**
     * Validate + normalize a component payload. Throws RuntimeException
     * with user-facing message on validation failure.
     *
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private function validate(string $theme, array $patch, ?string $existingId): array
    {
        $id = strtolower(trim((string)($patch['id'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id)) {
            throw new \RuntimeException('Id must be lowercase letters, digits, dashes or underscores (no spaces).');
        }

        $template = trim((string)($patch['template'] ?? ''));
        if ($template === '' || str_contains($template, '..') || $template[0] === '/') {
            throw new \RuntimeException('Template path must be relative to the theme (e.g. `templates/_hero.twig`).');
        }
        $absTpl = $this->themesDir . '/' . $theme . '/' . $template;
        if (!is_file($absTpl)) {
            throw new \RuntimeException("Template file not found: {$template}");
        }

        return [
            'id'          => $id,
            'name'        => trim((string)($patch['name'] ?? $id)),
            'template'    => $template,
            'description' => trim((string)($patch['description'] ?? '')),
            'category'    => self::normalizeCategory((string)($patch['category'] ?? '')),
            // Sample stays optional and free-form — themes use whatever
            // variables their template expects.
            'sample'      => is_array($patch['sample'] ?? null) ? $patch['sample'] : [],
        ];
    }

    /** @return list<array<string, mixed>> raw on-disk component entries */
    private function readRaw(string $theme): array
    {
        $file = $this->themesDir . '/' . $theme . '/theme.components.json';
        if (!is_file($file)) return [];
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['components']) || !is_array($data['components'])) return [];
        return array_values($data['components']);
    }

    /** @param list<array<string, mixed>> $components */
    private function writeRaw(string $theme, array $components): void
    {
        $file = $this->themesDir . '/' . $theme . '/theme.components.json';
        $json = json_encode(
            ['components' => array_values($components)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        if ($json === false || !Fs::atomicWrite($file, (string)$json)) {
            throw new \RuntimeException('Could not write theme.components.json');
        }
    }

    private static function normalizeCategory(string $raw): string
    {
        $allowed = ['layout', 'navigation', 'content', 'media', 'forms', 'utility'];
        $raw     = strtolower(trim($raw));
        return in_array($raw, $allowed, true) ? $raw : 'utility';
    }
}
