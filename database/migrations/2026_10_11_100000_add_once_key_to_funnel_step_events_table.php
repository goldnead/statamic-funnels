<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ein Ereignis, das es je Besuch und Schritt nur einmal geben darf.
     *
     * Die meisten Wegmarken duerfen sich wiederholen (ein Neuladen ist kein
     * neuer Besuch, aber auch kein Fehler). „Bestaetigt" darf es nicht: ein
     * Mail-Scanner und der Klick treffen den Rueckweg im selben Augenblick,
     * und eine Pruefung im Code („gibt es das schon?") ueberholt der zweite
     * Prozess. Der Unique-Index ist die Idempotenz. NULL fuer alle anderen
     * Zeilen; mehrere NULL kollidieren in keiner Datenbank.
     */
    public function up(): void
    {
        if (Schema::hasColumn('funnel_step_events', 'once_key')) {
            return;
        }

        Schema::table('funnel_step_events', function (Blueprint $table) {
            $table->string('once_key', 120)->nullable()->unique('funnel_step_events_once');
        });
    }

    public function down(): void
    {
        // Tolerant of a table that is already gone or already without the
        // column: a rollback that dies half way helps nobody.
        if (! Schema::hasTable('funnel_step_events') || ! Schema::hasColumn('funnel_step_events', 'once_key')) {
            return;
        }

        Schema::table('funnel_step_events', function (Blueprint $table) {
            if (Schema::hasIndex('funnel_step_events', 'funnel_step_events_once')) {
                $table->dropUnique('funnel_step_events_once');
            }

            $table->dropColumn('once_key');
        });
    }
};
