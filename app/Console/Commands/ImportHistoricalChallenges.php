<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reclassify pre-31-March-2026 challenge purchases from INTERNAL_TRANSFER
 * to CHALLENGE_PURCHASE using a CSV export from the MTR admin panel.
 *
 * Before the gateway changeover, MTR logged challenge purchases as
 * Internal Transfer deposits with no offer name — indistinguishable from real
 * wallet movements in the API feed. Werner's CSV provides the missing offer
 * name link so we can correct the category.
 *
 * Expected CSV columns (header row required, order does not matter):
 *   transaction_uuid  — MTR transaction UUID (matches transactions.mtr_transaction_uuid)
 *   offer_name        — offer name confirming this was a challenge purchase
 *   amount            — USD amount, e.g. 199.00 (used for reconciliation only)
 *   occurred_at       — ISO-8601 or Y-m-d date (used for reconciliation only)
 *   email             — client email (used for reconciliation only, optional)
 *
 * Usage:
 *   php artisan import:historical-challenges             # live run
 *   php artisan import:historical-challenges --dry-run  # preview, no DB writes
 *   php artisan import:historical-challenges --rollback=2026-04-25T14:30:00
 */
class ImportHistoricalChallenges extends Command
{
    protected $signature = 'import:historical-challenges
                            {--dry-run : Preview changes without writing to the database}
                            {--rollback= : Batch ID to reverse — resets reclassified rows back to INTERNAL_TRANSFER}
                            {--file= : Path to CSV (default: storage/imports/historical-challenges-ttr-mfu.csv)}';

    protected $description = 'Reclassify historical challenge purchases from INTERNAL_TRANSFER to CHALLENGE_PURCHASE';

    private const CSV_PATH = 'imports/historical-challenges-ttr-mfu.csv';

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->handleRollback((string) $this->option('rollback'));
        }

        return $this->handleImport();
    }

    // ── Import ────────────────────────────────────────────────────────────────

    private function handleImport(): int
    {
        $csvPath = $this->option('file') ?? self::CSV_PATH;

        if (!Storage::disk('local')->exists($csvPath)) {
            $this->error("CSV not found: storage/app/{$csvPath}");
            $this->line('Place the export file at: storage/imports/historical-challenges-ttr-mfu.csv');

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $batch    = now()->format('Y-m-d\TH:i:s');
        $rows     = $this->parseCsv($csvPath);

        if (empty($rows)) {
            $this->error('CSV is empty or has no valid rows.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s Processing %d CSV rows%s',
            $isDryRun ? '[DRY RUN]' : '[LIVE]',
            count($rows),
            $isDryRun ? ' — no database writes will occur' : " (batch: {$batch})",
        ));

        $counts = ['reclassified' => 0, 'skipped' => 0, 'not_found' => 0, 'already_done' => 0];

        foreach ($rows as $row) {
            $uuid = trim($row['transaction_uuid'] ?? '');

            if ($uuid === '') {
                $this->warn("Row missing transaction_uuid — skipped.");
                $counts['skipped']++;
                continue;
            }

            // Idempotency: skip if already reclassified in a previous run
            $alreadyLogged = DB::table('import_audit_log')
                ->where('mtr_transaction_uuid', $uuid)
                ->where('action', 'reclassified')
                ->exists();

            if ($alreadyLogged) {
                $counts['already_done']++;
                continue;
            }

            $transaction = Transaction::where('mtr_transaction_uuid', $uuid)->first();

            if (!$transaction) {
                $counts['not_found']++;
                if (!$isDryRun) {
                    $this->logAudit($batch, $uuid, 'not_found', null, null, $row, 'No matching transaction in DB');
                }
                $this->warn("  NOT FOUND: {$uuid}");
                continue;
            }

            if ($transaction->category !== 'INTERNAL_TRANSFER') {
                $counts['skipped']++;
                if (!$isDryRun) {
                    $this->logAudit(
                        $batch, $uuid, 'skipped',
                        $transaction->category, null, $row,
                        "Category is '{$transaction->category}', expected INTERNAL_TRANSFER"
                    );
                }
                continue;
            }

            $counts['reclassified']++;

            if (!$isDryRun) {
                DB::transaction(function () use ($batch, $uuid, $transaction, $row): void {
                    DB::table('transactions')
                        ->where('id', $transaction->id)
                        ->update(['category' => 'CHALLENGE_PURCHASE']);

                    $this->logAudit(
                        $batch, $uuid, 'reclassified',
                        'INTERNAL_TRANSFER', 'CHALLENGE_PURCHASE', $row
                    );
                });
            }
        }

        $this->newLine();
        $this->table(
            ['Outcome', 'Count'],
            [
                ['Reclassified → CHALLENGE_PURCHASE', $counts['reclassified']],
                ['Already reclassified (skipped, idempotent)', $counts['already_done']],
                ['Unexpected category (skipped)', $counts['skipped']],
                ['Transaction UUID not found in DB', $counts['not_found']],
            ]
        );

        if ($isDryRun) {
            $this->warn('Dry run complete — no changes written. Re-run without --dry-run to apply.');
        } else {
            $this->info("Import complete. Batch ID: {$batch}");
            $this->line('To rollback: php artisan import:historical-challenges --rollback=' . $batch);
        }

        return self::SUCCESS;
    }

    // ── Rollback ──────────────────────────────────────────────────────────────

    private function handleRollback(string $batch): int
    {
        $entries = DB::table('import_audit_log')
            ->where('import_batch', $batch)
            ->where('action', 'reclassified')
            ->get();

        if ($entries->isEmpty()) {
            $this->error("No reclassified entries found for batch: {$batch}");

            return self::FAILURE;
        }

        $this->warn(sprintf(
            'Rolling back %d rows for batch %s (CHALLENGE_PURCHASE → INTERNAL_TRANSFER)',
            $entries->count(),
            $batch,
        ));

        if (!$this->confirm('Proceed with rollback?')) {
            $this->info('Rollback cancelled.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($entries, $batch): void {
            foreach ($entries as $entry) {
                DB::table('transactions')
                    ->where('mtr_transaction_uuid', $entry->mtr_transaction_uuid)
                    ->update(['category' => 'INTERNAL_TRANSFER']);
            }

            // Remove the reclassified entries for this batch from the audit log
            DB::table('import_audit_log')
                ->where('import_batch', $batch)
                ->where('action', 'reclassified')
                ->delete();
        });

        $this->info("Rollback complete. {$entries->count()} transactions reset to INTERNAL_TRANSFER.");

        return self::SUCCESS;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $storagePath): array
    {
        $fullPath = Storage::disk('local')->path($storagePath);
        $handle   = fopen($fullPath, 'r');

        if ($handle === false) {
            return [];
        }

        $headers = null;
        $rows    = [];

        while (($line = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                // Normalise header names: lowercase, trim whitespace
                $headers = array_map(fn (string $h) => strtolower(trim($h)), $line);
                continue;
            }

            if (count($line) !== count($headers)) {
                continue;
            }

            $rows[] = array_combine($headers, $line);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param array<string, string> $csvRow
     */
    private function logAudit(
        string $batch,
        string $uuid,
        string $action,
        ?string $oldCategory,
        ?string $newCategory,
        array $csvRow,
        ?string $notes = null,
    ): void {
        $rawAmount = trim($csvRow['amount'] ?? '');
        $amountCents = $rawAmount !== '' ? (int) round((float) $rawAmount * 100) : null;

        DB::table('import_audit_log')->insert([
            'id'                    => Str::uuid()->toString(),
            'import_batch'          => $batch,
            'mtr_transaction_uuid'  => $uuid,
            'action'                => $action,
            'old_category'          => $oldCategory,
            'new_category'          => $newCategory,
            'csv_offer_name'        => trim($csvRow['offer_name'] ?? '') ?: null,
            'csv_amount_cents'      => $amountCents,
            'csv_email'             => trim($csvRow['email'] ?? '') ?: null,
            'notes'                 => $notes,
            'created_at'            => now(),
        ]);
    }
}
