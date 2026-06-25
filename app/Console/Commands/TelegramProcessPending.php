<?php

namespace App\Console\Commands;

use App\Models\TelegramUpdate;
use App\Services\Support\CommandLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TelegramProcessPending extends Command
{
    protected $signature = 'telegram:process-pending {--limit=10 : Maximum pending updates to process}';

    protected $description = 'Process stored Telegram updates that have not been handled yet.';

    public function handle(CommandLock $lock): int
    {
        if (! $lock->acquire('telegram-process-pending')) {
            $this->info('Telegram pending processor already running.');

            return self::SUCCESS;
        }

        try {
            return $this->process();
        } finally {
            $lock->release();
        }
    }

    private function process(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $updates = TelegramUpdate::query()
            ->whereNull('processed_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($updates->isEmpty()) {
            $this->info('No pending Telegram updates.');
            $this->resendFailedReports($limit);

            return self::SUCCESS;
        }

        foreach ($updates as $update) {
            $this->line("Processing Telegram update {$update->update_id}...");
            Artisan::call('telegram:process-update', ['update_id' => $update->update_id]);
            $this->output->write(Artisan::output());
        }

        $this->resendFailedReports($limit);

        $this->info("Processed {$updates->count()} Telegram updates.");

        return self::SUCCESS;
    }

    private function resendFailedReports(int $limit): void
    {
        Artisan::call('telegram:resend-reports', ['--limit' => $limit]);
        $this->output->write(Artisan::output());
    }
}
