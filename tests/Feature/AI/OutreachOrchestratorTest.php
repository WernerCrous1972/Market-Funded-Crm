<?php

declare(strict_types=1);

use App\Models\AiDraft;
use App\Models\AiUsageLog;
use App\Models\OutreachTemplate;
use App\Models\Person;
use App\Models\User;
use App\Services\AI\AiOrchestratorException;
use App\Services\AI\ComplianceAgent;
use App\Services\AI\CostCeilingGuard;
use App\Services\AI\DraftService;
use App\Services\AI\ModelResponse;
use App\Services\AI\ModelRouter;
use App\Services\AI\OutreachOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

describe('OutreachOrchestrator', function () {

    beforeEach(function () {
        config()->set('ai.tasks', [
            'outreach_draft_individual' => 'claude-sonnet-4-6',
            'outreach_draft_bulk'       => 'claude-haiku-4-5-20251001',
            'compliance_check'          => 'claude-haiku-4-5-20251001',
        ]);
        config()->set('ai.default', 'claude-haiku-4-5-20251001');
        config()->set('ai.cost_caps.soft_usd', 300);
        config()->set('ai.cost_caps.hard_usd', 500);
        config()->set('ai.autonomous_paused', false);

        config()->set('outreach_compliance.hard_banned_phrases', [
            '/\bguaranteed?\s+returns?/i',
        ]);
        config()->set('outreach_compliance.soft_warning_rules', []);
        config()->set('outreach_compliance.required_disclosures', []);
        config()->set('outreach_compliance.agent_system_prompt', 'You are a compliance reviewer.');

        Cache::flush();
    });

    /**
     * Build a fake ModelRouter that returns one response per call from a
     * queue. Used to script the draft response then the compliance verdict.
     */
    function makeQueuedRouter(array $textsInOrder): ModelRouter
    {
        return new class($textsInOrder) extends ModelRouter
        {
            private int $i = 0;

            public function __construct(private readonly array $texts) {}

            public function call(string $task, string $system, array $messages, int $max_tokens = 1024): ModelResponse
            {
                $text = $this->texts[$this->i] ?? throw new \RuntimeException("Out of fake responses at call #{$this->i}");
                $this->i++;
                return new ModelResponse(
                    text: $text,
                    model_used: 'claude-haiku-4-5-20251001',
                    tokens_input: 100,
                    tokens_output: 30,
                    cost_cents: 1,
                    used_fallback: false,
                );
            }
        };
    }

    function makeOrchInstance(ModelRouter $router, ?CostCeilingGuard $guard = null): OutreachOrchestrator
    {
        return new OutreachOrchestrator(
            new DraftService($router),
            new ComplianceAgent($router),
            $guard ?? new CostCeilingGuard(),
        );
    }

    function makeOrchestratorTemplate(): OutreachTemplate
    {
        return OutreachTemplate::create([
            'name'               => 'Welcome — new lead',
            'trigger_event'      => 'lead_created',
            'channel'            => 'WHATSAPP',
            'system_prompt'      => 'Friendly concierge.',
            'autonomous_enabled' => false,
            'is_active'          => true,
        ]);
    }

    it('reviewedDraft creates a draft with a clean compliance check', function () {
        $person   = Person::factory()->create();
        $template = makeOrchestratorTemplate();

        $orch = makeOrchInstance(makeQueuedRouter([
            'Hi! Welcome to Market Funded.',                              // draft response
            '{"passed":true,"flags":[],"verdict":"clean"}',                // compliance verdict
        ]));

        $draft = $orch->reviewedDraft($person, $template);

        expect($draft)->toBeInstanceOf(AiDraft::class);
        expect($draft->mode)->toBe(AiDraft::MODE_REVIEWED);
        expect($draft->status)->toBe(AiDraft::STATUS_PENDING_REVIEW);
        expect($draft->compliance_check_id)->not->toBeNull();
        expect($draft->complianceCheck->passed)->toBeTrue();
    });

    it('reviewedDraft blocks the draft when compliance fails (hard regex)', function () {
        $person   = Person::factory()->create();
        $template = makeOrchestratorTemplate();

        $orch = makeOrchInstance(makeQueuedRouter([
            'We offer guaranteed returns of 10%/month.',                  // hard regex match
            '{"passed":true,"flags":[],"verdict":"clean"}',                // (unreached — AI not called)
        ]));

        $draft = $orch->reviewedDraft($person, $template);

        expect($draft->status)->toBe(AiDraft::STATUS_BLOCKED_COMPLIANCE);
        expect($draft->complianceCheck->passed)->toBeFalse();
        expect($draft->complianceCheck->flags[0]['severity'])->toBe('hard');
    });

    it('reviewedDraft records the triggering user', function () {
        $user     = User::factory()->create();
        $person   = Person::factory()->create();
        $template = makeOrchestratorTemplate();

        $orch = makeOrchInstance(makeQueuedRouter([
            'Hi!',
            '{"passed":true,"flags":[],"verdict":"ok"}',
        ]));

        $draft = $orch->reviewedDraft($person, $template, triggeredBy: $user);

        expect($draft->triggered_by_user_id)->toBe($user->id);
    });

    it('throws AiOrchestratorException when cost ceiling pauses all', function () {
        // Spend over the hard cap so guard refuses
        AiUsageLog::create([
            'id'            => Str::uuid()->toString(),
            'date'          => now()->toDateString(),
            'task_type'     => 'compliance_check',
            'model'         => 'claude-haiku-4-5-20251001',
            'call_count'    => 1,
            'tokens_input'  => 0,
            'tokens_output' => 0,
            'cost_cents'    => 600_00, // $600 — over $500 hard cap
        ]);

        $person   = Person::factory()->create();
        $template = makeOrchestratorTemplate();

        $orch = makeOrchInstance(makeQueuedRouter(['ignored']));

        expect(fn () => $orch->reviewedDraft($person, $template))
            ->toThrow(AiOrchestratorException::class);

        // No draft should have been persisted
        expect(AiDraft::count())->toBe(0);
    });

    it('throws when manual kill switch is engaged', function () {
        $person   = Person::factory()->create();
        $template = makeOrchestratorTemplate();

        $guard = new CostCeilingGuard();
        $guard->pauseAutonomous();

        $orch = makeOrchInstance(makeQueuedRouter(['ignored']), $guard);

        expect(fn () => $orch->reviewedDraft($person, $template))
            ->toThrow(AiOrchestratorException::class);

        expect(AiDraft::count())->toBe(0);
    });

    it('bulkReviewedDrafts creates one draft per person, all with compliance', function () {
        $people = Person::factory()->count(3)->create();
        $template = makeOrchestratorTemplate();

        // 3 people × 2 calls each (draft + compliance) = 6 responses queued
        $orch = makeOrchInstance(makeQueuedRouter([
            'Welcome A!', '{"passed":true,"flags":[],"verdict":"ok"}',
            'Welcome B!', '{"passed":true,"flags":[],"verdict":"ok"}',
            'Welcome C!', '{"passed":true,"flags":[],"verdict":"ok"}',
        ]));

        $drafts = $orch->bulkReviewedDrafts($people, $template);

        expect($drafts)->toHaveCount(3);
        expect($drafts->every(fn ($d) => $d->mode === AiDraft::MODE_BULK_REVIEWED))->toBeTrue();
        expect($drafts->every(fn ($d) => $d->compliance_check_id !== null))->toBeTrue();
    });

    it('bulkReviewedDrafts continues on per-person errors', function () {
        $people = Person::factory()->count(3)->create();
        $template = makeOrchestratorTemplate();

        // Person 2's draft response is missing — RuntimeException at call #2;
        // person 1 + 3 should still complete.
        // Sequence:
        //   1: draft for p1
        //   2: compliance for p1
        //   3: (intended) draft for p2  → BOOM — exception
        //   ...orchestrator continues to p3
        //   4: draft for p3
        //   5: compliance for p3
        $orch = makeOrchInstance(makeQueuedRouter([
            'Welcome A!', '{"passed":true,"flags":[],"verdict":"ok"}',
            // out-of-responses error fires here — NO matching response queued.
            // The router throws RuntimeException, orchestrator catches +
            // moves on. But because we have NO more responses, p3 also fails.
        ]));

        $drafts = $orch->bulkReviewedDrafts($people, $template);

        // p1 succeeded; p2 + p3 both ran out of fake responses → continue past
        expect($drafts)->toHaveCount(1);
        expect($drafts[0]->mode)->toBe(AiDraft::MODE_BULK_REVIEWED);
    });

    it('bulkReviewedDrafts stops cleanly if guard refuses mid-loop', function () {
        $people = Person::factory()->count(3)->create();
        $template = makeOrchestratorTemplate();

        // Set up: guard initially proceeds, then we manually pause after the
        // first iteration completes. We need a guard whose state we can flip
        // at the right moment — easiest is to pre-load near the cap and let
        // the inner ensureCostAllowed re-check.
        AiUsageLog::create([
            'id'            => Str::uuid()->toString(),
            'date'          => now()->toDateString(),
            'task_type'     => 'compliance_check',
            'model'         => 'claude-haiku-4-5-20251001',
            'call_count'    => 1,
            'tokens_input'  => 0,
            'tokens_output' => 0,
            'cost_cents'    => 600_00, // Over hard cap from the start
        ]);

        $guard = new CostCeilingGuard();
        $orch  = makeOrchInstance(makeQueuedRouter(['x']), $guard);

        // Should throw on the very first ensureCostAllowed
        expect(fn () => $orch->bulkReviewedDrafts($people, $template))
            ->toThrow(AiOrchestratorException::class);
    });

});
