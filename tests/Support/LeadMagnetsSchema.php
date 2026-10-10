<?php

namespace Goldnead\StatamicFunnels\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two tables the lead-magnets stand-in (`tests/Fakes/lead-magnets.php`)
 * writes to. Not the sibling's schema: just what the bridge reads.
 */
final class LeadMagnetsSchema
{
    public static function create(): void
    {
        Schema::create('fake_lm_resources', function (Blueprint $table): void {
            $table->id();
            $table->string('handle')->unique();
            $table->string('title');
            $table->boolean('published')->default(true);
            $table->boolean('requires_confirmation')->default(true);
        });

        Schema::create('fake_lm_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('resource_id');
            $table->string('email');
            $table->string('status')->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['resource_id', 'email']);
        });
    }
}
