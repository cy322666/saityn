<?php

namespace App\Services\Telegram;

use App\Services\Export\AmoLeadExporter;

class TelegramCommandHandler
{
    public function __construct(
        private readonly AmoLeadExporter $exporter,
    ) {}

    public function handle(?string $chatId, ?string $text): ?string
    {
        if (! $chatId || ! is_string($text)) {
            return null;
        }

        $text = trim($text);

        if ($text === '') {
            return null;
        }

        [$command, $arguments] = $this->parse($text);

        return match ($command) {
            '/start' => 'Бот подключен. Команда для выгрузки в amoCRM: /upload 10',
            '/help' => 'Доступные команды: /upload 10, /amo 10, /load 10, /выгрузить 10',
            '/upload', '/amo', '/load', '/выгрузить' => $this->export($arguments),
            default => null,
        };
    }

    private function export(string $arguments): string
    {
        if (trim($arguments) === '') {
            $count = 10;
        } elseif (preg_match('/^\s*(\d+)\s*$/u', $arguments, $matches)) {
            $count = (int) $matches[1];
        } else {
            return 'Укажите количество записей, например: /upload 10';
        }

        if ($count < 1) {
            return 'Количество должно быть больше нуля.';
        }

        return $this->exporter->exportPending(min($count, 10))->message();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parse(string $text): array
    {
        [$command, $arguments] = array_pad(preg_split('/\s+/u', $text, 2), 2, '');
        $command = preg_replace('/@.+$/', '', mb_strtolower($command));

        return [$command, $arguments];
    }
}
