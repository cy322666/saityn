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

        $update = TelegramUpdate::query()->updateOrCreate(
            ['update_id' => (string) data_get($payload, 'update_id')],
            [
                'message_chat_id' => $chatId ? (string) $chatId : null,
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

        $reply = $this->commands->handle($chatId ? (string) $chatId : null, is_string($text) ? $text : null);

        if ($chatId && $reply) {
            try {
                $this->telegram->sendMessage((string) $chatId, $reply);
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
