<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

use FrontPress\Content;
use FrontPress\Index;
use FrontPress\PathResolver;


class SearchController
{
    /** @param array<string, mixed> $config */
    public static function handle(string $method, array $config): void
    {
        Router::requireAuth();
        if ($method !== 'GET') {
            \json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
        }
        $q = strtolower(trim((string)($_GET['q'] ?? '')));
        if (strlen($q) < 2) {
            \json_response(['ok' => true, 'results' => []]);
        }
        // Body search reads every .md file from disk to match — fine for small
        // sites, painful at scale. Off by default; pass `?body=1` (the admin
        // UI can wire this to a "search inside content" toggle) to opt in.
        $searchBody = (string)($_GET['body'] ?? '') === '1';

        $paths   = ServiceFactory::paths($config);
        $content = ServiceFactory::content($config);
        $index   = ServiceFactory::index($config, $content);

        $results = [];
        foreach ($index->get(includeDrafts: true) as $page) {
            $titleMatch = str_contains(strtolower((string)($page['title'] ?? '')), $q);
            $pathMatch  = str_contains(strtolower((string)($page['path']  ?? '')), $q);
            $bodyMatch  = false;
            if ($searchBody && !$titleMatch && !$pathMatch) {
                $abs = $paths->contentFile($page['path']);
                if ($abs) {
                    $bodyMatch = str_contains(strtolower((string)file_get_contents($abs)), $q);
                }
            }
            if ($titleMatch || $pathMatch || $bodyMatch) {
                $results[] = [
                    'path'   => $page['path'],
                    'title'  => $page['title']  ?? '',
                    'folder' => $page['folder'] ?? '',
                    'draft'  => !empty($page['draft']),
                    'match'  => $titleMatch ? 'title' : ($pathMatch ? 'path' : 'body'),
                ];
            }
        }
        \json_response(['ok' => true, 'results' => $results]);
    }
}
