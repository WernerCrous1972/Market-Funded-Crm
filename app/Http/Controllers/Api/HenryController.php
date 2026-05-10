<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Person;
use App\Models\PersonMetric;
use App\Models\Transaction;
use App\Services\AI\CostCeilingGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Read-mostly endpoints exposed for Henry's MCP server.
 *
 * Henry calls these via the OpenClaw gateway's tools/invoke pipeline; the
 * HenryApiToken middleware verifies the shared secret on the way in.
 *
 * Phase 4a scope: high-level book metrics + person search + person summary.
 * Action endpoints (pause autonomy, log event) come in milestone 4.
 */
class HenryController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status'        => 'ok',
            'people_count'  => Person::count(),
            'clients_count' => Person::where('contact_type', 'CLIENT')->count(),
            'leads_count'   => Person::where('contact_type', 'LEAD')->count(),
            'time'          => now()->toIso8601String(),
        ]);
    }

    public function searchPeople(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $limit = min(50, (int) $request->query('limit', 20));

        if ($query === '') {
            return response()->json(['results' => []]);
        }

        $people = Person::query()
            ->where(function ($q) use ($query) {
                $q->where('email', 'ilike', "%{$query}%")
                  ->orWhere('first_name', 'ilike', "%{$query}%")
                  ->orWhere('last_name', 'ilike', "%{$query}%")
                  ->orWhere('phone_e164', 'ilike', "%{$query}%");
            })
            ->limit($limit)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone_e164', 'contact_type', 'branch']);

        return response()->json([
            'query'   => $query,
            'count'   => $people->count(),
            'results' => $people->map(fn ($p) => [
                'id'           => $p->id,
                'name'         => trim("{$p->first_name} {$p->last_name}"),
                'email'        => $p->email,
                'phone'        => $p->phone_e164,
                'contact_type' => $p->contact_type,
                'branch'       => $p->branch,
            ]),
        ]);
    }

    public function showPerson(string $id): JsonResponse
    {
        $person = Person::with('metrics')->find($id);

        if (! $person) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $recentTransactions = Transaction::where('person_id', $person->id)
            ->where('status', 'DONE')
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get(['id', 'category', 'amount_cents', 'currency', 'occurred_at', 'gateway_name'])
            ->map(fn ($t) => [
                'category'    => $t->category,
                'amount_usd'  => round($t->amount_cents / 100, 2),
                'currency'    => $t->currency,
                'occurred_at' => $t->occurred_at?->toIso8601String(),
                'gateway'     => $t->gateway_name,
            ]);

        $metric = $person->metrics; // hasOne — singular row

        return response()->json([
            'id'             => $person->id,
            'name'           => trim("{$person->first_name} {$person->last_name}"),
            'email'          => $person->email,
            'phone'          => $person->phone_e164,
            'country'        => $person->country,
            'contact_type'   => $person->contact_type,
            'lead_status'    => $person->lead_status,
            'branch'         => $person->branch,
            'account_manager' => $person->account_manager,
            'last_online_at' => $person->last_online_at?->toIso8601String(),
            'metrics'        => $metric ? [
                'total_deposits_usd'      => round(($metric->total_deposits_cents ?? 0) / 100, 2),
                'total_withdrawals_usd'   => round(($metric->total_withdrawals_cents ?? 0) / 100, 2),
                'net_deposits_usd'        => round(($metric->net_deposits_cents ?? 0) / 100, 2),
                'days_since_last_deposit' => $metric->days_since_last_deposit,
                'days_since_last_login'   => $metric->days_since_last_login,
                'has_markets'             => (bool) $metric->has_markets,
                'has_capital'             => (bool) $metric->has_capital,
                'has_academy'             => (bool) $metric->has_academy,
            ] : null,
            'recent_transactions' => $recentTransactions,
        ]);
    }

    public function bookMetrics(): JsonResponse
    {
        $startOfMonth = now()->startOfMonth();
        $startOfDay   = now()->startOfDay();

        $sumWhere = fn ($category, $since) => (int) Transaction::where('status', 'DONE')
            ->where('category', $category)
            ->where('occurred_at', '>=', $since)
            ->sum('amount_cents');

        return response()->json([
            'as_of'   => now()->toIso8601String(),
            'people'  => [
                'total'   => Person::count(),
                'leads'   => Person::where('contact_type', 'LEAD')->count(),
                'clients' => Person::where('contact_type', 'CLIENT')->count(),
            ],
            'deposits_usd' => [
                'today' => round($sumWhere('EXTERNAL_DEPOSIT', $startOfDay) / 100, 2),
                'mtd'   => round($sumWhere('EXTERNAL_DEPOSIT', $startOfMonth) / 100, 2),
            ],
            'withdrawals_usd' => [
                'today' => round($sumWhere('EXTERNAL_WITHDRAWAL', $startOfDay) / 100, 2),
                'mtd'   => round($sumWhere('EXTERNAL_WITHDRAWAL', $startOfMonth) / 100, 2),
            ],
            'challenge_purchases_usd' => [
                'today' => round($sumWhere('CHALLENGE_PURCHASE', $startOfDay) / 100, 2),
                'mtd'   => round($sumWhere('CHALLENGE_PURCHASE', $startOfMonth) / 100, 2),
            ],
            'dormant_clients' => [
                'over_14_days' => PersonMetric::where('days_since_last_login', '>=', 14)->count(),
                'over_30_days' => PersonMetric::where('days_since_last_login', '>=', 30)->count(),
            ],
        ]);
    }

    /**
     * Henry posts an event into the CRM. Currently writes an Activity row
     * tied to a person if a person_id is supplied; otherwise logs to the
     * Laravel log only (Henry's standalone observations don't have a row
     * to attach to).
     *
     * Body shape:
     *   {
     *     "event_type":  "henry_observation" | "manual_flag" | "kyc_concern" | …,
     *     "person_id":   "uuid (optional)",
     *     "description": "human-readable summary",
     *     "metadata":    { ... arbitrary jsonb }
     *   }
     */
    public function postEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type'  => 'required|string|max:64',
            'person_id'   => 'nullable|uuid',
            'description' => 'required|string|max:2000',
            'metadata'    => 'nullable|array',
        ]);

        // Without a person_id, we still log it but don't write Activity (no FK target).
        if (empty($data['person_id'])) {
            Log::info('Henry posted unattached event', $data);
            return response()->json([
                'recorded' => true,
                'attached' => false,
                'note'     => 'Logged to Laravel log only — no person_id supplied.',
            ]);
        }

        $person = Person::find($data['person_id']);
        if (! $person) {
            return response()->json(['error' => 'person_not_found'], 404);
        }

        $activity = Activity::create([
            'person_id'   => $person->id,
            'type'        => 'CALL_LOG', // closest existing type for "Henry observed something"
            'description' => "[Henry/{$data['event_type']}] " . $data['description'],
            'metadata'    => array_merge(['source' => 'henry', 'event_type' => $data['event_type']], $data['metadata'] ?? []),
            'occurred_at' => now(),
        ]);

        return response()->json([
            'recorded'    => true,
            'attached'    => true,
            'activity_id' => $activity->id,
        ], 201);
    }

    /**
     * Henry can flip the autonomous kill switch on or off.
     *
     * Body shape:
     *   { "action": "pause" | "resume", "reason": "string (optional)" }
     */
    public function pauseAutonomous(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:pause,resume',
            'reason' => 'nullable|string|max:500',
        ]);

        $guard = app(CostCeilingGuard::class);

        if ($data['action'] === 'pause') {
            $guard->pauseAutonomous();
            Log::warning('Autonomous AI sends paused via Henry endpoint', $data);
            return response()->json([
                'state'  => 'paused',
                'reason' => $data['reason'] ?? null,
            ]);
        }

        $guard->resumeAutonomous();
        Log::info('Autonomous AI sends resumed via Henry endpoint', $data);
        return response()->json([
            'state'  => 'resumed',
            'reason' => $data['reason'] ?? null,
        ]);
    }
}
