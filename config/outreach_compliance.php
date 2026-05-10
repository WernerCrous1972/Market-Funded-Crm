<?php

declare(strict_types=1);

/**
 * Compliance rules for outbound AI-generated messages.
 *
 * Two layers — both run on every draft via `ComplianceAgent`:
 *
 *   1. Hard regex blocklist (this file) — runs FIRST. Any match fails
 *      compliance and blocks the send. No AI involvement.
 *
 *   2. AI self-check — runs AFTER. A Haiku call evaluates the draft against
 *      the prompt rules in this file plus per-template rules (if set on
 *      `outreach_templates.compliance_rules`). Returns flags, soft warnings,
 *      and an overall pass/fail.
 *
 * v1 grounded in financial promotion norms common to FSCA/FAIS (ZA),
 * FCA (UK), MiFID (EU) and CFTC (US). Werner reviews + tunes over time.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Hard banned phrases (regex, case-insensitive)
    |--------------------------------------------------------------------------
    |
    | Any match → compliance fail → message NOT sent. These are language we
    | can't lawfully use in financial promotions even with disclaimers. Each
    | regex is tested against the rendered draft text (post variable substitution).
    |
    */

    'hard_banned_phrases' => [
        // Guarantee language
        '/\bguaranteed?\s+(returns?|profits?|income|gains?|wins?|success)/i',
        '/\b(zero|no)[-\s]?risk\b/i',
        '/\brisk[-\s]?free\b/i',
        '/\bcan(?:\'|no)?t\s+lose\b/i',
        '/\b100%\s+(safe|guaranteed|win|profit|success)/i',

        // Specific return promises (numbers + return language)
        '/\b\d+%\s+(monthly|weekly|daily|annual|guaranteed)\s+(returns?|profits?|gains?)/i',
        '/\bwill\s+make\s+you\s+\$?\d+/i',
        '/\bdouble\s+your\s+(money|deposit|capital)/i',
        '/\btriple\s+your\s+(money|deposit|capital)/i',

        // Get-rich / hype language
        '/\bget[-\s]?rich(?:[-\s]quick)?\b/i',
        '/\bmake\s+millions\b/i',
        '/\beasy\s+money\b/i',
        '/\bquick\s+(cash|profits?)\b/i',

        // Pressure / urgency manipulation
        '/\blast\s+chance\b/i',
        '/\bact\s+now\s+or\s+lose\b/i',
        '/\blimited\s+spots?\s+left/i',  // sometimes legit; flagged here pending Werner review

        // Impersonation of regulators
        '/\b(fsca|fais|fca|sec|cftc|esma)[\s-]?(?:approved|endorsed|certified)/i',
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft warning rules (descriptions for the AI checker)
    |--------------------------------------------------------------------------
    |
    | The compliance agent reads these descriptions and flags drafts that
    | match — but soft flags don't block the send, they just get logged on
    | `ai_compliance_checks.flags`. Werner reviews them in the AI Ops queue.
    |
    | Plain-language so the AI can reason about them; not regex.
    |
    */

    'soft_warning_rules' => [
        'unhedged_performance_claim'
            => 'Claims about past or expected returns without acknowledging risk of loss.',
        'urgency_pressure'
            => 'Time-pressure tactics that could induce rushed decisions ("only 24 hours", "immediate action required").',
        'unsuitable_audience_inference'
            => 'Framing implying the product is suitable for a specific person without knowing their financial circumstances.',
        'missing_risk_disclaimer'
            => 'Financial-promotion content (returns, deposits, leverage) without a brief risk warning. Required for MFU_MARKETS messages.',
        'aggressive_recovery_language'
            => 'For losing-trader re-engagement: language that pushes recovery psychology ("win back what you lost", "double or nothing").',
        'unverified_credential_claim'
            => 'Claims about credentials, awards, or partnerships that should be verifiable but are not stated as such.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Required disclosures (per pipeline)
    |--------------------------------------------------------------------------
    |
    | When a template targets a pipeline below, the compliance agent checks
    | the draft INCLUDES at least one phrase reasonably matching the
    | description. Missing → soft warning (logged, doesn't block).
    |
    | Phrases here are guidance for the AI checker, not literal strings; the
    | agent may match equivalents.
    |
    */

    'required_disclosures' => [
        'MFU_MARKETS' => [
            'risk_warning' => 'A brief acknowledgment that trading carries risk of loss (e.g. "trading involves risk", "you can lose more than you deposit", "CFDs are high-risk").',
        ],
        'MFU_CAPITAL' => [
            'evaluation_disclaimer' => 'For prop challenge messages, an acknowledgment that passing requires meeting specific rules (drawdown, profit target, time limits).',
        ],
        // MFU_ACADEMY has no required risk disclosure for educational content
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance agent system prompt
    |--------------------------------------------------------------------------
    |
    | The Haiku call runs with this as its system prompt. It receives the
    | rendered draft + the template's pipeline + the per-template rules and
    | returns a JSON object with `passed`, `flags[]`, `verdict`.
    |
    */

    'agent_system_prompt' => <<<'PROMPT'
You are a compliance reviewer for a financial brokerage CRM. You audit AI-drafted outbound messages against regulatory norms (FSCA/FAIS, FCA, MiFID, CFTC) before they're sent to leads or clients.

Your job:
1. Read the DRAFT.
2. Apply the BANNED PATTERNS — any match means hard fail.
3. Apply the SOFT WARNINGS — any match adds a flag but does not block.
4. Check REQUIRED DISCLOSURES for the pipeline; missing ones add a soft warning.
5. Return a single JSON object, no prose:
   {
     "passed": <boolean — true unless a hard pattern matched>,
     "flags": [
       {"rule": "<rule name>", "severity": "hard|soft", "excerpt": "<the offending phrase, ≤80 chars>"}
     ],
     "verdict": "<one-sentence summary>"
   }

Be strict on hard patterns, lenient on style. Prefer false positives on soft warnings (a human will review). Never invent rules not in the input.
PROMPT,

];
