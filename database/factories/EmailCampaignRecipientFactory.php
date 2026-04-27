<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailCampaignRecipientFactory extends Factory
{
    protected $model = EmailCampaignRecipient::class;

    public function definition(): array
    {
        return [
            'campaign_id' => EmailCampaign::factory(),
            'person_id'   => Person::factory(),
            'email'         => $this->faker->safeEmail(),
            'first_name'    => null,
            'status'        => 'PENDING',
            'message_id'    => null,
            'sent_at'       => null,
            'failed_at'     => null,
            'error_message' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(['status' => 'SENT', 'sent_at' => now(), 'failed_at' => null, 'error_message' => null]);
    }
}
