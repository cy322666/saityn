<?php

namespace App\Console\Commands;

use App\Models\IntegrationEvent;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TelegramPollUpdates extends Command
{
    protected $signature = 'telegram:poll-updates {--limit=10 : Maximum Telegram updates to fetch and process}';

    protected $description = 'Poll Telegram updates and process bot commands without a webhook.';

    public function handle(TelegramBotClient $telegram): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $offset = $this->nextOffset();
        $response = $telegram->getUpdates($offset, $limit, 0);
        $updates = $response['result'] ?? [];

        if (! is_array($updates)) {
            $this->error('Telegram returned an invalid updates response.');

            return self::FAILURE;
        }

        foreach ($updates as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            $this->storeUpdate($payload);
        }

        $this->info('Polled '.count($updates).' Telegram updates.');

        Artisan::call('telegram:process-pending', ['--limit' => $limit]);
        $this->output->write(Artisan::output());

        return self::SUCCESS;
    }

    private function nextOffset(): int
    {
        $lastUpdateId = TelegramUpdate::query()
            ->latest('id')
            ->value('update_id');

        return $lastUpdateId ? ((int) $lastUpdateId) + 1 : 0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeUpdate(array $payload): void
    {
        $updateId = data_get($payload, 'update_id');

        if ($updateId === null) {
            return;
        }

        $message = data_get($payload, 'message') ?? data_get($payload, 'edited_message');
        $chatId = data_get($message, 'chat.id');
        $text = data_get($message, 'text');

        $update = TelegramUpdate::query()->firstOrNew([
            'update_id' => (string) $updateId,
        ]);

        $update->message_chat_id = $chatId ? (string) $chatId : null;
        $update->message_text = is_string($text) ? $text : null;
        $update->payload = $payload;

        if (! $update->exists) {
            $update->processed_at = null;
        }

        $update->save();

        IntegrationEvent::query()->create([
            'provider' => 'telegram',
            'type' => 'poll.update',
            'external_id' => $update->update_id,
            'payload' => $payload,
            'status' => 'processed',
        ]);
    }
}
