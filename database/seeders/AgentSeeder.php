<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Seeder;

class AgentSeeder extends Seeder
{
    public function run(): void
    {
        $agents = [
            ['key' => 'education',  'name' => 'Education Agent',  'department' => 'EDUCATION'],
            ['key' => 'deposits',   'name' => 'Deposits Agent',   'department' => 'DEPOSITS'],
            ['key' => 'challenges', 'name' => 'Challenges Agent', 'department' => 'CHALLENGES'],
            ['key' => 'support',    'name' => 'Support Agent',    'department' => 'SUPPORT'],
            ['key' => 'onboarding', 'name' => 'Onboarding Agent', 'department' => 'ONBOARDING'],
            ['key' => 'retention',  'name' => 'Retention Agent',  'department' => 'RETENTION'],
            ['key' => 'nurturing',  'name' => 'Nurturing Agent',  'department' => 'NURTURING'],
            ['key' => 'general',    'name' => 'General Agent',    'department' => 'GENERAL'],
        ];

        foreach ($agents as $data) {
            Agent::updateOrCreate(
                ['key' => $data['key']],
                [
                    'name'          => $data['name'],
                    'department'    => $data['department'],
                    'system_prompt' => null,
                    'is_active'     => true,
                ]
            );
        }
    }
}
