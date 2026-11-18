<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Wie oft `funnels:retry-mails` eine Mail schon erneut gestellt hat. */
    public function up(): void
    {
        if (! Schema::hasTable('funnel_mail_deliveries') || Schema::hasColumn('funnel_mail_deliveries', 'attempts')) {
            return;
        }

        Schema::table('funnel_mail_deliveries', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('funnel_mail_deliveries', 'attempts')) {
            Schema::table('funnel_mail_deliveries', function (Blueprint $table) {
                $table->dropColumn('attempts');
            });
        }
    }
};
