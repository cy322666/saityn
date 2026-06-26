<?php

namespace App\Services\Export;

class AmoLeadExportResult
{
    public function __construct(
        public readonly int $requested,
        public readonly int $selected,
        public readonly int $exported,
        public readonly int $failed,
        public readonly array $leadIds = [],
        public readonly ?string $error = null,
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $totalSellers = 0,
        public readonly int $pendingSellers = 0,
    ) {
    }

    public function message(): string
    {
        $lines = [
            'Отчет по выгрузке',
            '',
            "Запрошено: {$this->requested}",
            "Успешно загружено: {$this->exported}",
            "Ошибок: {$this->failed}",
        ];

        if ($this->error) {
            $lines[] = 'Ошибка: '.$this->error;
        }

        if ($this->failed > 0 || $this->error) {
            $lines[] = 'Статус: завершено с ошибками';
        } elseif ($this->selected === 0) {
            $lines[] = 'Статус: нет невыгруженных записей';
        } else {
            $lines[] = 'Статус: успешно';
        }

        $lines[] = "Всего в БД: {$this->totalSellers}";
        $lines[] = "Ждут выгрузки: {$this->pendingSellers}";

        return implode(PHP_EOL, $lines);
    }
}
