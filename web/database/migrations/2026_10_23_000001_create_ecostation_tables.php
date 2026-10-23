<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->timestamps();
        });

        Schema::create('teacher_class', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $t->primary(['user_id', 'class_id']);
        });

        Schema::create('students', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $t->timestamps();
            $t->unique(['name', 'class_id']);
        });

        Schema::table('users', fn (Blueprint $t) => $t->foreignId('student_id')->nullable()->constrained()->nullOnDelete());

        Schema::create('cards', function (Blueprint $t) {
            $t->id();
            $t->string('credential_uid')->unique();
            $t->string('kind')->default('physical');
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->string('status')->default('active');
            $t->timestamps();
        });

        Schema::create('readers', function (Blueprint $t) {
            $t->id();
            $t->string('label');
            $t->string('api_key')->unique();
            $t->timestamps();
        });

        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('card_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reader_id')->constrained()->cascadeOnDelete();
            // Denormalized for realtime class-scoped feeds; nullable (unassigned students).
            $t->foreignId('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $t->timestamp('occurred_at');
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->index(['card_id', 'occurred_at']);
            $t->index('occurred_at');
        });

        Schema::create('recycling_deposits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->unique()->constrained('events')->cascadeOnDelete();
            $t->string('image_path')->nullable();
            $t->string('material_class');
            $t->float('confidence');
            $t->integer('points_awarded');
            $t->boolean('is_bottle')->default(false);
            $t->boolean('is_recyclable')->default(false);
            $t->timestamps();
        });

        Schema::create('points_ledger', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->integer('delta');
            $t->string('reason');
            $t->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $t->timestamps();
            $t->index(['student_id', 'created_at']);
        });

        Schema::create('pending_pairings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('reader_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('expires_at');
            $t->timestamp('consumed_at')->nullable();
            $t->string('last_rejected_uid')->nullable();
            $t->string('last_rejected_reason')->nullable();
            $t->timestamp('last_rejected_at')->nullable();
            $t->timestamps();
            $t->index(['consumed_at', 'expires_at']);
        });

        Schema::create('pending_captures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('reader_id')->constrained()->cascadeOnDelete();
            $t->string('image_path');
            $t->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $t->foreignId('card_id')->nullable()->constrained()->nullOnDelete();
            $t->string('state')->default('awaiting_card');
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->index(['reader_id', 'state', 'expires_at']);
        });

        Schema::create('recycling_updates', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->json('payload');
            $t->timestamps();
        });

        Schema::create('tap_feedback', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('reader_id')->nullable();
            $t->unsignedBigInteger('event_id')->nullable();
            $t->string('cue', 16);
            $t->string('reason', 32);
            $t->timestamps();
        });

        Schema::create('hce_credential_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('card_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('secret');
            $t->string('fingerprint', 16);
            $t->foreignId('provisioned_by_reader_id')->nullable()->constrained('readers')->nullOnDelete();
            $t->timestamp('provisioned_at');
            $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->json('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('student_id');
        });
        foreach (['settings', 'hce_credential_keys', 'tap_feedback', 'recycling_updates', 'pending_captures',
            'pending_pairings', 'points_ledger', 'recycling_deposits', 'events', 'readers', 'cards',
            'students', 'teacher_class', 'classes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
