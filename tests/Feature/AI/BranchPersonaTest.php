<?php

declare(strict_types=1);

use App\Exceptions\AI\BranchNotDraftReadyException;
use App\Models\Branch;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Services\AI\DraftService;
use App\Services\AI\ModelRouter;
use App\Services\AI\ModelResponse;
use Mockery\MockInterface;

/**
 * Persona / branch-readiness rules for the AI draft path.
 *
 * The DraftService must:
 *   1. Substitute {{ persona_name }}, {{ branch_brand }}, {{ persona_signoff }}
 *      tokens in the template's system_prompt before calling the model
 *   2. Refuse to draft (BranchNotDraftReadyException) when the person's
 *      branch is missing, outreach-disabled, or persona-unset
 *   3. Bypass the guard for inbound auto-reply templates (trigger_event=null)
 */
beforeEach(function () {
    $this->captured = ['system' => null, 'count' => 0];
    $captured =& $this->captured;

    $router = Mockery::mock(ModelRouter::class);
    $router->shouldReceive('call')
        ->andReturnUsing(function ($task, $system, $messages, $max_tokens = null) use (&$captured) {
            $captured['system'] = $system;
            $captured['count']++;
            return new ModelResponse(
                text:          'mock-reply-text',
                model_used:    'claude-haiku-4-5-20251001',
                tokens_input:  100,
                tokens_output: 20,
                cost_cents:    1,
                used_fallback: false,
            );
        });

    $this->app->instance(ModelRouter::class, $router);
});

function makePersonaTemplate(string $promptBody): OutreachTemplate
{
    return OutreachTemplate::create([
        'name'               => 'Persona test ' . uniqid(),
        'trigger_event'      => 'lead_created',
        'channel'            => 'WHATSAPP',
        'system_prompt'      => $promptBody,
        'compliance_rules'   => null,
        'autonomous_enabled' => false,
        'is_active'          => true,
    ]);
}

it('substitutes persona tokens from the person branch into the system prompt', function () {
    $branch = Branch::firstOrCreate(
        ['name' => 'Persona Branch A'],
        [
            'mtr_branch_uuid'      => 'persona-branch-a',
            'is_included'          => true,
            'persona_name'         => 'Jordan',
            'customer_facing_name' => 'QuickTrade.world',
            'outreach_enabled'     => true,
        ],
    );

    $person = Person::factory()->create(['branch_id' => $branch->id]);
    $template = makePersonaTemplate(
        "You are {{ persona_name }} from {{ branch_brand }}. Sign off as {{ persona_signoff }}."
    );

    app(DraftService::class)->draft($person, $template);

    expect($this->captured['count'])->toBe(1);
    expect($this->captured['system'])->toContain('You are Jordan from QuickTrade.world');
    expect($this->captured['system'])->toContain('Sign off as Jordan from QuickTrade.world');
});

it('honours persona_signoff override when set on the branch', function () {
    $branch = Branch::firstOrCreate(
        ['name' => 'Persona Override Branch'],
        [
            'mtr_branch_uuid'      => 'persona-override',
            'is_included'          => true,
            'persona_name'         => 'Chantel',
            'customer_facing_name' => 'Trade With Chantel',
            'persona_signoff'      => 'Chantel — Trade With Chantel',
            'outreach_enabled'     => true,
        ],
    );

    $person = Person::factory()->create(['branch_id' => $branch->id]);
    $template = makePersonaTemplate("Sign off: {{ persona_signoff }}");

    app(DraftService::class)->draft($person, $template);

    expect($this->captured['system'])->toContain('Sign off: Chantel — Trade With Chantel');
});

it('throws missing_branch when person has no branch_id', function () {
    $person = Person::factory()->withoutBranch()->create();
    $template = makePersonaTemplate("Sign off: {{ persona_signoff }}");

    app(DraftService::class)->draft($person, $template);
})->throws(BranchNotDraftReadyException::class);

it('throws outreach_disabled when branch outreach is off', function () {
    $person = Person::factory()->withOutreachDisabledBranch()->create();
    $template = makePersonaTemplate("Sign off: {{ persona_signoff }}");

    try {
        app(DraftService::class)->draft($person, $template);
        $this->fail('Expected BranchNotDraftReadyException');
    } catch (BranchNotDraftReadyException $e) {
        expect($e->reason)->toBe(BranchNotDraftReadyException::REASON_OUTREACH_DISABLED);
    }
});

it('throws persona_unset when branch is outreach-enabled but has no persona_name', function () {
    $branch = Branch::firstOrCreate(
        ['name' => 'No Persona Branch'],
        [
            'mtr_branch_uuid'  => 'no-persona',
            'is_included'      => true,
            'outreach_enabled' => true,
            'persona_name'     => null,
        ],
    );

    $person = Person::factory()->create(['branch_id' => $branch->id]);
    $template = makePersonaTemplate("Sign off: {{ persona_signoff }}");

    try {
        app(DraftService::class)->draft($person, $template);
        $this->fail('Expected BranchNotDraftReadyException');
    } catch (BranchNotDraftReadyException $e) {
        expect($e->reason)->toBe(BranchNotDraftReadyException::REASON_PERSONA_UNSET);
    }
});

it('skips persona guard for inbound auto-reply templates (trigger_event=null)', function () {
    $person = Person::factory()->withoutBranch()->create();
    $template = OutreachTemplate::create([
        'name'               => 'Inbound test ' . uniqid(),
        'trigger_event'      => null, // inbound auto-reply
        'channel'            => 'WHATSAPP',
        'system_prompt'      => 'You are a polite assistant. No tokens here.',
        'compliance_rules'   => null,
        'autonomous_enabled' => true,
        'is_active'          => true,
    ]);

    $draft = app(DraftService::class)->draft($person, $template);
    expect($draft)->not->toBeNull();
});
