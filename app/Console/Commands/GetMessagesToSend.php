<?php

namespace App\Console\Commands;

use App\Services\SmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class GetMessagesToSend extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'sms:get-messages 
                            {--limit=5 : Number of messages to retrieve}
                            {--provider=inhousesms : Provider to filter by}
                            {--dry-run : Only show messages without marking them as sent}';

    /**
     * The console command description.
     */
    protected $description = 'Retrieve and mark pending SMS messages that are ready to be sent';

    /**
     * Execute the console command.
     */
    public function handle(SmsService $smsService): int
    {
        $limit = (int) $this->option('limit');
        $provider = $this->option('provider');
        $dryRun = $this->option('dry-run');

        $this->info('Fetching messages to send...');
        $this->newLine();

        if ($dryRun) {
            $messages = $smsService->previewMessagesToSend($limit, $provider);
        } else {
            $messages = $smsService->getAndMarkMessagesToSend($limit, $provider);
        }

        if ($messages->isEmpty()) {
            $this->warn('No messages found matching the criteria.');
            return Command::SUCCESS;
        }

        // Display the messages
        $this->displayMessages($messages, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Display messages in a formatted table.
     */
    private function displayMessages($messages, bool $dryRun): void
    {
        $this->info($dryRun ? 'Messages found (DRY RUN - not marked as sent):' : 'Messages retrieved and marked as sent:');
        $this->newLine();

        $tableData = $messages->map(function ($msg) {
            return [
                'ID' => $msg->id,
                'Phone' => $msg->phone,
                'Message' => Str::limit($msg->message, 40),
                'Timezone' => $msg->time_zone,
                'Send After' => $msg->send_after?->format('Y-m-d H:i:s') ?? 'NULL',
                'Status' => $msg->status,
                'Sent' => $msg->sent,
                'Sent At' => $msg->sent_at?->format('Y-m-d H:i:s') ?? 'NULL',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Phone', 'Message', 'Timezone', 'Send After', 'Status', 'Sent', 'Sent At'],
            $tableData
        );

        $this->newLine();
        $this->info("Total: {$messages->count()} message(s)");
    }
}

