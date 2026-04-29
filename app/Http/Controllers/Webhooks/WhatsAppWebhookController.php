<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\WhatsApp\ProcessWhatsAppWebhookJob;
use App\Services\WhatsApp\MetaCloudClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Handles Meta's WhatsApp Cloud API webhook.
 *
 * Routes (registered in routes/web.php, exempt from CSRF):
 *   GET  /webhooks/whatsapp  → verificationChallenge()  (Meta verification handshake)
 *   POST /webhooks/whatsapp  → receive()                (incoming events)
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly MetaCloudClient $client,
    ) {}

    /**
     * Meta sends a GET with hub.mode=subscribe + hub.verify_token + hub.challenge.
     * We must echo back hub.challenge if the verify token matches.
     */
    public function verificationChallenge(Request $request): Response
    {
        $mode        = $request->query('hub_mode',         $request->query('hub.mode'));
        $token       = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge   = $request->query('hub_challenge',    $request->query('hub.challenge'));

        $expectedToken = (string) config('whatsapp.webhook_verify_token');

        if ($mode === 'subscribe' && hash_equals($expectedToken, (string) $token)) {
            Log::info('WhatsApp webhook verified');
            return response((string) $challenge, 200);
        }

        Log::warning('WhatsApp webhook verification failed', ['mode' => $mode]);
        return response('Forbidden', 403);
    }

    /**
     * Meta sends POST for all events (messages, status updates, etc.).
     * We must respond 200 immediately — process async via job.
     */
    public function receive(Request $request): Response
    {
        $rawBody  = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! $this->client->verifyWebhookSignature($rawBody, $signature)) {
            Log::warning('WhatsApp webhook signature mismatch', [
                'ip' => $request->ip(),
            ]);
            return response('Unauthorized', 401);
        }

        $payload = json_decode($rawBody, true) ?? [];

        if (empty($payload)) {
            return response('OK', 200);
        }

        ProcessWhatsAppWebhookJob::dispatch($payload);

        return response('OK', 200);
    }
}
