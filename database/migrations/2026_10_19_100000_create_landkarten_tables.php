<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Landkarte: one named map with phase lanes, the objects pinned to them
     * and the connections drawn by hand.
     *
     * 1.25 gave every funnel its own map (`funnel_stages`). A Landkarte spans
     * funnels, automations, lead magnets and courses, so it belongs to itself,
     * not to one funnel. The old rows are copied across below, one Landkarte per
     * funnel that had phases, and left where they are.
     *
     * Derived connections are never stored: the owning addons compute them. Only
     * what a person draws by hand lives in `funnel_landkarte_edges`.
     */
    public function up(): void
    {
        if (! Schema::hasTable('funnel_landkarten')) {
            Schema::create('funnel_landkarten', function (Blueprint $table) {
                $table->id();
                $table->string('name', 191);
                // Set only on a map copied from a funnel's 1.25 map: how a second run knows it is done.
                $table->unsignedBigInteger('source_funnel_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('funnel_landkarte_stages')) {
            Schema::create('funnel_landkarte_stages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('landkarte_id')->constrained('funnel_landkarten')->cascadeOnDelete();
                $table->string('name', 191);
                $table->string('purpose', 191)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->index(['landkarte_id', 'position']);
            });
        }

        if (! Schema::hasTable('funnel_landkarte_refs')) {
            Schema::create('funnel_landkarte_refs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stage_id')->constrained('funnel_landkarte_stages')->cascadeOnDelete();
                $table->string('type', 64);
                $table->string('ref_id', 191);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->unique(['stage_id', 'type', 'ref_id'], 'funnel_landkarte_refs_unique');
                $table->index(['stage_id', 'position']);
            });
        }

        if (! Schema::hasTable('funnel_landkarte_edges')) {
            Schema::create('funnel_landkarte_edges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('landkarte_id')->constrained('funnel_landkarten')->cascadeOnDelete();
                $table->string('from_type', 64);
                $table->string('from_ref', 191);
                $table->string('to_type', 64);
                $table->string('to_ref', 191);
                $table->string('label', 191)->nullable();
                $table->timestamps();

                $table->unique(['landkarte_id', 'from_type', 'from_ref', 'to_type', 'to_ref'], 'funnel_landkarte_edges_unique');
            });
        }

        $this->copyFromFunnelMaps();
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_landkarte_edges');
        Schema::dropIfExists('funnel_landkarte_refs');
        Schema::dropIfExists('funnel_landkarte_stages');
        Schema::dropIfExists('funnel_landkarten');
    }

    /**
     * One Landkarte per funnel that had phases on its 1.25 map, named after the
     * funnel, with the same phases and cards in the same order. Runs once: a
     * second run finds the Landkarte and does nothing.
     */
    protected function copyFromFunnelMaps(): void
    {
        if (! Schema::hasTable('funnel_stages') || ! Schema::hasTable('funnel_stage_refs')) {
            return;
        }

        $funnelIds = DB::table('funnel_stages')->distinct()->pluck('funnel_id');
        $now = now();

        foreach ($funnelIds as $funnelId) {
            $funnel = DB::table('funnels')->where('id', $funnelId)->first();

            if ($funnel === null) {
                continue;
            }

            // Named after the funnel; two funnels with one title get the handle
            // added, so neither is lost. The name is also how a second run
            // recognises what it already copied.
            if (DB::table('funnel_landkarten')->where('source_funnel_id', $funnelId)->exists()) {
                continue;
            }

            // Named after the funnel; a title that is taken gets the handle added, so no funnel is lost.
            $name = DB::table('funnel_landkarten')->where('name', $funnel->title)->exists()
                ? $funnel->title.' ('.$funnel->handle.')'
                : $funnel->title;

            DB::transaction(function () use ($funnelId, $name, $now) {
                $landkarteId = DB::table('funnel_landkarten')->insertGetId(['name' => $name, 'source_funnel_id' => $funnelId, 'created_at' => $now, 'updated_at' => $now]);

                foreach (DB::table('funnel_stages')->where('funnel_id', $funnelId)->orderBy('position')->orderBy('id')->get() as $stage) {
                    $stageId = DB::table('funnel_landkarte_stages')->insertGetId([
                        'landkarte_id' => $landkarteId,
                        'name' => $stage->name,
                        'position' => $stage->position,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    foreach (DB::table('funnel_stage_refs')->where('funnel_stage_id', $stage->id)->orderBy('position')->orderBy('id')->get() as $ref) {
                        DB::table('funnel_landkarte_refs')->insert([
                            'stage_id' => $stageId,
                            'type' => $ref->type,
                            'ref_id' => $ref->ref_id,
                            'position' => $ref->position,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
        }
    }
};
