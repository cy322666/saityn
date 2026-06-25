<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;

class TelegramSendTest extends Command
{
    protected $signature = 'telegram:send-test {chat_id? : Chat ID; defaults to TELEGRAM_COMMAND_CHAT_ID}';

    protected $description = 'Send a test message to Telegram.';

    public function handle(TelegramBotClient $telegram): int
    {
        $chatId = $this->argument('chat_id') ?: config('services.telegram.command_chat_id');

        if (! $chatId) {
            $this->error('Chat ID is not configured.');

            return self::FAILURE;
        }

        $telegram->sendMessage((string) $chatId, 'Проверка связи с Telegram API');
        $this->info('Telegram test message sent.');

        return self::SUCCESS;
    }
}
