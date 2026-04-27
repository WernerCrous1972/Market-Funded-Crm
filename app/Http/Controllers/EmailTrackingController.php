<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailEvent;
use App\Models\EmailUnsubscribe;
use App\Services\Email\CampaignMailer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Handles email tracking pixel, click tracking, and unsubscribe.
 *
 * These routes are PUBLIC (no auth) — they are accessed by email clients
 * and recipients clicking links.
 *
 * Add to routes/web.php:
 *
 *   Route::get('/email/track/open/{recipient}', [EmailTrackingController::class, 'trackOpen'])
 *       ->name('email.track.open');
 *
 *   Route::get('/email/track/click/{recipient}', [EmailTrackingController::class, 'trackClick'])
 *       ->name('email.track.click');
 *
 *   Route::get('/email/unsubscribe/{recipient}', [EmailTrackingController::class, 'unsubscribeForm'])
 *       ->name('email.unsubscribe');
 *
 *   Route::post('/email/unsubscribe/{recipient}', [EmailTrackingController::class, 'unsubscribeConfirm'])
 *       ->name('email.unsubscribe.confirm');
 */
class EmailTrackingController extends Controller
{
    public function __construct(
        private readonly CampaignMailer $mailer,
    ) {}

    /**
     * 1×1 transparent GIF — records an OPENED event.
     */
    public function trackOpen(string $recipient): Response
    {
        $rec = EmailCampaignRecipient::find($recipient);

        if ($rec) {
            // Only record first open to avoid inflating counts
            $alreadyOpened = EmailEvent::where('recipient_id', $rec->id)
                ->where('type', 'OPENED')
                ->exists();

            if (! $alreadyOpened) {
                EmailEvent::create([
                    'campaign_id'  => $rec->campaign_id,
                    'recipient_id' => $rec->id,
                    'person_id'    => $rec->person_id,
                    'type'         => 'OPENED',
                    'ip_address'   => request()->ip(),
                    'user_agent'   => request()->userAgent(),
                    'occurred_at'  => now(),
                ]);

                $rec->campaign()->increment('opened_count');
            }
        }

        // Return 1×1 transparent GIF
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200, [
            'Content-Type'  => 'image/gif',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    /**
     * Click redirect — records a CLICKED event then redirects to destination.
     */
    public function trackClick(string $recipient, Request $request): \Illuminate\Http\RedirectResponse
    {
        $rec = EmailCampaignRecipient::find($recipient);
        $url = $request->query('url', '/');

        if ($rec) {
            EmailEvent::create([
                'campaign_id'  => $rec->campaign_id,
                'recipient_id' => $rec->id,
                'person_id'    => $rec->person_id,
                'type'         => 'CLICKED',
                'url_clicked'  => substr($url, 0, 500),
                'ip_address'   => request()->ip(),
                'user_agent'   => request()->userAgent(),
                'occurred_at'  => now(),
            ]);

            $rec->campaign()->increment('clicked_count');
        }

        return redirect()->away($url);
    }

    /**
     * Unsubscribe confirmation page.
     */
    public function unsubscribeForm(string $recipient, Request $request): \Illuminate\Contracts\View\View
    {
        $rec   = EmailCampaignRecipient::findOrFail($recipient);
        $token = $request->query('token', '');

        // Verify the token to prevent mass-unsubscribing others
        $expectedToken = $this->mailer->unsubscribeToken($rec);
        abort_unless(hash_equals($expectedToken, $token), 403);

        return view('email.unsubscribe', [
            'recipient' => $rec,
            'token'     => $token,
        ]);
    }

    /**
     * Process the unsubscribe.
     */
    public function unsubscribeConfirm(string $recipient, Request $request): \Illuminate\Contracts\View\View
    {
        $rec   = EmailCampaignRecipient::findOrFail($recipient);
        $token = $request->input('token', '');

        $expectedToken = $this->mailer->unsubscribeToken($rec);
        abort_unless(hash_equals($expectedToken, $token), 403);

        // Record unsubscribe
        EmailUnsubscribe::record(
            email: $rec->email,
            reason: 'unsubscribe_link',
            personId: $rec->person_id,
        );

        EmailEvent::create([
            'campaign_id'  => $rec->campaign_id,
            'recipient_id' => $rec->id,
            'person_id'    => $rec->person_id,
            'type'         => 'UNSUBSCRIBED',
            'occurred_at'  => now(),
        ]);

        $rec->campaign()->increment('unsubscribed_count');

        return view('email.unsubscribed');
    }
}
