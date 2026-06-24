<?php

use App\Models\Seller;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var string[] */
    private array $excludedColumns = [
        'seller_id',
        'ogrn',
        'inn',
        'lead_id',
        'contact_id',
        'company_id',
        'is_exported',
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
        'platform_registered_at',
        'registered_at',
        'liquidated_at',
        'wb_registration_at',
        'wildberries_registered_at',
        'fns_registered_at',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $table = 'sellers';
        $columnsToAlter = $this->columnsToAlter($table);

        if ($columnsToAlter === []) {
            return;
        }

        $this->dropIndexesForColumns($table, $columnsToAlter);

        foreach ($columnsToAlter as $column) {
            DB::statement('ALTER TABLE '.$this->quoteIdentifier($table).' MODIFY '.$this->quoteIdentifier($column).' MEDIUMTEXT NULL');
        }
    }

    public function down(): void
    {
        //
    }

    /**
     * @return string[]
     */
    private function columnsToAlter(string $table): array
    {
        $longTextColumns = array_values(array_diff(
            array_unique(array_values(Seller::COLUMN_MAP)),
            $this->excludedColumns,
        ));

        $columns = DB::select(
            'select COLUMN_NAME, DATA_TYPE from information_schema.COLUMNS where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ?',
            [$table],
        );

        $types = [];

        foreach ($columns as $column) {
            $types[$column->COLUMN_NAME] = strtolower((string) $column->DATA_TYPE);
        }

        return array_values(array_filter(
            $longTextColumns,
            fn (string $column) => isset($types[$column]) && ! in_array($types[$column], ['text', 'mediumtext', 'longtext'], true),
        ));
    }

    /**
     * @param string[] $columns
     */
    private function dropIndexesForColumns(string $table, array $columns): void
    {
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $indexes = DB::select(
            "select distinct INDEX_NAME from information_schema.STATISTICS where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME in ({$placeholders}) and INDEX_NAME <> 'PRIMARY'",
            array_merge([$table], $columns),
        );

        foreach ($indexes as $index) {
            DB::statement('ALTER TABLE '.$this->quoteIdentifier($table).' DROP INDEX '.$this->quoteIdentifier((string) $index->INDEX_NAME));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
};
