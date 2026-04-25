<?php

declare(strict_types=1);

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

// ── Helper ────────────────────────────────────────────────────────────────────

function makeCsv(array $rows): string
{
    $headers = 'transaction_uuid,offer_name,amount,occurred_at,email';
    $lines   = array_map(
        fn (array $r) => implode(',', [
            $r['transaction_uuid'] ?? '',
            $r['offer_name']       ?? '',
            $r['amount']           ?? '199.00',
            $r['occurred_at']      ?? '2026-01-15',
            $r['email']            ?? 'test@example.com',
        ]),
        $rows,
    );

    return $headers . "\n" . implode("\n", $lines);
}

function makePerson(): string
{
    $id = (string) \Illuminate\Support\Str::uuid();

    DB::table('people')->insert([
        'id'           => $id,
        'first_name'   => 'Test',
        'last_name'    => 'User',
        'email'        => 'test-' . $id . '@example.com',
        'contact_type' => 'LEAD',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    return $id;
}

function makeTransaction(string $uuid, string $category = 'INTERNAL_TRANSFER'): Transaction
{
    return Transaction::forceCreate([
        'id'                   => \Illuminate\Support\Str::uuid(),
        'mtr_transaction_uuid' => $uuid,
        'person_id'            => makePerson(),
        'type'                 => 'DEPOSIT',
        'status'               => 'DONE',
        'amount_cents'         => 19900,
        'gateway_name'         => 'Internal Transfer',
        'category'             => $category,
        'occurred_at'          => now(),
        'synced_at'            => now(),
    ]);
}

// ── Missing file ──────────────────────────────────────────────────────────────

it('exits with failure when CSV file is not present', function () {
    $this->artisan('import:historical-challenges')
        ->assertExitCode(1)
        ->expectsOutputToContain('CSV not found');
});

// ── Dry run ───────────────────────────────────────────────────────────────────

it('dry run reports correct counts without writing to the database', function () {
    $uuid = 'aaaaaaaa-0000-0000-0000-000000000001';
    makeTransaction($uuid, 'INTERNAL_TRANSFER');

    Storage::disk('local')->put(
        'imports/historical-challenges-ttr-mfu.csv',
        makeCsv([['transaction_uuid' => $uuid, 'offer_name' => 'Evaluation_1_$5k TTR 3-Phase Challenge']])
    );

    $this->artisan('import:historical-challenges --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('[DRY RUN]')
        ->expectsOutputToContain('Dry run complete');

    // No DB writes
    expect(DB::table('import_audit_log')->count())->toBe(0);
    expect(Transaction::where('mtr_transaction_uuid', $uuid)->value('category'))->toBe('INTERNAL_TRANSFER');
});

// ── Happy path ────────────────────────────────────────────────────────────────

it('reclassifies INTERNAL_TRANSFER transaction to CHALLENGE_PURCHASE', function () {
    $uuid = 'bbbbbbbb-0000-0000-0000-000000000001';
    makeTransaction($uuid, 'INTERNAL_TRANSFER');

    Storage::disk('local')->put(
        'imports/historical-challenges-ttr-mfu.csv',
        makeCsv([['transaction_uuid' => $uuid, 'offer_name' => 'Evaluation_1_$5k TTR 3-Phase Challenge']])
    );

    $this->artisan('import:historical-challenges')
        ->assertExitCode(0)
        ->expectsOutputToContain('[LIVE]');

    expect(Transaction::where('mtr_transaction_uuid', $uuid)->value('category'))
        ->toBe('CHALLENGE_PURCHASE');

    expect(DB::table('import_audit_log')->where('mtr_transaction_uuid', $uuid)->value('action'))
        ->toBe('reclassified');
});

// ── Idempotency ───────────────────────────────────────────────────────────────

it('skips a row already reclassified in a previous run (idempotent)', function () {
    $uuid = 'cccccccc-0000-0000-0000-000000000001';
    makeTransaction($uuid, 'CHALLENGE_PURCHASE');

    // Simulate a prior audit log entry
    DB::table('import_audit_log')->insert([
        'id'                   => \Illuminate\Support\Str::uuid(),
        'import_batch'         => '2026-04-25T10:00:00',
        'mtr_transaction_uuid' => $uuid,
        'action'               => 'reclassified',
        'old_category'         => 'INTERNAL_TRANSFER',
        'new_category'         => 'CHALLENGE_PURCHASE',
        'created_at'           => now(),
    ]);

    Storage::disk('local')->put(
        'imports/historical-challenges-ttr-mfu.csv',
        makeCsv([['transaction_uuid' => $uuid]])
    );

    $this->artisan('import:historical-challenges')
        ->assertExitCode(0);

    // No second audit entry created
    expect(DB::table('import_audit_log')->where('mtr_transaction_uuid', $uuid)->count())->toBe(1);
});

// ── Not found ─────────────────────────────────────────────────────────────────

it('logs not_found for a UUID that does not exist in the database', function () {
    $uuid = 'dddddddd-0000-0000-0000-000000000001';

    Storage::disk('local')->put(
        'imports/historical-challenges-ttr-mfu.csv',
        makeCsv([['transaction_uuid' => $uuid]])
    );

    $this->artisan('import:historical-challenges')
        ->assertExitCode(0)
        ->expectsOutputToContain('NOT FOUND');

    expect(DB::table('import_audit_log')->where('mtr_transaction_uuid', $uuid)->value('action'))
        ->toBe('not_found');
});

// ── Wrong category ────────────────────────────────────────────────────────────

it('skips a transaction that is already EXTERNAL_DEPOSIT (unexpected category)', function () {
    $uuid = 'eeeeeeee-0000-0000-0000-000000000001';
    makeTransaction($uuid, 'EXTERNAL_DEPOSIT');

    Storage::disk('local')->put(
        'imports/historical-challenges-ttr-mfu.csv',
        makeCsv([['transaction_uuid' => $uuid]])
    );

    $this->artisan('import:historical-challenges')->assertExitCode(0);

    expect(Transaction::where('mtr_transaction_uuid', $uuid)->value('category'))
        ->toBe('EXTERNAL_DEPOSIT');

    expect(DB::table('import_audit_log')->where('mtr_transaction_uuid', $uuid)->value('action'))
        ->toBe('skipped');
});

// ── Multiple rows ─────────────────────────────────────────────────────────────

it('processes multiple rows and reports correct totals', function () {
    $uuid1 = 'ffffffff-0000-0000-0000-000000000001';
    $uuid2 = 'ffffffff-0000-0000-0000-000000000002';
    $uuid3 = 'ffffffff-0000-0000-0000-000000000003'; // will not be found

    makeTransaction($uuid1, 'INTERNAL_TRANSFER');
    makeTransaction($uuid2, 'INTERNAL_TRANSFER');

    Storage::disk('local')->put(
        'imports/historical-challenges-ttr-mfu.csv',
        makeCsv([
            ['transaction_uuid' => $uuid1],
            ['transaction_uuid' => $uuid2],
            ['transaction_uuid' => $uuid3],
        ])
    );

    $this->artisan('import:historical-challenges')->assertExitCode(0);

    expect(Transaction::where('mtr_transaction_uuid', $uuid1)->value('category'))->toBe('CHALLENGE_PURCHASE');
    expect(Transaction::where('mtr_transaction_uuid', $uuid2)->value('category'))->toBe('CHALLENGE_PURCHASE');
    expect(DB::table('import_audit_log')->where('action', 'reclassified')->count())->toBe(2);
    expect(DB::table('import_audit_log')->where('action', 'not_found')->count())->toBe(1);
});
