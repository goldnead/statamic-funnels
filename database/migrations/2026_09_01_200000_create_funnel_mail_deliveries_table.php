<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Was ein Mail-Knoten ausgeloest hat, je Besuch.
     *
     * Eine eigene Tabelle und keine Zeile in `funnel_step_events`: die Ereignisse
     * dort sind Wegmarken von Menschen, und ein Mail-Knoten ist keine Station,
     * an der jemand steht. Wer die Abbruchstatistik ueber `funnel_step_events`
     * baut, darf hier nichts mitzaehlen.
     */
    public function up(): void
    {
        if (Schema::hasTable('funnel_mail_deliveries')) {
            return;
        }

        Schema::create('funnel_mail_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('funnel_visits')->cascadeOnDelete();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
            $table->string('node_key', 64);

            // Der Slug der Vorlage zum Zeitpunkt des Ausloesens. Der Knoten
            // kann spaeter eine andere waehlen; die Zeile sagt, welche ging.
            $table->string('template', 191)->nullable();
            $table->string('to', 191)->nullable();
            $table->string('brand_id', 64)->nullable()->index();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // Einmal je Besuch und Knoten. Das ist die Idempotenz, nicht eine
            // Pruefung im Code, die ein zweiter Prozess ueberholen koennte.
            $table->unique(['visit_id', 'node_key'], 'funnel_mail_deliveries_once');
            $table->index(['funnel_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_mail_deliveries');
    }
};
