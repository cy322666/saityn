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

        if ($expectedSecret && $request->hдеeader('X-Telegram-Bot-Api-Secret-Token') !== $expectedSecret) {
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

        $reply = $this->commands->handle($chatId, is_string($text) ? $text : null);
        $replyChatId = $commandChatId ?: $chatId;

        if ($replyChatId && $reply) {
            try {
                $this->telegram->sendMessage($replyChatId, $reply);
            } catch (\Throwable $exception) {
                Log::warning('Telegram command reply failed', [
                    'update_id' => $update->update_id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
