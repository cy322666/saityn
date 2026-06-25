<?php

namespace App\Http\Controllers;

use App\Models\IntegrationEvent;
use App\Models\TelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
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

        $this->startBackgroundProcessor($update->update_id);

        return response()->json(['ok' => true]);
    }

    private function startBackgroundProcessor(string $updateId): void
    {
        if (app()->runningUnitTests()) {
            Artisan::call('telegram:process-update', ['update_id' => $updateId]);

            return;
        }

        if (! function_exists('exec')) {
            Log::warning('Telegram background processor cannot start because exec is disabled.', [
                'update_id' => $updateId,
            ]);

            return;
        }

        $php = (string) config('services.telegram.php_cli_binary', 'php');
        $logFile = storage_path('logs/telegram-worker.log');
        $command = sprintf(
            'cd %s && %s artisan telegram:process-update %s >> %s 2>&1 &',
            escapeshellarg(base_path()),
            escapeshellcmd($php),
            escapeshellarg($updateId),
            escapeshellarg($logFile),
        );

        exec($command);

        IntegrationEvent::query()->create([
            'provider' => 'telegram',
            'type' => 'command.dispatched',
            'external_id' => $updateId,
            'payload' => [
                'command' => $command,
                'log_file' => $logFile,
            ],
            'status' => 'processed',
        ]);

        Log::info('Telegram background processor dispatched.', [
            'update_id' => $updateId,
        ]);
    }
}
