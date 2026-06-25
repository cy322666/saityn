<?php

namespace App\Console\Commands;

use App\Models\IntegrationEvent;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCommandHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TelegramProcessUpdate extends Command
{
    protected $signature = 'telegram:process-update {update_id : Telegram update_id to process}';

    protected $description = 'Process a stored Telegram update and send a reply.';

    public function handle(TelegramCommandHandler $commands, TelegramBotClient $telegram): int
    {
        $updateId = (string) $this->argument('update_id');
        $update = TelegramUpdate::query()->where('update_id', $updateId)->first();

        if (! $update) {
            $this->error("Telegram update not found: {$updateId}");

            return self::FAILURE;
        }

        $chatId = $update->message_chat_id ? (string) $update->message_chat_id : null;
        $commandChatId = config('services.telegram.command_chat_id');
        $commandChatId = $commandChatId ? (string) $commandChatId : null;

        if ($commandChatId && $chatId !== $commandChatId) {
            $this->line("Ignored chat {$chatId}; allowed chat is {$commandChatId}.");

            return self::SUCCESS;
        }

        try {
            $reply = $commands->handle($chatId, $update->message_text);
            $replyChatId = $commandChatId ?: $chatId;

            if ($replyChatId && $reply) {
                $telegram->sendMessage($replyChatId, $reply);
            }

            $update->forceFill(['processed_at' => Carbon::now()])->save();

            IntegrationEvent::query()->create([
                'provider' => 'telegram',
                'type' => 'command.processed',
                'external_id' => $update->update_id,
                'payload' => [
                    'chat_id' => $chatId,
                    'text' => $update->message_text,
                    'has_reply' => (bool) $reply,
                ],
                'status' => 'processed',
            ]);

            $this->info("Telegram update {$updateId} processed.");

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            IntegrationEvent::query()->create([
                'provider' => 'telegram',
                'type' => 'command.failed',
                'external_id' => $update->update_id,
                'payload' => [
                    'chat_id' => $chatId,
                    'text' => $update->message_text,
                ],
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);

            report($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
