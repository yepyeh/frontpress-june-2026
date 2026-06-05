<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

use Symfony\Component\Yaml\Yaml;

/**
 * Write-side counterpart to {@see Content}: knows how to serialise front
 * matter + body back to disk, and pairs every write/delete with the cache
 * invalidations that have to happen alongside it. The `parse*` methods are
 * thin pass-throughs so callers can hold a single object instead of two —
 * but the real reason this class exists is `save()` and `delete()`.
 */
class ContentRepository
{
    private string $contentDir;
    private CacheService $cache;
    private Content $content;

    public function __construct(string $contentDir, CacheService $cache, Content $content)
    {
        $this->contentDir = rtrim($contentDir, '/');
        $this->cache      = $cache;
        $this->content    = $content;
    }

    /** @return array<string, mixed> */
    public function parseMeta(string $absPath): array
    {
        return $this->content->parseMeta($absPath);
    }

    /** @return array<string, mixed> */
    public function parse(string $absPath): array
    {
        return $this->content->parse($absPath);
    }

    /** @param array<string, mixed> $meta */
    public function save(string $relPath, array $meta, string $body): void
    {
        $file     = $this->contentDir . '/' . $relPath . '.md';
        $contents = "---\n" . Yaml::dump($meta, 2, 2) . "---\n\n" . $body;

        if (!Fs::atomicWrite($file, $contents)) {
            throw new \RuntimeException("Failed to write content file: {$relPath}");
        }
        $this->cache->clearPage($relPath);
        $this->cache->clearIndex();
    }

    public function delete(string $relPath, string $absPath): void
    {
        unlink($absPath);
        $this->cache->clearPage($relPath);
        $this->cache->clearIndex();
    }

    /**
     * Move a content file from one relPath to another. Also moves the
     * matching per-post upload directory (`site/content/<oldPath>/`) when it
     * exists, so attachments referenced by the post stay alongside it.
     *
     * Caller is responsible for path validation; this method assumes both
     * paths are already inside the content dir.
     *
     * @return array{ok: true}|array{ok: false, error: string}
     */
    public function rename(string $oldRelPath, string $newRelPath): array
    {
        if ($oldRelPath === $newRelPath) {
            return ['ok' => true];
        }
        $oldFile = $this->contentDir . '/' . $oldRelPath . '.md';
        $newFile = $this->contentDir . '/' . $newRelPath . '.md';
        if (!is_file($oldFile)) {
            return ['ok' => false, 'error' => 'Source file not found'];
        }
        if (file_exists($newFile)) {
            return ['ok' => false, 'error' => 'A page already exists at the new path'];
        }

        $newDir = dirname($newFile);
        if (!is_dir($newDir) && !@mkdir($newDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create destination directory'];
        }
        if (!@rename($oldFile, $newFile)) {
            return ['ok' => false, 'error' => 'Could not move content file'];
        }

        // Per-post attachments live in `site/content/<oldRelPath>/`. Move the
        // whole directory so URLs like `/uploads/<oldRelPath>/cover.jpg`
        // continue to resolve under the new path. (The post's own body still
        // references `/uploads/<oldRelPath>/...` URLs — the user is expected
        // to update those if they care; we don't rewrite body content here.)
        $oldAttachDir = $this->contentDir . '/' . $oldRelPath;
        $newAttachDir = $this->contentDir . '/' . $newRelPath;
        if (is_dir($oldAttachDir) && !is_dir($newAttachDir)) {
            @rename($oldAttachDir, $newAttachDir);
        }

        $this->cache->clearPage($oldRelPath);
        $this->cache->clearPage($newRelPath);
        $this->cache->clearIndex();
        return ['ok' => true];
    }
}
