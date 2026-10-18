<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The map: phases and what is pinned to them.
     *
     * Phases are data, not graph. The graph is the path one visitor walks in a
     * session; a phase such as "Freebie" or "Kurs" spans days and several
     * addons, and nothing about it is a step. A reference stores only the
     * object's `type` and `id`: the owning addon is asked for the label, the
     * link, the switch and the number every time, so a copy of any of them
     * here could only go out of date.
     */
    public function up(): void
    {
        if (! Schema::hasTable('funnel_stages')) {
            Schema::create('funnel_stages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
                $table->string('name', 191);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->index(['funnel_id', 'position']);
            });
        }

        if (! Schema::hasTable('funnel_stage_refs')) {
            Schema::create('funnel_stage_refs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('funnel_stage_id')->constrained('funnel_stages')->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('ref_id', 191);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['funnel_stage_id', 'type', 'ref_id'], 'funnel_stage_refs_unique');
                $table->index(['funnel_stage_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_stage_refs');
        Schema::dropIfExists('funnel_stages');
    }
};
