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
        if ($this->option('delete')) {
            $telegram->deleteMyCommands();
            $this->info('Telegram bot commands deleted.');

            return self::SUCCESS;
        }

        $commands = [
            [
                'command' => 'start',
                'description' => 'Проверить подключение бота',
            ],
            [
                'command' => 'help',
                'description' => 'Показать доступные команды',
            ],
            [
                'command' => 'upload',
                'description' => 'Выгрузить N sellers в amoCRM',
            ],
            [
                'command' => 'amo',
                'description' => 'Выгрузить N sellers в amoCRM',
            ],
            [
                'command' => 'load',
                'description' => 'Выгрузить N sellers в amoCRM',
            ],
        ];

        $telegram->setMyCommands($commands);

        foreach ($commands as $command) {
            $this->line('/'.$command['command'].' - '.$command['description']);
        }

        $this->info('Telegram bot commands configured.');

        return self::SUCCESS;
    }
}
