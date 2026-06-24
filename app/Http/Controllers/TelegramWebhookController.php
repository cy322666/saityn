<?php

namespace App\Http\Controllers;

use App\Models\IntegrationEvent;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCommandHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramBotClient $telegram,
        private readonly TelegramCommandHandler $commands,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $expectedSecret = config('services.telegram.webhook_secret');

        if ($expectedSecret && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $expectedSecret) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->all();
        $message = data_get($payload, 'message') ?? data_get($payload, 'edited_message');
        $chatId = data_get($message, 'chat.id');
        $text = data_get($message, 'text');
        $chatId = $chatId ? (string) $chatId : null;
        $commandChatId = config('services.telegram.command_chat_id');
        $commandChatId = $commandChatId ? (string) $commandChatId : null;

        $update = TelegramUpdate::query()->updateOrCreate(
            ['update_id' => (string) data_get($payload, 'update_id')],
            [
                'message_chat_id' => $chatId,
                'message_text' => $text,
                'payload' => $payload,
                'processed_at' => Carbon::now(),
            ],
        );

        IntegrationEvent::query()->create([
            'provider' => 'telegram',
            'type' => 'webhook.update',
            'external_id' => $update->update_id,
            'payload' => $payload,
            'status' => 'processed',
        ]);

        if ($commandChatId && $chatId !== $commandChatId) {
            IntegrationEvent::query()->create([
                'provider' => 'telegram',
                'type' => 'webhook.ignored_chat',
                'external_id' => $update->update_id,
                'payload' => [
                    'chat_id' => $chatId,
                    'allowed_chat_id' => $commandChatId,
                ],
                'status' => 'ignored',
            ]);

            return response()->json(['ok' => true]);
        }

        defer(fn () => $this->processCommandAfterResponse(
            $update->update_id,
            $chatId,
            is_string($text) ? $text : null,
            $commandChatId,
        ));

        return response()->json(['ok' => true]);
    }

    private function processCommandAfterResponse(
        string $updateId,
        ?string $chatId,
        ?string $text,
        ?string $commandChatId,
    ): void {
        try {
            $reply = $this->commands->handle($chatId, $text);
            $replyChatId = $commandChatId ?: $chatId;

            if ($replyChatId && $reply) {
                $this->telegram->sendMessage($replyChatId, $reply);
            }
        } catch (\Throwable $exception) {
            Log::warning('Telegram command processing failed', [
                'update_id' => $updateId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
