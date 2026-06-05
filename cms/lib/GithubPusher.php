<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Pushes a flat map of files to a GitHub repo as one atomic commit using
 * the low-level Git Data API (blobs → tree → commit → ref). One commit
 * regardless of how many files changed; matches what `git push` produces.
 *
 * Why not the Contents API (`PUT /repos/.../contents/{path}`): every PUT
 * creates its own commit. A 40-file theme would become 40 commits.
 *
 * Requires the target repo to already have at least one commit. An empty
 * repo has no `refs/heads/{branch}` to update; the caller gets a clear
 * error in that case rather than us juggling initial-push endpoints.
 */
class GithubPusher
{
    public function __construct(
        private GithubClient $client,
        private string $owner,
        private string $repo,
        private string $branch,
    ) {}

    /**
     * Push files to the repo.
     *
     * @param array<string, string>           $files Map of `remote/path.ext` → file content (raw, NOT base64).
     * @param (callable(int,int,string):void)|null $onProgress Called after each blob: `($done, $total, $currentPath)`.
     * @return array{ok: bool, commit?: string, error?: string, files?: int, status?: int}
     */
    public function push(array $files, string $message, ?callable $onProgress = null): array
    {
        if (empty($files)) {
            return ['ok' => false, 'error' => 'Nothing to push'];
        }

        // Total = blob uploads + 3 finalisation steps (tree, commit, ref).
        // Reporting `total` this way means the bar reaches 100% only after
        // the ref is updated, not after the last blob.
        $blobTotal  = count($files);
        $totalSteps = $blobTotal + 3;
        $step       = 0;
        $report = function (string $current) use ($onProgress, &$step, $totalSteps): void {
            $step++;
            if ($onProgress) $onProgress($step, $totalSteps, $current);
        };

        // 1. Current branch tip — the parent commit of the one we'll create.
        $refRes = $this->client->get("/repos/{$this->owner}/{$this->repo}/git/ref/heads/{$this->branch}");
        if (!$refRes['ok']) {
            return ['ok' => false, 'status' => self::httpStatus($refRes), 'error' => $this->humanRefError($refRes)];
        }
        $parentSha = (string)($refRes['data']['object']['sha'] ?? '');
        if ($parentSha === '') {
            return ['ok' => false, 'error' => 'Could not read parent commit SHA'];
        }

        // 2. Upload each file as a blob.
        $treeEntries = [];
        foreach ($files as $remotePath => $content) {
            $blobRes = $this->client->post(
                "/repos/{$this->owner}/{$this->repo}/git/blobs",
                ['content' => base64_encode($content), 'encoding' => 'base64'],
            );
            if (!$blobRes['ok']) {
                return [
                    'ok' => false,
                    'status' => self::httpStatus($blobRes),
                    'error' => "Blob upload failed for {$remotePath}: " . self::formatApiError($blobRes),
                ];
            }
            $treeEntries[] = [
                'path' => $remotePath,
                'mode' => '100644',
                'type' => 'blob',
                'sha'  => (string)$blobRes['data']['sha'],
            ];
            $report($remotePath);
        }

        // 3. New tree based on parent — preserves anything outside our paths.
        $parentCommit = $this->client->get("/repos/{$this->owner}/{$this->repo}/git/commits/{$parentSha}");
        if (!$parentCommit['ok']) {
            return [
                'ok' => false,
                'status' => self::httpStatus($parentCommit),
                'error' => 'Failed to read parent commit: ' . ($parentCommit['reason'] ?? 'unknown'),
            ];
        }
        $baseTreeSha = (string)($parentCommit['data']['tree']['sha'] ?? '');

        $treeRes = $this->client->post(
            "/repos/{$this->owner}/{$this->repo}/git/trees",
            ['base_tree' => $baseTreeSha, 'tree' => $treeEntries],
        );
        if (!$treeRes['ok']) {
            return [
                'ok' => false,
                'status' => self::httpStatus($treeRes),
                'error' => 'Tree creation failed: ' . self::formatApiError($treeRes),
            ];
        }
        $treeSha = (string)$treeRes['data']['sha'];
        $report('Building tree');

        // 4. Commit pointing at the new tree, with the current tip as parent.
        $commitRes = $this->client->post(
            "/repos/{$this->owner}/{$this->repo}/git/commits",
            ['message' => $message, 'tree' => $treeSha, 'parents' => [$parentSha]],
        );
        if (!$commitRes['ok']) {
            return [
                'ok' => false,
                'status' => self::httpStatus($commitRes),
                'error' => 'Commit creation failed: ' . self::formatApiError($commitRes),
            ];
        }
        $newCommitSha = (string)$commitRes['data']['sha'];
        $report('Creating commit');

        // 5. Fast-forward the branch ref to the new commit.
        $updateRes = $this->client->patch(
            "/repos/{$this->owner}/{$this->repo}/git/refs/heads/{$this->branch}",
            ['sha' => $newCommitSha, 'force' => false],
        );
        if (!$updateRes['ok']) {
            return [
                'ok' => false,
                'status' => self::httpStatus($updateRes),
                'error' => 'Ref update failed: ' . self::formatApiError($updateRes),
            ];
        }
        $report('Updating branch');

        return ['ok' => true, 'commit' => $newCommitSha, 'files' => count($treeEntries)];
    }

    /**
     * Walk a directory and return [relativePath => absolutePath]. Skips
     * dot-files and OS junk that has no business in a git repo.
     *
     * @return array<string, string>
     */
    public static function walk(string $root): array
    {
        if (!is_dir($root)) return [];
        $rootLen = strlen(rtrim($root, '/')) + 1;
        $out     = [];
        $iter    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iter as $file) {
            if (!$file->isFile()) continue;
            $name = $file->getFilename();
            if ($name === '.DS_Store' || $name === 'Thumbs.db') continue;
            $abs = $file->getPathname();
            $rel = str_replace('\\', '/', substr($abs, $rootLen));
            $out[$rel] = $abs;
        }
        ksort($out);
        return $out;
    }

    /**
     * Turn a failed API response into a human string. GitHub returns
     * `{message, documentation_url, errors?}` for most errors — surface
     * the message instead of just the HTTP code so users see the real
     * cause (push protection, branch protection, scope, etc.).
     */
    private static function formatApiError(array $res): string
    {
        $reason = (string)($res['reason'] ?? 'unknown');
        $data   = $res['data'] ?? null;
        if (is_array($data) && !empty($data['message'])) {
            return $reason . ' — ' . (string)$data['message'];
        }
        return $reason;
    }

    private static function httpStatus(array $res): int
    {
        $status = (int)($res['status'] ?? 0);
        if ($status === 403 && str_contains(self::formatApiError($res), 'rate limit exceeded')) {
            return 429;
        }
        return $status >= 400 ? $status : 502;
    }

    /** GET ref-related errors → friendly text. */
    private function humanRefError(array $res): string
    {
        if (($res['status'] ?? 0) === 404) {
            return "Branch `{$this->branch}` not found in {$this->owner}/{$this->repo}. "
                 . 'If this is a brand-new repo, push an initial commit (e.g. a README) via the GitHub web UI first.';
        }
        if (($res['status'] ?? 0) === 401 || ($res['status'] ?? 0) === 403) {
            return 'GitHub denied access to this repo — disconnect and reconnect to refresh the token.';
        }
        return 'Could not read branch tip: ' . ($res['reason'] ?? 'unknown');
    }
}
