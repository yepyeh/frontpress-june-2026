<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Thin curl wrapper for the Unsplash API. Kept separate from
 * `Api\UnsplashController` so the controller stays focused on routing
 * and validation — curl plumbing is the kind of thing that bloats
 * controllers if left inline.
 *
 * All calls require the install's Access Key. Search + the
 * download-trigger endpoint use `getJson`; the actual binary fetch from
 * images.unsplash.com uses `getBinary` (which follows redirects and
 * captures the Content-Type so the caller can pick a file extension).
 *
 * CA bundle: Local by Flywheel (and some shared hosts) ship a PHP with
 * `curl.cainfo` pointed at WordPress's `wp-includes/certificates/ca-bundle.crt`
 * — a path that doesn't exist outside a WordPress install. We override
 * CURLOPT_CAINFO with Composer's CA-bundle locator on every call so TLS
 * verification works on those installs without disabling certificate
 * checks.
 */
class UnsplashHttp
{
    public const API_BASE = 'https://api.unsplash.com';

    /** @return array{0:int,1:string,2:string} HTTP status + raw body + curl-error string ('' on success) */
    public static function getJson(string $url, string $accessKey): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, self::baseOpts() + [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Accept-Version: v1',
                'Authorization: Client-ID ' . $accessKey,
            ],
        ]);
        $body   = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        curl_close($ch);
        // Surface curl-level errors (DNS / TLS / connect) so the controller
        // can put them in the JSON response instead of failing silently
        // with a generic 502.
        return [$status, $body, $errno !== 0 ? "curl[$errno]: $err" : ''];
    }

    /** @return array{0:int,1:string,2:array<string,string>} status + body + lowercased headers */
    public static function getBinary(string $url): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, self::baseOpts() + [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADERFUNCTION => function ($_ch, $line) use (&$headers) {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }
                return strlen($line);
            },
        ]);
        $body   = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $body, $headers];
    }

    /**
     * Base curl options shared by every call. The important one is
     * CURLOPT_CAINFO: PHP installs that hardcode `curl.cainfo` to a
     * non-existent path (Local by Flywheel's "wp-includes/certificates/..."
     * is the most common offender) fail every TLS handshake with
     * curl[77]. Composer's CaBundle resolves to a system bundle that
     * actually exists on the host.
     *
     * @return array<int,mixed>
     */
    private static function baseOpts(): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO         => \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath(),
        ];
    }
}
