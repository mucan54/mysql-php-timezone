<?php

namespace App\Console\Commands;

use App\Models\LogsSms;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PopulateRandomData extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sms:populate 
                            {--sent=1000000 : Number of rows with status=1 (sent)}
                            {--pending=50000 : Number of rows with status=0 (pending)}
                            {--batch=5000 : Batch size for inserts}';

    /**
     * The console command description.
     */
    protected $description = 'Truncate the logs_sms table and populate it with random test data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sentCount = (int) $this->option('sent');
        $pendingCount = (int) $this->option('pending');
        $batchSize = (int) $this->option('batch');

        $this->info('Starting data population...');
        $this->newLine();

        // Step 1: Truncate the table
        $this->info('Truncating logs_sms table...');
        DB::table('logs_sms')->truncate();
        $this->info('Table truncated successfully.');
        $this->newLine();

        // Step 2: Populate rows with status = 1 (sent messages)
        $this->info("Populating {$sentCount} rows with status = 1 (sent messages)...");
        $this->populateRows($sentCount, 1, $batchSize);
        $this->newLine();

        // Step 3: Populate rows with status = 0 (pending messages)
        $this->info("Populating {$pendingCount} rows with status = 0 (pending messages)...");
        $this->populateRows($pendingCount, 0, $batchSize);
        $this->newLine();

        $this->info('Data population completed successfully!');
        $this->table(
            ['Status', 'Count'],
            [
                ['Sent (status=1)', number_format($sentCount)],
                ['Pending (status=0)', number_format($pendingCount)],
                ['Total', number_format($sentCount + $pendingCount)],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Populate rows with the given status.
     */
    private function populateRows(int $count, int $status, int $batchSize): void
    {
        $progressBar = $this->output->createProgressBar($count);
        $progressBar->start();

        $batches = ceil($count / $batchSize);
        $remaining = $count;

        for ($i = 0; $i < $batches; $i++) {
            $currentBatchSize = min($batchSize, $remaining);
            $rows = [];

            for ($j = 0; $j < $currentBatchSize; $j++) {
                $rows[] = $this->generateRow($status);
            }

            DB::table('logs_sms')->insert($rows);

            $progressBar->advance($currentBatchSize);
            $remaining -= $currentBatchSize;
        }

        $progressBar->finish();
        $this->newLine();
    }

    /**
     * Generate a single random row.
     */
    private function generateRow(int $status): array
    {
        $timezone = $this->getRandomTimezone();
        $sendAfter = null;

        // For pending messages (status=0), generate send_after between 2 hours before and 2 days after
        if ($status === 0) {
            // Random datetime between 2 hours before and 2 days after current time
            $hoursOffset = rand(-2, 48);
            $minutesOffset = rand(0, 59);

            $sendAfter = Carbon::now()
                ->addHours($hoursOffset)
                ->addMinutes($minutesOffset)
                ->format('Y-m-d H:i:s');
        }

        $now = Carbon::now()->format('Y-m-d H:i:s');

        return [
            'phone' => $this->generatePhoneNumber(),
            'message' => $this->generateRandomMessage(),
            'priority' => rand(0, 5),
            'provider' => 'inhousesms',
            'status' => $status,
            'sent' => $status, // sent = 1 for status = 1
            'send_after' => $sendAfter,
            'time_zone' => $timezone,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Generate a random Australian phone number starting with 04.
     */
    private function generatePhoneNumber(): string
    {
        return '04' . str_pad((string) rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a random message between 100 and 255 characters.
     */
    private function generateRandomMessage(): string
    {
        $length = rand(100, 255);
        return Str::random($length);
    }

    /**
     * Get a random timezone from the predefined list.
     */
    private function getRandomTimezone(): string
    {
        return LogsSms::VALID_TIMEZONES[array_rand(LogsSms::VALID_TIMEZONES)];
    }
}

