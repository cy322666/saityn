<?php

namespace App\Console\Commands;

use App\Models\Seller;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

class ImportSellers extends Command
{
    protected $signature = 'sellers:import
        {file : Path to .xlsx, .csv or .ods file}
        {--sheet= : Sheet name to import; defaults to the first sheet}
        {--chunk=1000 : Number of rows to write per batch}
        {--limit= : Stop after N data rows, useful for testing}
        {--truncate : Clear sellers table before import}';

    protected $description = 'Import sellers from a spreadsheet into the sellers table.';

    /** @var array<string, string> */
    private array $headers = [];

    /** @var string[] */
    private array $dateColumns = [
        'platform_registered_at',
        'registered_at',
        'liquidated_at',
        'wb_registration_at',
        'wildberries_registered_at',
        'fns_registered_at',
    ];

    /** @var string[] */
    private array $numericColumns = [
        'rating',
        'reviews_count',
        'sold_products_count',
        'buyout_percent',
        'sales_speed_per_day',
        'seller_rating',
        'orders_buyout_percent',
        'products_in_stock_count',
        'avg_sold_products_per_day',
        'sold_products',
    ];

    public function handle(): int
    {
        $file = $this->absolutePath((string) $this->argument('file'));

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            Seller::query()->truncate();
            $this->warn('Sellers table truncated.');
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $limit = $this->option('limit') ? max(1, (int) $this->option('limit')) : null;
        $reader = $this->readerFor($file);
        $startedAt = microtime(true);
        $imported = 0;
        $skipped = 0;
        $batch = [];

        $reader->open($file);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($this->option('sheet') && $sheet->getName() !== $this->option('sheet')) {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $values = $this->rowValues($row);

                    if ($rowNumber === 1) {
                        $this->headers = $this->mapHeaders($values);
                        $this->line('Mapped '.count($this->headers).' columns.');
                        continue;
                    }

                    if ($limit !== null && $imported >= $limit) {
                        break 2;
                    }

                    $payload = $this->payload($values);

                    if ($payload === []) {
                        $skipped++;
                        continue;
                    }

                    $batch[] = $payload;
                    $imported++;

                    if (count($batch) >= $chunkSize) {
                        $this->flush($batch);
                        $batch = [];
                        $this->line("Imported {$imported} rows...");
                    }
                }

                break;
            }

            if ($batch !== []) {
                $this->flush($batch);
            }
        } finally {
            $reader->close();
        }

        $seconds = round(microtime(true) - $startedAt, 2);
        $this->info("Done. Imported: {$imported}. Skipped empty rows: {$skipped}. Time: {$seconds}s.");

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function readerFor(string $file): ReaderInterface
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'xlsx' => new XlsxReader(),
            'csv' => new CsvReader(),
            'ods' => new OdsReader(),
            default => throw new RuntimeException('Only .xlsx, .csv and .ods imports are supported.'),
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function rowValues(Row $row): array
    {
        return $row->toArray();
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    private function mapHeaders(array $values): array
    {
        $columns = array_flip(Schema::getColumnListing('sellers'));

        $headers = [];

        foreach ($values as $index => $value) {
            $name = trim((string) $value);
            $column = Seller::COLUMN_MAP[$name] ?? $name;

            if (isset($columns[$column]) && ! in_array($column, ['id', 'created_at', 'updated_at'], true)) {
                $headers[$index] = $column;
            }
        }

        if ($headers === []) {
            throw new RuntimeException('No matching sellers columns found in the header row.');
        }

        return $headers;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<string, mixed>
     */
    private function payload(array $values): array
    {
        $payload = [
            'is_exported' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        foreach ($this->headers as $index => $column) {
            $payload[$column] = $this->normalizeValue($column, $values[$index] ?? null);
        }

        $hasData = collect($payload)
            ->except(['is_exported', 'created_at', 'updated_at'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->isNotEmpty();

        return $hasData ? $payload : [];
    }

    private function normalizeValue(string $column, mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return in_array($column, $this->dateColumns, true)
                ? Carbon::instance($value)->toDateString()
                : Carbon::instance($value)->toDateTimeString();
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || $value === null) {
            return null;
        }

        if (in_array($column, $this->dateColumns, true)) {
            return $this->normalizeDateValue($value);
        }

        if (in_array($column, $this->numericColumns, true)) {
            $number = str_replace([' ', ','], ['', '.'], (string) $value);

            return is_numeric($number) ? $number : null;
        }

        if ($column === 'is_exported') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = trim(preg_split('/[|;,\\n]+/u', $value)[0] ?? $value);

        if ($value === '') {
            return null;
        }

        $number = str_replace([' ', ','], ['', '.'], $value);

        if (is_numeric($number)) {
            $serial = (int) $number;

            if ($serial > 20000 && $serial < 60000) {
                return Carbon::create(1899, 12, 30)->addDays($serial)->toDateString();
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     */
    private function flush(array $batch): void
    {
        $withSellerId = array_values(array_filter($batch, fn (array $row) => ! empty($row['seller_id'])));
        $withoutSellerId = array_values(array_filter($batch, fn (array $row) => empty($row['seller_id'])));

        if ($withSellerId !== []) {
            Seller::upsert($withSellerId, ['seller_id'], array_values(array_diff(array_keys($withSellerId[0]), ['seller_id', 'created_at'])));
        }

        if ($withoutSellerId !== []) {
            Seller::insert($withoutSellerId);
        }
    }
}
