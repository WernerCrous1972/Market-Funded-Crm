<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailCampaign;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailCampaignFactory extends Factory
{
    protected $model = EmailCampaign::class;

    public function definition(): array
    {
        return [
            'name'             => $this->faker->sentence(4),
            'email_template_id' => EmailTemplate::factory(),
            'subject_override' => null,
            'from_name'        => 'Market Funded',
            'from_email'       => 'info@market-funded.com',
            'recipient_mode'   => 'FILTER',
            'recipient_filter_key' => 'all_clients',
            'recipient_manual_ids' => null,
            'status'           => 'DRAFT',
            'recipient_count'  => 0,
            'sent_count'       => 0,
            'opened_count'     => 0,
            'clicked_count'    => 0,
            'bounced_count'    => 0,
            'unsubscribed_count' => 0,
            'scheduled_at'     => null,
            'completed_at'     => null,
        ];
    }

    public function sent(): static
    {
        return $this->state([
            'status'       => 'SENT',
            'completed_at' => now(),
        ]);
    }
}
