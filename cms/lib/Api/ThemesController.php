<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

use FrontPress\ThemeService;

class ThemesController
{
    /**
     * @param string[] $pathParts
     * @param array<string, mixed> $config
     */
    public static function handle(array $pathParts, string $method, array $config): void
    {
        Router::requireAuth();

        $themes = ServiceFactory::themes($config);
        $action = $pathParts[0] ?? '';

        if ($method === 'GET' && $action === '') {
            self::list($themes, $config);
            return;
        }

        if ($method === 'GET' && $action === 'templates') {
            \json_response(['ok' => true, 'templates' => $themes->listTemplates()]);
        }
        if ($method === 'GET' && $action === 'files') {
            $theme = isset($_GET['theme']) ? (string)$_GET['theme'] : null;
            self::themeFileResponse(fn () => ServiceFactory::themeFiles($config)->list($theme));
        }
        if ($method === 'GET' && $action === 'file') {
            $theme = isset($_GET['theme']) ? (string)$_GET['theme'] : null;
            $path = (string)($_GET['path'] ?? '');
            self::themeFileResponse(fn () => ServiceFactory::themeFiles($config)->read($theme, $path));
        }

        if ($method === 'GET' && $action === 'snippets') {
            $theme = isset($_GET['theme']) && $_GET['theme'] !== ''
                ? (string)$_GET['theme']
                : $themes->active();
            $store = new \FrontPress\ThemeSnippets((string)$config['themesDir']);
            \json_response(['ok' => true, 'theme' => $theme, 'snippets' => $store->list($theme)]);
        }

        if ($method === 'GET' && $action === 'components') {
            // Falls back to the currently-active theme so the front-end
            // can omit the param when working on the live site.
            $theme = isset($_GET['theme']) && $_GET['theme'] !== ''
                ? (string)$_GET['theme']
                : $themes->active();
            $registry = new \FrontPress\ThemeComponentRegistry((string)$config['themesDir']);
            \json_response([
                'ok'         => true,
                'theme'      => $theme,
                'components' => $registry->list($theme),
            ]);
        }

        Router::requireCsrf();

        if ($method !== 'POST') {
            \json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
        }

        // Multipart actions: don't try to JSON-decode the body — the file
        // lives in $_FILES and the slug override (if any) in $_POST.
        if ($action === 'upload') {
            self::upload($themes, $config);
            return;
        }

        $body = Router::jsonBody();

        if ($action === 'download') {
            $slug = preg_replace('/[^a-z0-9_-]/', '', (string)($body['slug'] ?? ''));
            self::download($slug, $config);
            return;
        }

        if ($action === 'activate') {
            $slug   = preg_replace('/[^a-z0-9_-]/', '', (string)($body['slug'] ?? ''));
            $result = $themes->activate($slug);
            if (!empty($result['ok'])) {
                self::clearCache($config);
                \json_response(['ok' => true]);
            }
            \json_response(['ok' => false, 'error' => $result['error'] ?? 'Failed'], 400);
        }
        if ($action === 'install') {
            $starter   = preg_replace('/[^a-z0-9_-]/', '', (string)($body['starter'] ?? ''));
            $themeSlug = preg_replace('/[^a-z0-9_-]/', '', (string)($body['theme_slug'] ?? $starter));
            $result    = $themes->installFromStarter($starter, $themeSlug, $config['cmsRoot'] . '/starters');
            if (!empty($result['ok'])) {
                \json_response(['ok' => true]);
            }
            \json_response(['ok' => false, 'error' => $result['error'] ?? 'Failed'], 400);
        }
        if ($action === 'delete') {
            $slug   = preg_replace('/[^a-z0-9_-]/', '', (string)($body['slug'] ?? ''));
            $result = $themes->delete($slug);
            if (!empty($result['ok'])) {
                self::clearCache($config);
                \json_response(['ok' => true]);
            }
            \json_response(['ok' => false, 'error' => $result['error'] ?? 'Failed'], 400);
        }
        if ($action === 'replace') {
            $starter   = preg_replace('/[^a-z0-9_-]/', '', (string)($body['starter'] ?? ''));
            $themeSlug = preg_replace('/[^a-z0-9_-]/', '', (string)($body['theme_slug'] ?? $themes->active()));
            $result    = $themes->replaceTemplates($starter, $themeSlug, $config['cmsRoot'] . '/starters');
            if (!empty($result['ok'])) {
                self::clearCache($config);
                \json_response(['ok' => true]);
            }
            \json_response(['ok' => false, 'error' => $result['error'] ?? 'Failed'], 400);
        }
        if ($action === 'file') {
            $theme = isset($body['theme']) ? (string)$body['theme'] : null;
            $path = (string)($body['path'] ?? '');
            $content = (string)($body['content'] ?? '');
            try {
                $result = ServiceFactory::themeFiles($config)->write($theme, $path, $content);
            } catch (\RuntimeException $e) {
                \json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            if (!empty($result['ok'])) {
                self::clearCache($config);
                \json_response($result);
            }
            \json_response(['ok' => false, 'error' => $result['error'] ?? 'Failed'], 400);
        }
        if (in_array($action, ['file-create', 'file-delete', 'file-rename', 'file-duplicate'], true)) {
            self::themeFileMutation($config, fn () => self::dispatchFileMutation(
                ServiceFactory::themeFiles($config),
                $action,
                $body
            ));
            return;
        }
        if (in_array($action, ['components-add', 'components-update', 'components-delete'], true)) {
            self::handleComponentMutation($config, $action, $body, $themes);
            return;
        }

        if (in_array($action, ['snippets-add', 'snippets-delete'], true)) {
            $theme = isset($body['theme']) && $body['theme'] !== ''
                ? (string)$body['theme']
                : $themes->active();
            $store = new \FrontPress\ThemeSnippets((string)$config['themesDir']);
            try {
                if ($action === 'snippets-add') {
                    $patch = is_array($body['snippet'] ?? null) ? $body['snippet'] : [];
                    \json_response(['ok' => true, 'snippet' => $store->add($theme, $patch)]);
                }
                \json_response(['ok' => $store->delete($theme, (string)($body['id'] ?? ''))]);
            } catch (\RuntimeException $e) {
                \json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
        }

        if ($action === 'create-template') {
            $theme   = isset($body['theme']) ? (string)$body['theme'] : null;
            $slug    = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($body['slug'] ?? '')));
            $ext     = ((string)($body['ext'] ?? 'twig')) === 'php' ? 'php' : 'twig';
            $kind    = ((string)($body['kind'] ?? 'template')) === 'partial' ? 'partial' : 'template';
            $content = (string)($body['content'] ?? '');
            if ($slug === '' || str_starts_with($slug, '_')) {
                \json_response(['ok' => false, 'error' => 'Invalid slug'], 400);
            }
            // Partials live alongside templates with a leading underscore
            // — the `partial()` helper resolves `partial('header')` to
            // `_header.twig`.
            $filename = ($kind === 'partial' ? '_' : '') . $slug . '.' . $ext;
            $path     = 'templates/' . $filename;
            try {
                $result = ServiceFactory::themeFiles($config)->create($theme, $path, $content);
            } catch (\RuntimeException $e) {
                \json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            if (!empty($result['ok'])) {
                self::clearCache($config);
                \json_response($result);
            }
            \json_response(['ok' => false, 'error' => $result['error'] ?? 'Failed'], 400);
        }

        \json_response(['ok' => false, 'error' => 'Unknown theme action'], 404);
    }

    /** Dispatch `components-{add,update,delete}`. Body: `{theme, component, id?}`. */
    private static function handleComponentMutation(array $config, string $action, array $body, $themes): void
    {
        $theme = isset($body['theme']) && $body['theme'] !== '' ? (string)$body['theme'] : $themes->active();
        $reg   = new \FrontPress\ThemeComponentRegistry((string)$config['themesDir']);
        $patch = is_array($body['component'] ?? null) ? $body['component'] : [];
        $id    = (string)($body['id'] ?? '');
        try {
            $res = match ($action) {
                'components-add'    => ['ok' => true, 'component' => $reg->add($theme, $patch)],
                'components-update' => ['ok' => true, 'component' => $reg->update($theme, $id, $patch)],
                'components-delete' => ['ok' => $reg->delete($theme, $id)],
            };
            \json_response($res);
        } catch (\RuntimeException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** @param array<string, mixed> $config */
    private static function download(string $slug, array $config): void
    {
        if ($slug === '') {
            \json_response(['ok' => false, 'error' => 'Invalid theme slug'], 400);
        }
        $themesDir = $config['appRoot'] . '/site/themes';
        $themeDir  = $themesDir . '/' . $slug;
        $base      = realpath($themesDir);
        $real      = realpath($themeDir);
        if (!$real || !$base || !str_starts_with($real, $base . '/')) {
            \json_response(['ok' => false, 'error' => 'Theme not found'], 404);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mdtheme_');
        if ($tmp === false || !ServiceFactory::themeArchiver()->writeZip($real, $slug, $tmp)) {
            if ($tmp) {
                @unlink($tmp);
            }
            \json_response(['ok' => false, 'error' => 'Failed to write theme archive'], 500);
        }

        $stamp = date('Y-m-d');
        // Router set Content-Type: application/json; strip it before
        // streaming binary so browsers don't mis-sniff the response.
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="theme-' . $slug . '-' . $stamp . '.zip"');
        header('Content-Length: ' . (string)filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /** @param array<string, mixed> $config */
    private static function upload(ThemeService $themes, array $config): void
    {
        $file = $_FILES['theme'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            \json_response(['ok' => false, 'error' => 'No file uploaded'], 400);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            \json_response(['ok' => false, 'error' => 'Invalid upload'], 400);
        }

        $slugOverride = isset($_POST['theme_slug']) ? (string)$_POST['theme_slug'] : null;
        $themesDir    = $config['appRoot'] . '/site/themes';

        $result = ServiceFactory::themeArchiver()->install($file['tmp_name'], $themesDir, $slugOverride);
        if (!$result['ok']) {
            \json_response(['ok' => false, 'error' => $result['error']], 400);
        }

        // If we just overwrote the active theme, its assets directory may
        // have changed shape (symlink target stays the same string, but a
        // missing assets/ would have broken `ensureAssetsLink`). Cheap to
        // re-run either way.
        if ($result['slug'] === $themes->active()) {
            $themes->ensureAssetsLink();
        }
        self::clearCache($config);

        \json_response([
            'ok'       => true,
            'slug'     => $result['slug'],
            'replaced' => $result['replaced'],
        ]);
    }

    /** @param callable(): array<string, mixed> $fn */
    private static function themeFileResponse(callable $fn): void
    {
        try {
            \json_response($fn());
        } catch (\RuntimeException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** @param array<string, mixed> $config */
    private static function list(ThemeService $themes, array $config): void
    {
        // Starters and installed themes both use `theme.json` for metadata.
        // The Starters card globs `cms/starters/*/theme.json`; the Installed
        // card globs `site/themes/*/theme.json` (handled by ThemeService::list).
        $starters = [];
        foreach (glob($config['cmsRoot'] . '/starters/*/theme.json') ?: [] as $f) {
            $slug            = basename(dirname($f));
            $meta            = json_decode((string)file_get_contents($f), true) ?? [];
            $engine          = $meta['engine'] ?? ThemeService::detectEngine(dirname($f) . '/templates');
            $starters[$slug] = array_merge(['name' => $slug, 'description' => ''], $meta, ['slug' => $slug, 'engine' => $engine]);
        }
        \json_response([
            'ok'       => true,
            'themes'   => array_values($themes->list()),
            'active'   => $themes->active(),
            'starters' => array_values($starters),
        ]);
    }

    /**
     * Run a `ThemeFiles` mutation, return its JSON, and clear the page +
     * Twig caches on success. Centralised so the four `file-*` actions
     * stay tiny.
     *
     * @param array<string, mixed> $config
     * @param callable(): array<string, mixed> $fn
     */
    private static function themeFileMutation(array $config, callable $fn): void
    {
        try {
            $result = $fn();
        } catch (\RuntimeException $e) {
            \json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        if (!empty($result['ok'])) {
            self::clearCache($config);
            \json_response($result);
        }
        \json_response(['ok' => false, 'error' => $result['error'] ?? 'Failed'], 400);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function dispatchFileMutation(\FrontPress\ThemeFiles $files, string $action, array $body): array
    {
        $theme = isset($body['theme']) ? (string)$body['theme'] : null;
        return match ($action) {
            'file-create'    => $files->create($theme, (string)($body['path'] ?? ''), (string)($body['content'] ?? '')),
            'file-delete'    => $files->delete($theme, (string)($body['path'] ?? '')),
            'file-rename'    => $files->rename($theme, (string)($body['from'] ?? ''), (string)($body['to'] ?? '')),
            'file-duplicate' => $files->duplicate($theme, (string)($body['from'] ?? ''), (string)($body['to'] ?? '')),
            default          => ['ok' => false, 'error' => 'Unknown file action'],
        };
    }

    /** @param array<string, mixed> $config */
    private static function clearCache(array $config): void
    {
        $cache = ServiceFactory::cache($config);
        $cache->clearAllHtml();
        $cache->clearIndex();
        $cache->clearTwig();
        // On no-symlink hosts the public assets/ dir is a copy of the
        // active theme's assets/. Edits to `assets/style.css` in the
        // Theme Builder won't show up until we re-copy. Cheap no-op
        // when the install IS using symlinks (early return inside).
        ServiceFactory::themes($config)->refreshActiveAssets();
    }
}
