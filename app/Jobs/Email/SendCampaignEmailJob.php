<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Services\Email\CampaignMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60;
    public int $timeout = 60;

    public function __construct(
        private readonly string $campaignId,
        private readonly string $recipientId,
    ) {}

    public function handle(CampaignMailer $mailer): void
    {
        $campaign  = EmailCampaign::find($this->campaignId);
        $recipient = EmailCampaignRecipient::with('person')->find($this->recipientId);

        if (! $campaign || ! $recipient) return;

        $mailer->send($campaign, $recipient);

        $pending = EmailCampaignRecipient::where('campaign_id', $this->campaignId)
            ->where('status', 'PENDING')
            ->count();

        if ($pending === 0) {
            $campaign->update([
                'status'       => 'SENT',
                'completed_at' => now(),
            ]);
        }
    }
}
