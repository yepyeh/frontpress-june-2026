<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Append-only audit log of admin writes. One JSON object per line, written to
 * `site/cache/audit.log`. Intentionally cache-located (not under `site/`) so a
 * full backup-restore doesn't churn it; the log is a forensic tool, not user
 * content.
 *
 * Reading is intentionally simple: tail the file. The reader trims to the
 * most recent `$limit` lines so the admin UI can surface a "Recent activity"
 * panel without slurping a multi-MB file.
 */
final class AuditLog
{
    private string $file;

    public function __construct(string $cacheDir)
    {
        $this->file = rtrim($cacheDir, '/') . '/audit.log';
    }

    /** @param array<string, mixed> $context */
    public function record(string $action, string $resource, array $context = [], ?string $user = null): void
    {
        $entry = [
            'ts'       => date('c'),
            'user'     => $user ?? ($_SESSION['admin_user'] ?? null),
            'ip'       => $_SERVER['REMOTE_ADDR']     ?? null,
            'action'   => $action,    // e.g. "page.save", "media.delete"
            'resource' => $resource,  // e.g. "blog/hello-world"
            'context'  => $context,
        ];
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return; // never throw out of an audit write
        }
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Most-recent-first list of audit entries. Returns at most $limit rows.
     * Malformed lines are skipped silently.
     *
     * @return list<array<string, mixed>>
     */
    public function tail(int $limit = 100): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw  = (string)@file_get_contents($this->file);
        if ($raw === '') {
            return [];
        }
        $lines = preg_split('/\R/', rtrim($raw, "\r\n")) ?: [];
        $lines = array_slice($lines, -$limit);
        $rows  = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
