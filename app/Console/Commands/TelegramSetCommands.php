<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotClient;
use Illuminate\Console\Command;

class TelegramSetCommands extends Command
{
    protected $signature = 'telegram:set-commands {--delete : Delete current bot command menu}';

    protected $description = 'Configure the Telegram bot command menu.';

    public function handle(TelegramBotClient $telegram): int
    {
        $scopes = $this->scopes();

        if ($this->option('delete')) {
            foreach ($scopes as $scopeName => $scope) {
                $telegram->deleteMyCommands($scope);
                $this->line("Deleted commands for {$scopeName} scope.");
            }

            $this->info('Telegram bot commands deleted.');

            return self::SUCCESS;
        }

        $commands = [
            [
                'command' => 'start',
                'description' => 'Проверить подключение бота',
            ],
            [
                'command' => 'upload',
                'description' => 'Выгрузить N sellers в amoCRM',
            ],
        ];

        foreach ($scopes as $scopeName => $scope) {
            $telegram->setMyCommands($commands, $scope);
            $this->line("Configured {$scopeName} scope.");
        }

        foreach ($commands as $command) {
            $this->line('/'.$command['command'].' - '.$command['description']);
        }

        $this->info('Telegram bot commands configured.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function scopes(): array
    {
        $scopes = [
            'default' => ['type' => 'default'],
            'private chats' => ['type' => 'all_private_chats'],
            'group chats' => ['type' => 'all_group_chats'],
        ];

        $chatId = config('services.telegram.command_chat_id');

        if ($chatId) {
            $scopes['command chat'] = [
                'type' => 'chat',
                'chat_id' => (string) $chatId,
            ];
        }

        return $scopes;
    }
}
