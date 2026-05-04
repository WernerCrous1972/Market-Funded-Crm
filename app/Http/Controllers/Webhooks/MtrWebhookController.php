<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * Receiver for MTR CRM webhook events.
 *
 * Phase 1 (test): log the full payload + all headers, return 200.
 * Phase 2: verify signature (once MTR confirms their auth mechanism),
 *          then dispatch ProcessMtrWebhookJob.
 */
class MtrWebhookController extends Controller
{
    public function receive(Request $request): Response
    {
        Log::channel('stack')->info('MTR webhook received', [
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
            'raw'     => $request->getContent(),
        ]);

        return response('OK', 200);
    }
}
