<?php

declare(strict_types=1);

namespace FrontPress;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Hand-rolled SMTP client. Speaks the subset of RFC 5321 every transactional
 * provider supports: EHLO → STARTTLS → AUTH LOGIN/PLAIN → MAIL FROM →
 * RCPT TO → DATA → end-of-data. No transitive dependencies — uses PHP's
 * built-in `stream_socket_client`.
 *
 * Works with the major providers tested in the wild:
 *   - Postmark:  smtp.postmarkapp.com:587 (server token as user + pass)
 *   - Mailgun:   smtp.mailgun.org:587
 *   - SendGrid:  smtp.sendgrid.net:587   (literal "apikey" as username)
 *   - Amazon SES: email-smtp.<region>.amazonaws.com:587
 *   - Gmail:     smtp.gmail.com:587      (with an app password)
 *   - Outlook:   smtp.office365.com:587
 *   - Any custom relay your hosting / VPS exposes.
 *
 * Does NOT support OAuth2 / XOAUTH2. If you specifically need to
 * authenticate to Gmail without an app password, use a transactional
 * relay (Postmark / Mailgun / SES) instead.
 *
 * Throws RuntimeException on protocol errors. The Mailer wrapper catches
 * those, logs to `error_log`, and decides whether to fall back to `mail()`.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;
    private float $timeout = 15.0;

    /**
     * @param array{
     *   smtp_host:string, smtp_port?:int, smtp_user?:string, smtp_pass?:string,
     *   smtp_encryption?:string, smtp_helo?:string
     * } $cfg
     */
    public function __construct(private array $cfg) {}

    /**
     * Deliver one message to one or more recipients.
     *
     * @param list<string> $rcpts
     * @throws \RuntimeException with the verbatim SMTP error code/message
     */
    public function deliver(string $from, array $rcpts, string $rawMessage): void
    {
        if (empty($this->cfg['smtp_host']) || empty($rcpts)) {
            throw new \RuntimeException('SMTP host / recipients required');
        }

        $host = (string)$this->cfg['smtp_host'];
        $port = (int)($this->cfg['smtp_port'] ?? 587);
        $enc  = (string)($this->cfg['smtp_encryption'] ?? 'tls');
        $helo = (string)($this->cfg['smtp_helo'] ?? gethostname() ?: 'frontpress.local');

        $url = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $this->socket = @stream_socket_client(
            $url,
            $errno,
            $err,
            $this->timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!$this->socket) {
            throw new \RuntimeException(sprintf('SMTP connect %s: %s', $url, $err ?: "errno $errno"));
        }
        stream_set_timeout($this->socket, (int)$this->timeout);

        try {
            $this->expect(220);
            $this->ehlo($helo);

            if ($enc === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto(
                    $this->socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                )) {
                    throw new \RuntimeException('STARTTLS handshake failed');
                }
                // Re-EHLO over the secured channel — some servers reset the
                // capability list after STARTTLS.
                $this->ehlo($helo);
            }

            if (!empty($this->cfg['smtp_user'])) {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode((string)$this->cfg['smtp_user']), 334);
                $this->cmd(base64_encode((string)($this->cfg['smtp_pass'] ?? '')), 235);
            }

            $this->cmd('MAIL FROM:<' . $from . '>', 250);
            foreach ($rcpts as $r) {
                $this->cmd('RCPT TO:<' . $r . '>', [250, 251]);
            }
            $this->cmd('DATA', 354);

            // CRLF-normalise + dot-stuff the body so any line that happens to
            // start with "." gets escaped (RFC 5321 §4.5.2). End with the
            // bare "." terminator.
            $body = preg_replace("/\r?\n/", "\r\n", $rawMessage) ?? $rawMessage;
            $body = preg_replace('/^\\./m', '..', $body) ?? $body;
            $this->write($body . "\r\n.\r\n");
            $this->expect(250);

            // QUIT is best-effort; some servers drop the connection eagerly.
            @$this->cmd('QUIT', 221);
        } finally {
            if (is_resource($this->socket)) @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function ehlo(string $name): void
    {
        // EHLO replies are multi-line (250-FEATURE / 250 LAST). The
        // multi-line reader in `expect()` handles that.
        $this->cmd("EHLO {$name}", 250);
    }

    /**
     * Send one command and assert the response code.
     *
     * @param int|list<int> $expected
     */
    private function cmd(string $line, int|array $expected): void
    {
        $this->write($line . "\r\n");
        $this->expect($expected);
    }

    /**
     * Read one SMTP response (handles multi-line "250-…" continuations)
     * and assert it matches the expected response code(s).
     *
     * @param int|list<int> $expected
     */
    private function expect(int|array $expected): void
    {
        $codes = is_array($expected) ? $expected : [$expected];
        $line  = '';
        do {
            $line = $this->readLine();
            $code = (int)substr($line, 0, 3);
            $cont = (substr($line, 3, 1) === '-');
            if (!$cont) {
                if (!in_array($code, $codes, true)) {
                    throw new \RuntimeException('SMTP ' . trim($line));
                }
                return;
            }
        } while (true);
    }

    private function readLine(): string
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP socket closed unexpectedly');
        }
        $line = @fgets($this->socket, 8192);
        $meta = stream_get_meta_data($this->socket);
        if (!empty($meta['timed_out'])) {
            throw new \RuntimeException('SMTP read timeout');
        }
        if ($line === false || $line === '') {
            throw new \RuntimeException('SMTP server closed the connection');
        }
        return $line;
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP socket closed unexpectedly');
        }
        $written = @fwrite($this->socket, $data);
        if ($written === false || $written < strlen($data)) {
            throw new \RuntimeException('SMTP write failed');
        }
    }
}
