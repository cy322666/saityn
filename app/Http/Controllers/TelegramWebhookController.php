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

        if (function_exists('fastcgi_finish_request') && ! app()->runningUnitTests()) {
            response()->json(['ok' => true])->send();
            fastcgi_finish_request();

            $this->storeAndDispatch($payload, shouldDispatch: true);

            exit;
        }

        $this->storeAndDispatch($payload, shouldDispatch: app()->runningUnitTests());

        return response()->json(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeAndDispatch(array $payload, bool $shouldDispatch): void
    {
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
                'processed_at' => null,
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

            $update->forceFill(['processed_at' => Carbon::now()])->save();

            return;
        }

        if ($shouldDispatch) {
            $this->startBackgroundProcessor($update->update_id);
        }
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
            'cd %s && nohup %s artisan telegram:process-update %s </dev/null >> %s 2>&1 & echo $!',
            escapeshellarg(base_path()),
            escapeshellcmd($php),
            escapeshellarg($updateId),
            escapeshellarg($logFile),
        );

        $output = [];
        exec($command, $output);

        IntegrationEvent::query()->create([
            'provider' => 'telegram',
            'type' => 'command.dispatched',
            'external_id' => $updateId,
            'payload' => [
                'command' => $command,
                'log_file' => $logFile,
                'pid' => $output[0] ?? null,
            ],
            'status' => 'processed',
        ]);

        Log::info('Telegram background processor dispatched.', [
            'update_id' => $updateId,
            'pid' => $output[0] ?? null,
        ]);
    }
}
