<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var string[] */
    private array $indexedColumns = [
        'deal_name',
        'product_category',
        'company_status',
        'product_section',
        'organization_status',
        'region',
    ];

    /** @var string[] */
    private array $textColumns = [
        'trade_name',
        'deal_name',
        'product_category',
        'wb_organization_name',
        'short_name',
        'director_full_name',
        'director_position',
        'main_okved_code',
        'company_status',
        'product_section',
        'wb_store_name',
        'wb_seller_name',
        'organization_status',
        'region',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('sellers', function ($table) {
            foreach ($this->indexedColumns as $column) {
                $table->dropIndex([$column]);
            }
        });

        foreach ($this->textColumns as $column) {
            DB::statement("ALTER TABLE sellers MODIFY {$column} TEXT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ($this->textColumns as $column) {
            DB::statement("ALTER TABLE sellers MODIFY {$column} VARCHAR(255) NULL");
        }

        Schema::table('sellers', function ($table) {
            foreach ($this->indexedColumns as $column) {
                $table->index($column);
            }
        });
    }
};
