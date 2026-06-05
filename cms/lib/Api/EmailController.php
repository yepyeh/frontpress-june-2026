<?php

declare(strict_types=1);

namespace FrontPress\Api;

defined('FRONTPRESS_BOOT') || exit;

/**
 * Email-related admin endpoints. Currently just:
 *
 *   POST /admin/api/email/test  { to } → tries to send a test message.
 *
 * Uses the same Mailer::send() path as real form submissions, so a
 * green test guarantees the contact form will also deliver. Returns
 * the verbatim SMTP error on failure so the admin UI can surface
 * actionable diagnostics ("AUTH LOGIN failed: 535 5.7.8 …") rather
 * than a generic "send failed" message.
 */
class EmailController
{
    /**
     * @param string[]             $pathParts
     * @param array<string, mixed> $config
     */
    public static function handle(array $pathParts, string $method, array $config): void
    {
        Router::requireAuth();
        Router::requireCsrf();

        $action = $pathParts[0] ?? '';
        if ($method !== 'POST' || $action !== 'test') {
            \json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
        }

        $body = Router::jsonBody();
        $to   = trim((string)($body['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            \json_response(['ok' => false, 'error' => 'Recipient is not a valid email address.'], 400);
        }

        // No isConfigured() gate: an empty smtp_host is a valid "use PHP
        // mail()" setup, and Mailer::send() routes correctly in both modes.
        // If PHP mail() also can't deliver (no MTA on the host), send()
        // surfaces that error to the caller below.
        $mailer = ServiceFactory::mailer($config);

        $subject = 'FrontPress test email';
        $bodyTxt = "If you're reading this, mail delivery works.\n\nSent " . date(\DATE_ATOM);
        $res     = $mailer->send($to, $subject, $bodyTxt);

        ServiceFactory::audit($config)->record('email.test', $to, [
            'ok'        => $res['ok'],
            'transport' => $res['transport'],
        ]);

        \json_response($res, $res['ok'] ? 200 : 502);
    }
}
