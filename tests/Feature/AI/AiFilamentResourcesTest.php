<?php

declare(strict_types=1);

use App\Filament\Pages\AiOpsPage;
use App\Filament\Resources\AiDraftResource;
use App\Filament\Resources\OutreachInboundMessageResource;
use App\Filament\Resources\OutreachTemplateResource;
use App\Models\OutreachInboundMessage;
use App\Models\WhatsAppMessage;
use App\Models\AiDraft;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\User;

describe('AI outreach Filament resources smoke tests', function () {

    beforeEach(function () {
        // Super admin so we can hit the AI-restricted pages
        $this->actingAs(User::factory()->create([
            'role' => 'ADMIN',
            'is_super_admin' => true,
        ]));
    });

    it('outreach templates index page loads without error', function () {
        $this->get(OutreachTemplateResource::getUrl('index'))
            ->assertOk();
    });

    it('outreach templates create page loads without error', function () {
        $this->get(OutreachTemplateResource::getUrl('create'))
            ->assertOk();
    });

    it('outreach templates edit page loads for an existing template', function () {
        $tpl = OutreachTemplate::create([
            'name'               => 'Smoke test template',
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'You are a test.',
            'autonomous_enabled' => false,
            'is_active'          => true,
        ]);
        $this->get(OutreachTemplateResource::getUrl('edit', ['record' => $tpl->id]))
            ->assertOk();
    });

    it('ai drafts index page loads without error', function () {
        $this->get(AiDraftResource::getUrl('index'))
            ->assertOk();
    });

    it('ai drafts edit page loads for an existing draft', function () {
        $person = Person::factory()->create();
        $tpl    = OutreachTemplate::create([
            'name'               => 'Smoke test template',
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'You are a test.',
            'autonomous_enabled' => false,
            'is_active'          => true,
        ]);
        $draft = AiDraft::create([
            'person_id'    => $person->id,
            'template_id'  => $tpl->id,
            'mode'         => AiDraft::MODE_REVIEWED,
            'channel'      => 'WHATSAPP',
            'model_used'   => 'claude-sonnet-4-6',
            'prompt_hash'  => str_repeat('a', 64),
            'prompt_full'  => 'fake',
            'draft_text'   => 'Hello world.',
            'status'       => AiDraft::STATUS_PENDING_REVIEW,
            'tokens_input' => 100,
            'tokens_output' => 50,
            'cost_cents'   => 5,
        ]);

        $this->get(AiDraftResource::getUrl('edit', ['record' => $draft->id]))
            ->assertOk();
    });

    it('ai ops page loads without error', function () {
        $this->get(AiOpsPage::getUrl())
            ->assertOk();
    });

    it('ai ops page denies non-super-admins', function () {
        // Switch to a non-super-admin user
        auth()->logout();
        $this->actingAs(User::factory()->create([
            'role' => 'SALES_AGENT',
            'is_super_admin' => false,
        ]));

        $this->get(AiOpsPage::getUrl())
            ->assertForbidden();
    });

    it('outreach templates index denies non-super-admins', function () {
        auth()->logout();
        $this->actingAs(User::factory()->create([
            'role' => 'SALES_AGENT',
            'is_super_admin' => false,
        ]));

        $this->get(OutreachTemplateResource::getUrl('index'))
            ->assertForbidden();
    });

    it('inbound messages index page loads without error', function () {
        $this->get(OutreachInboundMessageResource::getUrl('index'))
            ->assertOk();
    });

    it('inbound messages index renders rows when present', function () {
        $person = Person::factory()->create();
        $msg = WhatsAppMessage::create([
            'person_id'     => $person->id,
            'direction'     => 'INBOUND',
            'wa_message_id' => 'wamid.test_smoke',
            'body_text'     => 'thanks!',
            'status'        => 'RECEIVED',
        ]);
        OutreachInboundMessage::create([
            'whatsapp_message_id' => $msg->id,
            'person_id'           => $person->id,
            'intent'              => 'acknowledgment',
            'confidence'          => 90,
            'routing'             => OutreachInboundMessage::ROUTING_AUTO_REPLIED,
            'created_at'          => now(),
        ]);

        $this->get(OutreachInboundMessageResource::getUrl('index'))
            ->assertOk();
    });

});
