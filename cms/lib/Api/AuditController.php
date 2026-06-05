<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

class AuditController
{
    /** @param array<string, mixed> $config */
    public static function handle(string $method, array $config): void
    {
        Router::requireAuth();
        if ($method !== 'GET') {
            \json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
        $rows  = ServiceFactory::audit($config)->tail($limit);
        \json_response(['ok' => true, 'entries' => $rows]);
    }
}
