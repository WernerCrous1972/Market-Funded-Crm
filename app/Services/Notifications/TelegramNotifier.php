<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    private const VALID_SEVERITIES = ['info', 'warning', 'alert', 'critical'];

    public function __construct(
        private readonly ?GuzzleClient $http = null,
    ) {}

    /**
     * Send a notification to Werner's Telegram. Returns true on success.
     *
     * Failures are logged but never thrown — callers should never have their
     * primary work interrupted by a notification failure.
     */
    public function notify(string $message, string $severity = 'info'): bool
    {
        if (! $this->isConfigured()) {
            Log::debug('TelegramNotifier: skipped (not configured)', ['severity' => $severity]);
            return false;
        }

        if (! (bool) config('notifications.telegram.enabled')) {
            Log::debug('TelegramNotifier: skipped (disabled)', ['severity' => $severity]);
            return false;
        }

        $severity = in_array($severity, self::VALID_SEVERITIES, true) ? $severity : 'info';
        $emoji    = (string) (config("notifications.severities.{$severity}") ?? 'ℹ️');
        $prefix   = (string) config('notifications.telegram.message_prefix');
        $body     = trim("{$emoji} {$prefix} {$message}");

        $token  = (string) config('notifications.telegram.bot_token');
        $chatId = (string) config('notifications.telegram.chat_id');

        try {
            // Absolute URL — relative paths break on the colon in the bot token.
            $response = $this->client()->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $body,
                    'parse_mode' => 'HTML',
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true) ?? [];

            if (! ($data['ok'] ?? false)) {
                Log::warning('TelegramNotifier: API responded not-ok', [
                    'severity' => $severity,
                    'response' => $data,
                ]);
                return false;
            }

            return true;

        } catch (GuzzleException $e) {
            Log::warning('TelegramNotifier: send failed', [
                'severity' => $severity,
                'error'    => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function isReachable(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $token    = (string) config('notifications.telegram.bot_token');
            $response = $this->client()->get("https://api.telegram.org/bot{$token}/getMe");
            $data     = json_decode((string) $response->getBody(), true) ?? [];
            return (bool) ($data['ok'] ?? false);
        } catch (GuzzleException) {
            return false;
        }
    }

    private function isConfigured(): bool
    {
        return ! empty(config('notifications.telegram.bot_token'))
            && ! empty(config('notifications.telegram.chat_id'));
    }

    private function client(): GuzzleClient
    {
        // No base_uri here — bot tokens contain a colon, which Guzzle treats as
        // a port separator when resolving relative paths. We pass absolute URLs
        // at every call site instead.
        return $this->http ?? new GuzzleClient([
            'timeout' => (int) config('notifications.telegram.timeout_seconds', 5),
        ]);
    }
}
