<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gadya_connect_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('portal_url');
            $table->text('secret');
            $table->string('site_name')->nullable();
            $table->string('client_name')->nullable();
            $table->boolean('sso_enabled')->default(true);
            $table->timestamp('paired_at')->nullable();
            $table->timestamp('last_report_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gadya_connect_connections');
    }
};
