<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Builds a ZIP archive of user-owned site state (content, config, themes,
 * uploads) for one-click backup. Caches are intentionally excluded — they
 * regenerate from source.
 */
class BackupService
{
    public const SIZE_WARN_BYTES = 500 * 1024 * 1024;

    /**
     * Scopes let the admin grab a focused subset instead of the full site:
     *   - full:     everything below.
     *   - content:  the stuff you *write* — posts + their uploads.
     *   - settings: the stuff you *configure* — config + themes.
     */
    public const SCOPES = [
        'full'     => ['site/content', 'site/config.json', 'site/themes', 'site/uploads'],
        'content'  => ['site/content', 'site/uploads'],
        'settings' => ['site/config.json', 'site/themes'],
    ];

    /** Entries in a valid backup must begin with one of these prefixes (or be exactly `site/config.json`). */
    private const ALLOWED_PREFIXES = ['site/content/', 'site/themes/', 'site/uploads/'];

    /** @var array<string, array{src: string, prefix: string}> */
    private array $roots;

    private string $appRoot;
    private string $uploadsDir;

    public function __construct(string $appRoot, string $uploadsDir)
    {
        $this->appRoot    = rtrim($appRoot, '/');
        $this->uploadsDir = rtrim($uploadsDir, '/');
        $this->roots      = [
            'site/content'     => ['src' => $this->appRoot . '/site/content',     'prefix' => 'site/content'],
            'site/config.json' => ['src' => $this->appRoot . '/site/config.json', 'prefix' => 'site/config.json'],
            'site/themes'      => ['src' => $this->appRoot . '/site/themes',      'prefix' => 'site/themes'],
            'site/uploads'     => ['src' => $this->uploadsDir,                    'prefix' => 'site/uploads'],
        ];
    }

    /** Total bytes the backup would contain for $scope. Missing roots contribute 0. */
    public function estimateSize(string $scope = 'full'): int
    {
        $total = 0;
        foreach ($this->collect($scope) as $file) {
            $total += $file['size'];
        }
        return $total;
    }

    /**
     * Build a ZIP at $dest containing the files covered by $scope,
     * preserving relative paths. Returns true on success.
     */
    public function writeZip(string $dest, string $scope = 'full'): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }
        foreach ($this->collect($scope) as $file) {
            $zip->addFile($file['path'], $file['entry']);
        }
        return $zip->close();
    }

    /**
     * Walk the roots that belong to $scope and yield {path, entry, size} per
     * real file. Unknown scopes fall back to 'full'.
     *
     * @return \Generator<int, array{path: string, entry: string, size: int}>
     */
    private function collect(string $scope): \Generator
    {
        $keys = self::SCOPES[$scope] ?? self::SCOPES['full'];
        foreach ($keys as $key) {
            $root = $this->roots[$key];
            $src  = $root['src'];
            if (is_file($src)) {
                yield ['path' => $src, 'entry' => $root['prefix'], 'size' => filesize($src) ?: 0];
                continue;
            }
            if (!is_dir($src)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = substr($file->getPathname(), strlen($src) + 1);
                yield [
                    'path'  => $file->getPathname(),
                    'entry' => $root['prefix'] . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $rel),
                    'size'  => $file->getSize(),
                ];
            }
        }
    }

    /**
     * Inspect a ZIP without extracting. Returns ['ok' => true, 'counts' => [...]]
     * if the archive looks like a backup we can restore, otherwise
     * ['ok' => false, 'error' => 'reason'].
     *
     * Every entry must live under one of the allowed prefixes (or be exactly
     * `site/config.json`) and must not contain path-traversal segments.
     * Partial backups (content-only, settings-only) are accepted as long as
     * at least one known root is present.
     *
     * @return array{ok: true, counts: array<string, int>}|array{ok: false, error: string}
     */
    public function inspectZip(string $zipPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            return ['ok' => false, 'error' => 'Not a valid ZIP archive'];
        }

        $counts = ['site/content' => 0, 'site/config.json' => 0, 'site/themes' => 0, 'site/uploads' => 0];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '') {
                continue;
            }
            // Directory entries end with '/'; ignore them — we only care about files.
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (!$this->isSafeEntry($name)) {
                $zip->close();
                return ['ok' => false, 'error' => 'Unsafe entry in archive: ' . $name];
            }
            if ($name === 'site/config.json') {
                $counts['site/config.json'] = 1;
                continue;
            }
            foreach (self::ALLOWED_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $counts[rtrim($prefix, '/')]++;
                    continue 2;
                }
            }
            $zip->close();
            return ['ok' => false, 'error' => 'Entry outside backup roots: ' . $name];
        }
        $zip->close();

        if (array_sum($counts) === 0) {
            return ['ok' => false, 'error' => 'Archive contains no recognizable backup roots'];
        }
        return ['ok' => true, 'counts' => $counts];
    }

    /**
     * Restore a validated backup ZIP onto the live install. Delegates the
     * staging + rename + rollback machinery to {@see BackupRestorer}; this
     * method exists for backwards compatibility (the API surface used to live
     * here).
     *
     * @return array{ok: true, counts: array<string, int>}|array{ok: false, error: string}
     */
    public function restore(string $zipPath): array
    {
        return (new BackupRestorer($this, $this->roots))->restore($zipPath);
    }

    private function isSafeEntry(string $name): bool
    {
        if ($name === '' || $name[0] === '/') {
            return false;
        }
        // Normalise backslashes (Windows ZIPs occasionally sneak them in).
        if (str_contains($name, '\\')) {
            return false;
        }
        foreach (explode('/', $name) as $seg) {
            if ($seg === '..' || $seg === '.') {
                return false;
            }
        }
        return true;
    }
}
