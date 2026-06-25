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
    ) {
    }

    public function message(): string
    {
        if ($this->selected === 0) {
            $lines = [
                'Отчет по выгрузке amoCRM',
                "Запрошено: {$this->requested}",
                'Найдено: 0',
            ];

            if ($this->error) {
                $lines[] = 'Ошибка: '.$this->error;
                $lines[] = 'Статус: завершено с ошибками.';
            } else {
                $lines[] = 'Статус: нет невыгруженных записей в sellers.';
            }

            return implode(PHP_EOL, $lines);
        }

        $lines = [
            'Отчет по выгрузке amoCRM',
            "Запрошено: {$this->requested}",
            "Взято из БД: {$this->selected}",
            "Успешно загружено: {$this->exported}",
            "Создано новых сделок: {$this->created}",
            "Обновлено дублей: {$this->updated}",
            "Ошибок: {$this->failed}",
        ];

        if ($this->error) {
            $lines[] = 'Ошибка: '.$this->error;
        }

        $lines[] = $this->failed > 0 ? 'Статус: завершено с ошибками.' : 'Статус: успешно.';

        return implode(PHP_EOL, $lines);
    }
}
