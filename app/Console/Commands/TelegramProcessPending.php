<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TelegramProcessPending extends Command
{
    protected $signature = 'telegram:process-pending {--limit=10 : Maximum pending updates to process}';

    protected $description = 'Process stored Telegram updates that have not been handled yet.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $updates = TelegramUpdate::query()
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($updates->isEmpty()) {
            $this->info('No pending Telegram updates.');

            return self::SUCCESS;
        }

        foreach ($updates as $update) {
            $this->line("Processing Telegram update {$update->update_id}...");
            Artisan::call('telegram:process-update', ['update_id' => $update->update_id]);
            $this->output->write(Artisan::output());
        }

        $this->info("Processed {$updates->count()} Telegram updates.");

        return self::SUCCESS;
    }
}
