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

    public function getUpdates(int $offset, int $limit = 10, int $timeout = 0): array
    {
        return $this->request('getUpdates', [
            'offset' => $offset,
            'limit' => $limit,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'edited_message'],
        ], 'GET');
    }

    /**
     * @param array<int, array{command: string, description: string}> $commands
     * @param array<string, mixed>|null $scope
     */
    public function setMyCommands(array $commands, ?array $scope = null): array
    {
        return $this->post('setMyCommands', [
            'commands' => $commands,
            'scope' => $scope ?? ['type' => 'default'],
        ]);
    }

    /**
     * @param array<string, mixed>|null $scope
     */
    public function deleteMyCommands(?array $scope = null): array
    {
        return $this->post('deleteMyCommands', [
            'scope' => $scope ?? ['type' => 'default'],
        ]);
    }

    private function post(string $method, array $payload = []): array
    {
        return $this->request($method, $payload, 'POST');
    }

    private function request(string $method, array $payload = [], string $httpMethod = 'POST'): array
    {
        if (! app()->runningUnitTests()) {
            return $this->curlRequest($method, $payload, $httpMethod);
        }

        return $this->httpRequest($method, $payload);
    }

    private function httpRequest(string $method, array $payload = []): array
    {
        $response = $this->http()->post($method, $payload)->throw()->json();

        if (! is_array($response)) {
            throw new RuntimeException('Telegram returned an invalid response.');
        }

        return $response;
    }

    private function curlRequest(string $method, array $payload = [], string $httpMethod = 'POST'): array
    {
        if (! function_exists('curl_init')) {
            return $this->httpRequest($method, $payload);
        }

        $url = $this->methodUrl($method);
        $formPayload = $this->formPayload($payload);
        $httpMethod = strtoupper($httpMethod);

        if ($httpMethod === 'GET' && $formPayload !== []) {
            $url .= '?'.http_build_query($formPayload);
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Cannot initialize Telegram cURL request.');
        }

        $connectTimeout = max(1, (int) config('services.telegram.connect_timeout', 20));
        $timeout = max($connectTimeout, (int) config('services.telegram.timeout', 60));

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];

        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        if ($httpMethod !== 'GET') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($formPayload);
        }

        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException("Telegram cURL request failed: {$error}");
        }

        $response = json_decode((string) $body, true);

        if (! is_array($response)) {
            throw new RuntimeException('Telegram returned an invalid response: '.substr((string) $body, 0, 500));
        }

        if ($status >= 400 || data_get($response, 'ok') === false) {
            $description = data_get($response, 'description', 'Unknown Telegram API error');
            throw new RuntimeException("Telegram API error ({$status}): {$description}");
        }

        return $response;
    }

    private function methodUrl(string $method): string
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $baseUrl = rtrim((string) config('services.telegram.api_base_url', 'https://api.telegram.org'), '/');

        return "{$baseUrl}/bot{$token}/{$method}";
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(array $payload): array
    {
        return collect($payload)
            ->map(fn (mixed $value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value)
            ->all();
    }

    private function http(): PendingRequest
    {
        $token = config('services.telegram.bot_token');

        if (! $token) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $options = [];

        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options['curl'] = [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ];
        }

        $baseUrl = rtrim((string) config('services.telegram.api_base_url', 'https://api.telegram.org'), '/');
        $connectTimeout = max(1, (int) config('services.telegram.connect_timeout', 20));
        $timeout = max($connectTimeout, (int) config('services.telegram.timeout', 60));

        return Http::baseUrl("{$baseUrl}/bot{$token}")
            ->acceptJson()
            ->asJson()
            ->withOptions($options)
            ->retry(2, 1000)
            ->connectTimeout($connectTimeout)
            ->timeout($timeout);
    }
}
