<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // Id stabile della persona nel database del SaaS (dal token customer_sso); email e nome restano come copia
            $table->string('customer_id', 64)->nullable()->after('customer_email')->index();
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });
    }
};
