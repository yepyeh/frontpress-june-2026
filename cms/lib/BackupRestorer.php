<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Restores a validated backup ZIP onto the live install with an atomic-rename
 * dance per backup root. Lives in its own class so {@see BackupService} can
 * stay focused on building/inspecting archives.
 *
 * Strategy:
 *   1. Extract to a staging directory.
 *   2. For each of the backup roots, rename the live path to a
 *      `.restore-bak-<ts>` sibling, then move the staged root into place.
 *   3. On success, remove the `.restore-bak-*` siblings.
 *   4. On any failure, roll back the renames and remove the staging dir.
 *
 * The rename keeps each swap atomic from a reader's point of view — live
 * requests see either the old tree or the fully-extracted new one.
 */
final class BackupRestorer
{
    private BackupService $backup;
    /** @var array<string, array{src: string, prefix: string}> */
    private array $roots;

    /** @param array<string, array{src: string, prefix: string}> $roots */
    public function __construct(BackupService $backup, array $roots)
    {
        $this->backup = $backup;
        $this->roots  = $roots;
    }

    /**
     * @return array{ok: true, counts: array<string, int>}|array{ok: false, error: string}
     */
    public function restore(string $zipPath): array
    {
        $check = $this->backup->inspectZip($zipPath);
        if (!$check['ok']) {
            return $check;
        }

        $stage = sys_get_temp_dir() . '/mdrestore_' . bin2hex(random_bytes(6));
        if (!@mkdir($stage, 0755, true)) {
            return ['ok' => false, 'error' => 'Could not create staging directory'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            FilesystemUtils::removeDir($stage);
            return ['ok' => false, 'error' => 'Could not open ZIP for extraction'];
        }
        if (!$zip->extractTo($stage)) {
            $zip->close();
            FilesystemUtils::removeDir($stage);
            return ['ok' => false, 'error' => 'ZIP extraction failed'];
        }
        $zip->close();

        $ts      = date('YmdHis');
        $renames = []; // [live, backup] pairs we did, for rollback.

        foreach ($this->roots as $root) {
            $stagedPath = $stage . '/' . $root['prefix'];
            $livePath   = $root['src'];

            if (!file_exists($stagedPath)) {
                continue;
            }

            $bak = $livePath . '.restore-bak-' . $ts;
            if (file_exists($livePath)) {
                if (!@rename($livePath, $bak)) {
                    $this->rollback($renames);
                    FilesystemUtils::removeDir($stage);
                    return ['ok' => false, 'error' => 'Could not move existing ' . $root['prefix'] . ' aside'];
                }
                $renames[] = [$livePath, $bak];
            }

            if (!@is_dir(dirname($livePath)) && !@mkdir(dirname($livePath), 0755, true)) {
                $this->rollback($renames);
                FilesystemUtils::removeDir($stage);
                return ['ok' => false, 'error' => 'Could not create parent directory for ' . $root['prefix']];
            }
            if (!@rename($stagedPath, $livePath)) {
                $this->rollback($renames);
                FilesystemUtils::removeDir($stage);
                return ['ok' => false, 'error' => 'Could not install restored ' . $root['prefix']];
            }
        }

        foreach ($renames as [, $bak]) {
            FilesystemUtils::removeDir($bak);
        }
        FilesystemUtils::removeDir($stage);

        return ['ok' => true, 'counts' => $check['counts']];
    }

    /** @param list<array{0: string, 1: string}> $renames */
    private function rollback(array $renames): void
    {
        foreach (array_reverse($renames) as [$live, $bak]) {
            if (file_exists($live)) {
                FilesystemUtils::removeDir($live);
            }
            @rename($bak, $live);
        }
    }
}
