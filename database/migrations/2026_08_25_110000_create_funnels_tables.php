<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A funnel is a path a person walks.
     *
     * That is the whole difference to an automation, and it is why this addon
     * has its own tables rather than new node types in that one. An automation
     * is *event → action*: something happened, do something. A funnel is a
     * sequence of places a visitor is actually standing in, which means it has
     * two things an automation never needs — a page, and a record of where each
     * person got to.
     */
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->string('handle', 191)->unique();
            $table->string('title', 191);
            $table->text('description')->nullable();

            // A funnel that is not live answers 404 on the front end. Not "shows
            // a warning": a half-built funnel handed to a visitor is worse than
            // a missing page, because it takes money in the middle.
            $table->boolean('published')->default(false)->index();

            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('funnel_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();

            // The key the graph refers to. Generated in the browser while
            // editing, so it cannot be the database id — a node exists on the
            // canvas before it has ever been saved.
            $table->string('node_key', 64);

            $table->string('type', 64)->index();
            $table->string('label', 191)->nullable();

            // Part of the URL a visitor sees: /f/{funnel}/{slug}. Null for step
            // types that are not a place anybody stands in.
            $table->string('slug', 191)->nullable();

            $table->json('config')->nullable();
            $table->boolean('disabled')->default(false);
            $table->timestamps();

            $table->unique(['funnel_id', 'node_key']);
            $table->unique(['funnel_id', 'slug']);
        });

        Schema::create('funnel_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();

            $table->string('from_node_key', 64);
            $table->string('to_node_key', 64);

            // Which way out of the source step this edge leaves by: `default`,
            // or `accepted` / `declined` on an offer. The names come from the
            // node's own output declaration, evaluated by the shared canvas.
            $table->string('from_output', 64)->default('default');

            $table->timestamps();

            $table->unique(['funnel_id', 'from_node_key', 'from_output', 'to_node_key'], 'funnel_edges_unique');
        });

        Schema::create('funnel_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();

            // Who is walking. A random token in the visitor's own cookie, not an
            // identifier of a person: a funnel has to work before anybody has
            // told it their name, and most visitors never do.
            $table->string('token', 64)->index();

            $table->string('current_node_key', 64)->nullable();

            // Filled in the moment they hand it over, and only then.
            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();

            // Which payment came out of this walk, if one did. Nullable because
            // most walks end without one, and that is the ordinary case.
            $table->unsignedBigInteger('payment_id')->nullable()->index();

            $table->timestamp('completed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['funnel_id', 'token']);
        });

        Schema::create('funnel_step_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('funnel_visits')->cascadeOnDelete();
            $table->string('node_key', 64);

            // `entered`, `submitted`, `accepted`, `declined`, `completed`. What
            // a funnel is judged by is where people stop, and that question
            // needs one row per step per visitor — unlike an offer's counters,
            // where nobody ever asks which visitor.
            $table->string('event', 32)->index();

            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'node_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_step_events');
        Schema::dropIfExists('funnel_visits');
        Schema::dropIfExists('funnel_edges');
        Schema::dropIfExists('funnel_steps');
        Schema::dropIfExists('funnels');
    }
};
