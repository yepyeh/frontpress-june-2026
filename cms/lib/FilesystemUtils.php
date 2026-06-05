<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Tiny grab-bag of generic recursive filesystem helpers shared by services that
 * have no business owning their own copy (theme install, backup restore, etc.).
 *
 * Kept deliberately small — when something needs more than these primitives,
 * pull in a real library rather than expanding this file.
 */
final class FilesystemUtils
{
    /**
     * Recursively delete a directory or file. No-op if missing. Handles
     * symlinks and one-off files transparently so callers don't have to
     * branch on `is_file($x)` first.
     */
    public static function removeDir(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }

    /** Recursively copy `$src` to `$dst`, creating directories as needed. */
    public static function copyDir(string $src, string $dst): void
    {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $target = $dst . '/' . substr($item->getPathname(), strlen($src) + 1);
            $item->isDir()
                ? (is_dir($target) ?: mkdir($target, 0755, true))
                : copy($item->getPathname(), $target);
        }
    }
}
