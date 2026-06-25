<?php

namespace App\Console\Commands;

use App\Models\IntegrationEvent;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;

class TelegramResendReports extends Command
{
    protected $signature = 'telegram:resend-reports {--limit=10 : Maximum failed reports to resend}';

    protected $description = 'Resend Telegram reports that were saved after send failures.';

    public function handle(TelegramBotClient $telegram): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $events = IntegrationEvent::query()
            ->where('provider', 'telegram')
            ->where('type', 'report.send_failed')
            ->where('status', 'failed')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            $this->info('No failed Telegram reports to resend.');

            return self::SUCCESS;
        }

        foreach ($events as $event) {
            $replyChatId = data_get($event->payload, 'reply_chat_id');
            $reply = data_get($event->payload, 'reply');

            if (! $replyChatId || ! $reply) {
                $event->forceFill([
                    'status' => 'invalid',
                    'error' => 'Missing reply_chat_id or reply payload.',
                ])->save();

                continue;
            }

            try {
                $telegram->sendMessage((string) $replyChatId, (string) $reply);
                $event->forceFill([
                    'status' => 'processed',
                    'error' => null,
                ])->save();

                $this->info("Report event {$event->id} sent.");
            } catch (\Throwable $exception) {
                $event->forceFill(['error' => $exception->getMessage()])->save();
                $this->warn("Report event {$event->id} failed: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
