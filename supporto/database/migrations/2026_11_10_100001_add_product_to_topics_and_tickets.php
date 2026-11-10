<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            // saas | plugin; vuoto = «Per tutti» (accesso, fatture, abbonamento)
            $table->string('product', 20)->nullable()->after('slug');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            // saas | plugin | billing (fatturazione e account)
            $table->string('product', 20)->nullable()->after('topic_id');
            // Piano e livello di assistenza copiati dall'abbonamento del cliente al momento dell'apertura
            $table->string('plan_slug', 60)->nullable()->after('product');
            $table->string('support_level', 20)->nullable()->after('plan_slug');
        });

        // Le guide esistenti sono tutte del plugin
        DB::table('topics')->update(['product' => 'plugin']);
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['product', 'plan_slug', 'support_level']);
        });
        Schema::table('topics', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
