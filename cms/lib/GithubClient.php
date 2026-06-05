<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Thin authenticated HTTP client for the GitHub REST API.
 *
 * Centralises three concerns that would otherwise be duplicated across
 * every endpoint that talks to GitHub: auth headers, the standard set
 * of GitHub-recommended headers (Accept, User-Agent, X-GitHub-Api-Version),
 * and CA-bundle discovery — the last of which is the only reason this
 * class exists. Bundled-PHP setups (Local by Flywheel, MAMP, XAMPP) often
 * ship without a usable CA bundle and fail with curl_77 on any HTTPS
 * call. We hunt down a system bundle so the user doesn't have to set
 * openssl.cafile by hand.
 */
class GithubClient
{
    public function __construct(private string $token) {}

    /** @return array{ok: bool, status: int, data?: mixed, reason?: string} */
    public function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /** @param array<string, mixed> $body @return array{ok: bool, status: int, data?: mixed, reason?: string} */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    /** @param array<string, mixed> $body @return array{ok: bool, status: int, data?: mixed, reason?: string} */
    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, $body);
    }

    /**
     * Returns:
     *   ['ok' => true,  'status' => 2xx, 'data' => mixed]
     *   ['ok' => false, 'status' => int, 'reason' => 'curl_<n>|http_<n>|no_body|bad_json']
     *
     * @param array<string, mixed>|null $body
     * @return array{ok: bool, status: int, data?: mixed, reason?: string}
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $url = 'https://api.github.com' . $path;
        $ch  = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/vnd.github+json',
                'Content-Type: application/json',
                'User-Agent: frontpress-studio',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = (string)json_encode($body);
        }
        $ca = self::findCaBundle();
        if ($ca !== null) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $rawBody = curl_exec($ch);
        $code    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno   = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'status' => 0, 'reason' => 'curl_' . $errno];
        }
        if (!is_string($rawBody)) {
            return ['ok' => false, 'status' => $code, 'reason' => 'no_body'];
        }
        $decoded = json_decode($rawBody, true);
        if ($decoded === null && trim($rawBody) !== 'null' && trim($rawBody) !== '') {
            return ['ok' => false, 'status' => $code, 'reason' => 'bad_json'];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'status' => $code, 'reason' => 'http_' . $code, 'data' => $decoded];
        }
        return ['ok' => true, 'status' => $code, 'data' => $decoded];
    }

    /**
     * Best-effort discovery of an OS-level CA bundle. Returns the first
     * readable file from a short list of well-known paths, or null if
     * nothing is found (cURL then falls back to its built-in default —
     * which is exactly what fails on Local-by-Flywheel and friends).
     */
    private static function findCaBundle(): ?string
    {
        foreach (['SSL_CERT_FILE', 'CURL_CA_BUNDLE'] as $envKey) {
            $envPath = getenv($envKey);
            if (is_string($envPath) && $envPath !== '' && is_readable($envPath)) {
                return $envPath;
            }
        }

        $candidates = [
            (string)ini_get('openssl.cafile'),
            (string)ini_get('curl.cainfo'),
            '/etc/ssl/cert.pem',                          // macOS, Alpine
            '/opt/homebrew/etc/openssl@3/cert.pem',       // Homebrew, Apple Silicon
            '/usr/local/etc/openssl@3/cert.pem',          // Homebrew, Intel
            '/etc/ssl/certs/ca-certificates.crt',         // Debian, Ubuntu
            '/etc/pki/tls/certs/ca-bundle.crt',           // RHEL, CentOS, Fedora
        ];
        foreach ($candidates as $path) {
            if ($path !== '' && is_readable($path)) {
                return $path;
            }
        }
        return null;
    }
}
