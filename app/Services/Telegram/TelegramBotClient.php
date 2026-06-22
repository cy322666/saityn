<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotClient
{
    public function sendMessage(string $chatId, string $text, array $options = []): array
    {
        return $this->post('sendMessage', array_merge($options, [
            'chat_id' => $chatId,
            'text' => $text,
        ]));
    }

    public function setWebhook(string $url, ?string $secretToken = null): array
    {
        $payload = ['url' => $url];

        if ($secretToken) {
            $payload['secret_token'] = $secretToken;
        }

        return $this->post('setWebhook', $payload);
    }

    public function deleteWebhook(): array
    {
        return $this->post('deleteWebhook');
    }

    private function post(string $method, array $payload = []): array
    {
        $response = $this->http()->post($method, $payload)->throw()->json();

        if (! is_array($response)) {
            throw new RuntimeException('Telegram returned an invalid response.');
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        return Http::baseUrl("https://api.telegram.org/bot{$token}")
            ->acceptJson()
            ->asJson();
    }
}

