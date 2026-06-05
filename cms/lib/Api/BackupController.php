<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

use FrontPress\BackupService;

class BackupController
{
    /**
     * @param string[] $pathParts
     * @param array<string, mixed> $config
     */
    public static function handle(array $pathParts, string $method, array $config): void
    {
        Router::requireAuth();

        $backup = ServiceFactory::backup($config);
        $action = $pathParts[0] ?? '';

        if ($method === 'GET' && $action === '') {
            \json_response([
                'ok'    => true,
                'sizes' => [
                    'full'     => $backup->estimateSize('full'),
                    'content'  => $backup->estimateSize('content'),
                    'settings' => $backup->estimateSize('settings'),
                ],
            ]);
        }

        Router::requireCsrf();

        if ($method === 'POST' && $action === 'download') {
            self::download($backup);
            return;
        }
        if ($method === 'POST' && $action === 'restore') {
            self::restore($backup, $config);
            return;
        }

        \json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
    }

    private static function download(BackupService $backup): void
    {
        $body  = Router::jsonBody();
        $scope = (string)($body['scope'] ?? 'full');
        if (!isset(BackupService::SCOPES[$scope])) {
            $scope = 'full';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'mdbackup_');
        if ($tmp === false || !$backup->writeZip($tmp, $scope)) {
            if ($tmp) {
                @unlink($tmp);
            }
            \json_response(['ok' => false, 'error' => 'Failed to write backup'], 500);
        }
        $stamp = date('Y-m-d');
        $label = $scope === 'full' ? 'backup' : $scope;
        // Override the JSON content-type set by the router.
        header_remove('Content-Type');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="frontpress-studio-' . $label . '-' . $stamp . '.zip"');
        header('Content-Length: ' . (string)filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /** @param array<string, mixed> $config */
    private static function restore(BackupService $backup, array $config): void
    {
        $file = $_FILES['backup'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            \json_response(['ok' => false, 'error' => 'No file uploaded'], 400);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            \json_response(['ok' => false, 'error' => 'Invalid upload'], 400);
        }
        $result = $backup->restore($file['tmp_name']);
        if (!empty($result['ok'])) {
            $cache = ServiceFactory::cache($config);
            $cache->clearAllHtml();
            $cache->clearIndex();
            \json_response(['ok' => true]);
        }
        \json_response(['ok' => false, 'error' => $result['error'] ?? 'Restore failed'], 400);
    }
}
