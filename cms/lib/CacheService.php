<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

class CacheService
{
    private PathResolver $paths;
    private string $contentDir;
    private string $cacheDir;

    public function __construct(PathResolver $paths, string $contentDir, string $cacheDir)
    {
        $this->paths      = $paths;
        $this->contentDir = $contentDir;
        $this->cacheDir   = $cacheDir;
    }

    public function clearPage(string $relPath): void
    {
        $f = $this->paths->htmlCacheFile($relPath);
        if (is_file($f)) {
            unlink($f);
        }
    }

    public function clearIndex(): void
    {
        $f = $this->paths->indexCacheFile();
        if (is_file($f)) {
            unlink($f);
        }
        $marker = $this->cacheDir . '/index.mtime';
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        @touch($marker);
    }

    public function clearAllHtml(): void
    {
        $htmlDir = $this->cacheDir . '/html';
        if (!is_dir($htmlDir)) {
            return;
        }
        foreach (glob($htmlDir . '/*.json') ?: [] as $f) {
            unlink($f);
        }
    }

    /**
     * Recursively delete the Twig compiled-template cache. Called on theme
     * activation so a swapped theme never serves a compiled template from the
     * previous theme.
     */
    public function clearTwig(): void
    {
        $twigDir = $this->cacheDir . '/twig';
        if (!is_dir($twigDir)) {
            return;
        }
        $rdi = new \RecursiveDirectoryIterator($twigDir, \FilesystemIterator::SKIP_DOTS);
        $rii = new \RecursiveIteratorIterator($rdi, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
    }

    /**
     * Clear the index, HTML, and Twig caches and rebuild the post index.
     *
     * Set `$warm = true` to additionally re-parse every page so the HTML cache
     * is hot when the next request comes in. That's a big-O(n) walk over all
     * content and used to run unconditionally — it now only runs on demand
     * (admin "Warm cache" button) so a routine save doesn't re-render the
     * whole site synchronously.
     *
     * @return array{ok: bool, count?: int, warmed?: bool, error?: string}
     */
    public function rebuild(bool $warm = false): array
    {
        $htmlDir = $this->cacheDir . '/html';
        if (is_dir($htmlDir)) {
            foreach (glob($htmlDir . '/*.{json,json.tmp,php}', GLOB_BRACE) ?: [] as $f) {
                unlink($f);
            }
        }
        $this->clearIndex();
        $this->clearTwig();

        $content = new Content($this->contentDir, $this->cacheDir);
        $index   = new Index($this->contentDir, $this->cacheDir, $content);
        $index->build();
        $pages = $index->get(includeDrafts: true);
        if ($warm) {
            foreach ($pages as $page) {
                $content->load($page['path']);
            }
        }
        return ['ok' => true, 'count' => count($pages), 'warmed' => $warm];
    }
}
