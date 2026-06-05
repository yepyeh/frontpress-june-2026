<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * High-level email API. Builds RFC-5322 headers + body, delegates to
 * `SmtpTransport` when SMTP credentials are configured, and falls back
 * to PHP `mail()` when configured to do so. Returns a structured result
 * so the admin UI can surface the transport + verbatim error.
 *
 * Plain-text only in v1. HTML support is a feature-flag away (a `$html`
 * param on `send()` sets the right Content-Type) but the bundled
 * contact-form pipeline doesn't need it.
 */
final class Mailer
{
    /**
     * @param array{
     *   smtp_host?:string, smtp_port?:int, smtp_user?:string, smtp_pass?:string,
     *   smtp_encryption?:string, from_address?:string, from_name?:string,
     *   fallback_to_mail?:bool
     * } $cfg
     */
    public function __construct(private array $cfg) {}

    /** True when SMTP host is set — the operator filled out the form. */
    public function isConfigured(): bool
    {
        return ($this->cfg['smtp_host'] ?? '') !== '';
    }

    /**
     * Send a message.
     *
     * If SMTP is configured and `fallback_to_mail` is true, an SMTP
     * failure falls through to `mail()` and the result reports the
     * `mail` transport. Operators can spot this in the UI and fix
     * their SMTP settings rather than relying on the fallback (which
     * usually delivers to spam).
     *
     * @return array{ok: bool, transport: string, error?: string}
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $replyTo = null,
        ?string $fromAddressOverride = null,
        ?string $fromNameOverride = null,
    ): array {
        $from     = $fromAddressOverride ?? trim((string)($this->cfg['from_address'] ?? ''));
        $fromName = $fromNameOverride    ?? trim((string)($this->cfg['from_name']    ?? ''));
        if ($from === '') {
            // Last-resort sender: better than empty, still likely to be filtered.
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $from = 'noreply@' . preg_replace('/[^a-z0-9.\-]/i', '', $host);
        }

        $headers = self::buildHeaders($from, $fromName, $to, $subject, $replyTo);
        $rfc822  = $headers . "\r\n" . self::normalizeBody($body);

        if ($this->isConfigured()) {
            try {
                (new SmtpTransport($this->cfg))->deliver($from, [$to], $rfc822);
                return ['ok' => true, 'transport' => 'smtp'];
            } catch (\Throwable $e) {
                error_log('[fp.mailer] SMTP failed: ' . $e->getMessage());
                if (empty($this->cfg['fallback_to_mail'])) {
                    return ['ok' => false, 'transport' => 'smtp', 'error' => $e->getMessage()];
                }
                // Fall through to mail() ↓
            }
        }

        // PHP mail() needs headers and body separately. Use `\n` line
        // endings (PHP mail() unfolds anyway; keeping CRLF here can
        // trip some sendmail implementations).
        $mailHeaders = str_replace("\r\n", "\n", $headers);
        $ok = @mail($to, $subject, str_replace("\r\n", "\n", self::normalizeBody($body)), $mailHeaders);

        return $ok
            ? ['ok' => true, 'transport' => 'mail']
            : ['ok' => false, 'transport' => 'mail', 'error' => 'PHP mail() returned false (no MTA configured?)'];
    }

    /**
     * Build a compact RFC-5322 header block (no trailing CRLF — caller
     * appends one before the body).
     */
    private static function buildHeaders(
        string $from,
        string $fromName,
        string $to,
        string $subject,
        ?string $replyTo,
    ): string {
        $fromHeader = $fromName !== ''
            ? self::encodeWord($fromName) . ' <' . $from . '>'
            : '<' . $from . '>';

        $lines = [
            'From: '         . $fromHeader,
            'To: '           . '<' . $to . '>',
            'Subject: '      . self::encodeWord($subject),
            'Date: '         . date(\DATE_RFC2822),
            'Message-ID: '   . '<' . bin2hex(random_bytes(12)) . '@' . self::messageIdHost($from) . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: FrontPress Studio',
        ];
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $lines[] = 'Reply-To: <' . $replyTo . '>';
        }
        return implode("\r\n", $lines);
    }

    /**
     * RFC 2047 MIME-encoded-word for header values that may contain
     * non-ASCII (subject lines, display names). Skipped when the value
     * is pure printable-ASCII to keep headers human-readable in dev.
     */
    private static function encodeWord(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function messageIdHost(string $from): string
    {
        $at = strrpos($from, '@');
        if ($at === false) return 'localhost';
        $host = substr($from, $at + 1);
        return preg_replace('/[^A-Za-z0-9._\-]/', '', $host) ?: 'localhost';
    }

    /**
     * Normalise body endings to CRLF (SMTP DATA expects them; mail()
     * tolerates both but we strip them before handing off). Strip any
     * carriage-return-only line endings first to avoid double-CR output.
     */
    private static function normalizeBody(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\r", "\n", $body);
        return str_replace("\n", "\r\n", $body);
    }
}
