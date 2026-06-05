<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Per-key sliding-window rate limiter, persisted to a single JSON file.
 *
 * Cheap enough for the contact-form / login-throttle / public-API
 * scenarios this project sees: each `check()` reads + rewrites one file
 * with an exclusive lock. Auto-prunes stale entries on every call so
 * the file size stays bounded by `(active keys) × (recent hits)`.
 *
 * Not a replacement for Redis under heavy concurrent load — by design.
 * If a form ever sees enough traffic that file-lock contention matters,
 * something else has gone very wrong on the way in.
 */
class RateLimiter
{
    public function __construct(private string $file) {}

    /**
     * Allow this hit? If `false`, the caller should return 429.
     *
     * @param string $key       caller-scoped, e.g. "contact:1.2.3.4"
     * @param int    $max       max allowed within $windowSec
     * @param int    $windowSec sliding window length in seconds
     */
    public function check(string $key, int $max, int $windowSec): bool
    {
        $now  = $this->now();
        $data = $this->load();

        // Drop stale entries for this key first so the count below is fresh.
        $hits = array_values(array_filter(
            $data[$key] ?? [],
            fn ($t) => $t > $now - $windowSec,
        ));

        if (count($hits) >= $max) {
            // Persist the pruned list so the file doesn't grow forever
            // even when we deny.
            $data[$key] = $hits;
            $this->prune($data, $now);
            $this->save($data);
            return false;
        }

        $hits[] = $now;
        $data[$key] = $hits;
        $this->prune($data, $now);
        $this->save($data);
        return true;
    }

    /** For tests — override the clock. */
    protected function now(): int
    {
        return time();
    }

    /**
     * Drop keys whose newest entry is older than `$windowMaxSec` (~ one
     * day) so the file can't grow without bound. Cheap because we touch
     * the whole map on every save anyway.
     *
     * @param array<string, list<int>> $data
     */
    private function prune(array &$data, int $now): void
    {
        $cutoff = $now - 86400; // 24h
        foreach ($data as $key => $hits) {
            if (empty($hits)) {
                unset($data[$key]);
                continue;
            }
            $newest = max($hits);
            if ($newest < $cutoff) {
                unset($data[$key]);
            }
        }
    }

    /** @return array<string, list<int>> */
    private function load(): array
    {
        if (!is_file($this->file)) return [];
        $raw = @file_get_contents($this->file);
        if ($raw === false || $raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, list<int>> $data */
    private function save(array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        Fs::atomicWrite($this->file, $json);
    }
}
