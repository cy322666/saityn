<?php

use App\Models\AmoExportRecord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amo_export_records', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('price')->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default(AmoExportRecord::STATUS_PENDING)->index();
            $table->string('amo_lead_id')->nullable()->index();
            $table->timestamp('exported_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amo_export_records');
    }
};

