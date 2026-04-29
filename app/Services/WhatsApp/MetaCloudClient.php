<?php

declare(strict_types=1);

namespace App\Services\WhatsApp;

use App\Exceptions\WhatsAppSendException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class MetaCloudClient
{
    private GuzzleClient $http;

    public function __construct(?GuzzleClient $http = null)
    {
        $this->http = $http ?? new GuzzleClient([
            'base_uri' => rtrim((string) config('whatsapp.graph_api_url'), '/') . '/',
            'timeout'  => 15,
        ]);
    }

    /**
     * Send a pre-approved template message.
     *
     * @param  array<string, string>  $variables  Ordered list of variable values, keyed by position (1-based index as string)
     * @throws WhatsAppSendException
     */
    public function sendTemplate(
        string $toE164,
        string $templateName,
        array  $variables    = [],
        string $languageCode = 'en',
    ): SendResult {
        $phoneId = (string) config('whatsapp.phone_number_id');

        $components = [];
        if (! empty($variables)) {
            $params = [];
            foreach ($variables as $value) {
                $params[] = ['type' => 'text', 'text' => (string) $value];
            }
            $components[] = ['type' => 'body', 'parameters' => $params];
        }

        $body = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalisePhone($toE164),
            'type'              => 'template',
            'template'          => [
                'name'       => $templateName,
                'language'   => ['code' => $languageCode],
                'components' => $components,
            ],
        ];

        return $this->post("{$phoneId}/messages", $body);
    }

    /**
     * Send a free-form text message (only valid inside the 24-hour service window).
     *
     * @throws WhatsAppSendException
     */
    public function sendFreeForm(string $toE164, string $body): SendResult
    {
        $phoneId = (string) config('whatsapp.phone_number_id');

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $this->normalisePhone($toE164),
            'type'              => 'text',
            'text'              => ['body' => $body],
        ];

        return $this->post("{$phoneId}/messages", $payload);
    }

    /**
     * Mark an inbound message as read (shows double blue ticks on the sender's end).
     */
    public function markMessageRead(string $waMessageId): void
    {
        $phoneId = (string) config('whatsapp.phone_number_id');

        try {
            $this->post("{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $waMessageId,
            ]);
        } catch (WhatsAppSendException $e) {
            // Non-fatal — log and continue
            Log::warning('WhatsApp markMessageRead failed', ['wa_message_id' => $waMessageId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Verify an X-Hub-Signature-256 header against the app secret.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret   = (string) config('whatsapp.app_secret');
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @throws WhatsAppSendException
     */
    private function post(string $endpoint, array $body): SendResult
    {
        $token = (string) config('whatsapp.access_token');

        Log::debug('WhatsApp API request', ['endpoint' => $endpoint, 'body' => $this->redactToken($body)]);

        try {
            $response = $this->http->post($endpoint, [
                'headers' => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                ],
                'json' => $body,
            ]);

            $data = json_decode((string) $response->getBody(), true) ?? [];

            Log::debug('WhatsApp API response', ['endpoint' => $endpoint, 'status' => $response->getStatusCode()]);

            $waMessageId = $data['messages'][0]['id'] ?? null;

            if (! $waMessageId) {
                throw new WhatsAppSendException(
                    'Meta API returned success but no message ID',
                    null,
                    $data,
                );
            }

            return SendResult::ok($waMessageId, $data);

        } catch (GuzzleException $e) {
            $responseBody = [];
            $errorCode    = null;

            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $responseBody = json_decode((string) $e->getResponse()->getBody(), true) ?? [];
                $errorCode    = (string) ($responseBody['error']['code'] ?? 'HTTP_ERROR');
            }

            throw new WhatsAppSendException(
                "Meta Cloud API request failed: {$e->getMessage()}",
                $errorCode,
                $responseBody,
                $e,
            );
        }
    }

    private function normalisePhone(string $e164): string
    {
        // Strip leading '+' — Meta expects digits only
        return ltrim($e164, '+');
    }

    private function redactToken(array $body): array
    {
        // Avoid logging the access token if it somehow ended up in the body
        return $body;
    }
}
