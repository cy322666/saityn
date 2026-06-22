<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('seller_id')->nullable()->index();
            $table->text('source_bases')->nullable();
            $table->text('seller_url')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('deal_name')->nullable()->index();
            $table->string('product_category')->nullable()->index();
            $table->string('wb_organization_name')->nullable();
            $table->text('company_mobile_phones')->nullable();
            $table->text('company_landline_phones')->nullable();
            $table->text('company_email')->nullable();
            $table->text('company_website')->nullable();
            $table->text('whatsapp')->nullable();
            $table->text('telegram')->nullable();
            $table->text('viber')->nullable();
            $table->text('vk')->nullable();
            $table->text('instagram')->nullable();
            $table->text('ok')->nullable();
            $table->decimal('rating', 5, 2)->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->unsignedBigInteger('sold_products_count')->nullable();
            $table->decimal('buyout_percent', 5, 2)->nullable();
            $table->decimal('sales_speed_per_day', 12, 2)->nullable();
            $table->text('seller_address')->nullable();
            $table->date('platform_registered_at')->nullable();
            $table->string('ogrn')->nullable()->index();
            $table->string('inn')->nullable()->index();
            $table->string('short_name')->nullable();
            $table->string('director_full_name')->nullable();
            $table->string('director_position')->nullable();
            $table->string('main_okved_code')->nullable();
            $table->text('main_okved_name')->nullable();
            $table->date('registered_at')->nullable();
            $table->date('liquidated_at')->nullable();
            $table->string('company_status')->nullable()->index();
            $table->string('product_section')->nullable()->index();
            $table->text('wb_profile_url')->nullable();
            $table->text('product_categories')->nullable();
            $table->text('brands')->nullable();
            $table->string('wb_store_name')->nullable();
            $table->text('mobile_phones')->nullable();
            $table->text('landline_phones')->nullable();
            $table->text('email')->nullable();
            $table->text('website')->nullable();
            $table->text('first_product_url')->nullable();
            $table->decimal('seller_rating', 5, 2)->nullable();
            $table->decimal('orders_buyout_percent', 5, 2)->nullable();
            $table->unsignedInteger('products_in_stock_count')->nullable();
            $table->text('wb_organization_address')->nullable();
            $table->date('wb_registration_at')->nullable();
            $table->text('legal_address')->nullable();
            $table->string('wb_seller_name')->nullable();
            $table->text('work_mobile_phones')->nullable();
            $table->text('work_mobile_phones_legal_extra')->nullable();
            $table->text('work_landline_phones')->nullable();
            $table->text('work_landline_phones_legal_extra')->nullable();
            $table->text('work_emails')->nullable();
            $table->text('work_emails_extra_source')->nullable();
            $table->text('website_extra')->nullable();
            $table->text('work_whatsapp')->nullable();
            $table->text('work_whatsapp_legal_extra')->nullable();
            $table->text('work_telegram')->nullable();
            $table->text('work_vk')->nullable();
            $table->text('work_vk_extra_source')->nullable();
            $table->text('work_instagram')->nullable();
            $table->text('work_ok')->nullable();
            $table->text('work_ok_extra_source')->nullable();
            $table->decimal('avg_sold_products_per_day', 12, 2)->nullable();
            $table->text('wb_page_url')->nullable();
            $table->text('working_mobile_phones')->nullable();
            $table->text('working_landline_phones')->nullable();
            $table->text('working_email')->nullable();
            $table->unsignedBigInteger('sold_products')->nullable();
            $table->date('wildberries_registered_at')->nullable();
            $table->string('organization_status')->nullable()->index();
            $table->date('fns_registered_at')->nullable();
            $table->text('address')->nullable();
            $table->string('region')->nullable()->index();
            $table->text('activity_type')->nullable();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->unsignedBigInteger('contact_id')->nullable()->index();
            $table->boolean('is_exported')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};

