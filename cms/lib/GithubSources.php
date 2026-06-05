<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Catalogue of pushable paths under `site/` plus the gather/redact logic
 * the push pipeline needs. `cache/` is deliberately absent — it's
 * regenerated on demand and shouldn't be in version control.
 */
class GithubSources
{
    /** @var list<array{key: string, path: string, label: string, type: string, warning: ?string}> */
    public const ITEMS = [
        ['key' => 'content',     'path' => 'content',     'label' => 'Content',          'type' => 'dir',  'warning' => null],
        ['key' => 'themes',      'path' => 'themes',      'label' => 'Themes',           'type' => 'dir',  'warning' => null],
        ['key' => 'uploads',     'path' => 'uploads',     'label' => 'Uploads (media)',  'type' => 'dir',  'warning' => 'May be large; pushes everything in site/uploads/.'],
        ['key' => 'config',      'path' => 'config.json', 'label' => 'Settings (config.json)', 'type' => 'file', 'warning' => 'GitHub token and SMTP password are redacted before pushing.'],
    ];

    /**
     * List the sources for the UI, annotated with existence and current
     * selection state.
     *
     * @param string[] $picked
     * @return list<array<string, mixed>>
     */
    public static function listForUi(string $appRoot, array $picked): array
    {
        $out = [];
        foreach (self::ITEMS as $s) {
            $abs    = $appRoot . '/site/' . $s['path'];
            $exists = $s['type'] === 'dir' ? is_dir($abs) : is_file($abs);
            $out[]  = $s + ['exists' => $exists, 'selected' => in_array($s['key'], $picked, true)];
        }
        return $out;
    }

    /**
     * Sanitize a user-supplied list of keys, dropping anything not in
     * the allow-list and preserving order.
     *
     * @param mixed[] $rawKeys
     * @return string[]
     */
    public static function sanitizeKeys(array $rawKeys): array
    {
        $allowed = array_column(self::ITEMS, 'key');
        return array_values(array_intersect($allowed, array_map('strval', $rawKeys)));
    }

    /**
     * Build the `[remotePath => content]` map for the picked sources.
     * Directories are walked recursively; config.json is redacted.
     *
     * @param string[] $picked
     * @return array<string, string>
     */
    public static function gather(string $appRoot, array $picked): array
    {
        $files = [];
        foreach (self::ITEMS as $s) {
            if (!in_array($s['key'], $picked, true)) continue;
            $abs = $appRoot . '/site/' . $s['path'];
            if ($s['type'] === 'dir') {
                if (!is_dir($abs)) continue;
                foreach (GithubPusher::walk($abs) as $rel => $absFile) {
                    $content = file_get_contents($absFile);
                    if ($content === false) continue;
                    $files[$s['path'] . '/' . $rel] = $content;
                }
            } else {
                if (!is_file($abs)) continue;
                $content = file_get_contents($abs);
                if ($content === false) continue;
                if ($s['key'] === 'config') {
                    $content = self::redactConfig($content);
                }
                $files[$s['path']] = $content;
            }
        }
        return $files;
    }

    /**
     * Strip secrets from config.json before it leaves the install. Keeps
     * structure so a restore still parses; user re-enters secrets after.
     */
    private static function redactConfig(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) return $json;
        // Drop the github block entirely — token is session-bound and our
        // connection state shouldn't be in another install's restore.
        unset($data['github']);
        // SMTP password — empty out, preserve the rest so the user only
        // has to retype the password after restoring.
        if (isset($data['email']) && is_array($data['email'])) {
            $data['email']['smtp_pass'] = '';
        }
        return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
