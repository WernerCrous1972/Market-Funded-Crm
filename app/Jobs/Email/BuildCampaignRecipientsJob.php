<?php

declare(strict_types=1);

namespace App\Jobs\Email;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailUnsubscribe;
use App\Models\Person;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds the recipient list for a campaign, then dispatches SendCampaignEmailJob.
 *
 * Handles both FILTER and MANUAL recipient modes.
 * Respects unsubscribe list and deduplicates.
 */
class BuildCampaignRecipientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;
    public int $timeout = 300;

    private const FILTER_MAP = [
        'at_risk' => [
            'label' => 'At-Risk Clients (score < 40)',
            'query' => 'applyAtRiskFilter',
        ],
        'critical' => [
            'label' => 'Critical Clients (score < 20)',
            'query' => 'applyCriticalFilter',
        ],
        'unconverted_leads' => [
            'label' => 'Unconverted Leads 7d+',
            'query' => 'applyUnconvertedLeadsFilter',
        ],
        'dormant_with_equity' => [
            'label' => 'Dormant Clients (10d+ no login)',
            'query' => 'applyDormantFilter',
        ],
        'all_clients' => [
            'label' => 'All Clients',
            'query' => 'applyAllClientsFilter',
        ],
        'all_mfu_capital' => [
            'label' => 'All MFU Capital Clients',
            'query' => 'applyCapitalFilter',
        ],
        'all_mfu_markets' => [
            'label' => 'All MFU Markets Clients',
            'query' => 'applyMarketsFilter',
        ],
        'all_leads' => [
            'label' => 'All Leads',
            'query' => 'applyAllLeadsFilter',
        ],
    ];

    public function __construct(
        private readonly string $campaignId,
    ) {}

    public function handle(): void
    {
        $campaign = EmailCampaign::findOrFail($this->campaignId);

        Log::info('BuildCampaignRecipientsJob starting', ['campaign_id' => $this->campaignId]);

        $personIds = collect();

        if (in_array($campaign->recipient_mode, ['FILTER', 'COMBINED'], true)) {
            $filterKey = $campaign->recipient_filter_key;
            if ($filterKey && isset(self::FILTER_MAP[$filterKey])) {
                $method    = self::FILTER_MAP[$filterKey]['query'];
                $filterIds = $this->{$method}()->pluck('id');
                $personIds = $personIds->merge($filterIds);
            }
        }

        if (in_array($campaign->recipient_mode, ['MANUAL', 'COMBINED'], true)) {
            $manualIds = $campaign->recipient_manual_ids ?? [];
            $personIds = $personIds->merge($manualIds);
        }

        $personIds = $personIds->unique()->values();

        if ($personIds->isEmpty()) {
            Log::warning('BuildCampaignRecipientsJob: no recipients found', ['campaign_id' => $this->campaignId]);
            $campaign->update(['status' => 'FAILED']);
            return;
        }

        $unsubscribedEmails = EmailUnsubscribe::pluck('email')->flip();

        $rows = Person::whereIn('id', $personIds)
            ->whereNotNull('email')
            ->get(['id', 'email', 'first_name'])
            ->filter(fn ($p) => ! isset($unsubscribedEmails[strtolower($p->email)]))
            ->map(fn ($p) => [
                'id'          => \Illuminate\Support\Str::uuid()->toString(),
                'campaign_id' => $campaign->id,
                'person_id'   => $p->id,
                'email'       => strtolower($p->email),
                'first_name'  => $p->first_name,
                'status'      => 'PENDING',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('email_campaign_recipients')->insertOrIgnore($chunk->values()->all());
        }

        $count = $rows->count();

        $campaign->update([
            'status'          => 'SENDING',
            'recipient_count' => $count,
            'started_at'      => now(),
        ]);

        Log::info('BuildCampaignRecipientsJob built recipients', [
            'campaign_id' => $this->campaignId,
            'count'       => $count,
        ]);

        EmailCampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'PENDING')
            ->chunkById(100, function ($chunk) use ($campaign) {
                foreach ($chunk as $recipient) {
                    dispatch(new SendCampaignEmailJob($campaign->id, $recipient->id))
                        ->delay(now()->addSeconds(1));
                }
            });
    }

    private function applyAtRiskFilter(): Builder
    {
        return Person::where('contact_type', 'CLIENT')
            ->whereHas('metrics', fn ($q) => $q->whereNotNull('health_score')->where('health_score', '<', 40));
    }

    private function applyCriticalFilter(): Builder
    {
        return Person::where('contact_type', 'CLIENT')
            ->whereHas('metrics', fn ($q) => $q->whereNotNull('health_score')->where('health_score', '<', 20));
    }

    private function applyUnconvertedLeadsFilter(): Builder
    {
        return Person::where('contact_type', 'LEAD')
            ->where('mtr_created_at', '<', now()->subDays(7))
            ->whereHas('metrics', fn ($q) => $q->where('deposit_count', 0));
    }

    private function applyDormantFilter(): Builder
    {
        return Person::where('contact_type', 'CLIENT')
            ->whereHas('metrics', fn ($q) => $q->where('days_since_last_login', '>', 10)->where('net_deposits_cents', '>', 500_000));
    }

    private function applyAllClientsFilter(): Builder
    {
        return Person::where('contact_type', 'CLIENT');
    }

    private function applyCapitalFilter(): Builder
    {
        return Person::where('contact_type', 'CLIENT')
            ->whereHas('metrics', fn ($q) => $q->where('has_capital', true));
    }

    private function applyMarketsFilter(): Builder
    {
        return Person::where('contact_type', 'CLIENT')
            ->whereHas('metrics', fn ($q) => $q->where('has_markets', true));
    }

    private function applyAllLeadsFilter(): Builder
    {
        return Person::where('contact_type', 'LEAD');
    }

    public static function filterLabel(string $key): string
    {
        return self::FILTER_MAP[$key]['label'] ?? $key;
    }

    public static function filterOptions(): array
    {
        return collect(self::FILTER_MAP)
            ->mapWithKeys(fn ($v, $k) => [$k => $v['label']])
            ->toArray();
    }
}
