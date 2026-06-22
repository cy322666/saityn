<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {url? : Public webhook URL} {--delete : Delete current webhook}';

    protected $description = 'Configure the Telegram bot webhook URL.';

    public function handle(TelegramBotClient $telegram): int
    {
        if ($this->option('delete')) {
            $telegram->deleteWebhook();
            $this->info('Telegram webhook deleted.');

            return self::SUCCESS;
        }

        $url = $this->argument('url') ?: url('/api/telegram/webhook');
        $telegram->setWebhook($url, config('services.telegram.webhook_secret'));
        $this->info("Telegram webhook set to {$url}.");

        return self::SUCCESS;
    }
}

