<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

class ThemeAssets
{
    private const MARKER = '.fp-theme';

    public function __construct(
        private string $publicDir,
        private string $themesDir,
    ) {}

    public function ensure(string $slug): bool
    {
        $link = $this->publicDir . '/assets';
        if (!is_dir($this->themeAssetsDir($slug))) return false;

        if (is_link($link) && readlink($link) === $this->relativeTarget($slug)) {
            return true;
        }
        if (is_dir($link) && !is_link($link) && self::readMarker($link) === $slug) {
            return true;
        }

        return $this->relink($slug)['ok'];
    }

    /** @return array{ok: bool, error?: string} */
    public function relink(string $slug): array
    {
        $link      = $this->publicDir . '/assets';
        $assetsDir = $this->themeAssetsDir($slug);
        if (!is_dir($assetsDir) && !@mkdir($assetsDir, 0755, true) && !is_dir($assetsDir)) {
            return ['ok' => false, 'error' => "Could not create assets dir for theme '{$slug}'"];
        }

        $backup = null;
        if (is_link($link)) {
            if (!@unlink($link)) {
                return ['ok' => false, 'error' => 'Could not remove previous assets symlink'];
            }
        } elseif (is_dir($link)) {
            $backup = $link . '_bak_' . time();
            if (!@rename($link, $backup)) {
                return ['ok' => false, 'error' => 'Could not move previous assets directory aside'];
            }
        }

        if ($this->createSymlink($this->relativeTarget($slug), $link)) {
            return ['ok' => true];
        }

        if (!self::copyDir($assetsDir, $link)) {
            @self::removeDir($link);
            if ($backup !== null && is_dir($backup)) {
                @rename($backup, $link);
            }
            return ['ok' => false, 'error' => 'Could not copy theme assets (filesystem permissions?)'];
        }

        self::writeMarker($link, $slug);
        if ($backup !== null && is_dir($backup)) {
            @self::removeDir($backup);
        }
        return ['ok' => true];
    }

    public function refresh(string $slug): void
    {
        $link = $this->publicDir . '/assets';
        if (is_link($link)) return;

        $assetsDir = $this->themeAssetsDir($slug);
        if (!is_dir($assetsDir) || !is_dir($link)) return;

        @self::removeDir($link);
        @mkdir($link, 0755, true);
        self::copyDir($assetsDir, $link);
        self::writeMarker($link, $slug);
    }

    protected function createSymlink(string $target, string $link): bool
    {
        return function_exists('symlink') && @symlink($target, $link);
    }

    private function themeAssetsDir(string $slug): string
    {
        return $this->themesDir . '/' . $slug . '/assets';
    }

    private function relativeTarget(string $slug): string
    {
        return 'site/themes/' . $slug . '/assets';
    }

    private static function readMarker(string $dir): ?string
    {
        $file = $dir . '/' . self::MARKER;
        return is_file($file) ? trim((string)@file_get_contents($file)) : null;
    }

    private static function writeMarker(string $dir, string $slug): void
    {
        @file_put_contents($dir . '/' . self::MARKER, $slug);
    }

    private static function copyDir(string $src, string $dst): bool
    {
        if (!is_dir($src)) return false;
        if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) return false;

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iter as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $target = $dst . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) return false;
            } elseif (!@copy($item->getPathname(), $target)) {
                return false;
            }
        }
        return true;
    }

    private static function removeDir(string $dir): bool
    {
        if (!is_dir($dir) || is_link($dir)) return @unlink($dir) || @rmdir($dir);

        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        return @rmdir($dir);
    }
}
