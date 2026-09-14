<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The core table — Ring 1 and Ring 2 only.
 *
 * Deliberately narrow. Everything a single product needs goes into that
 * product's own table or a 1:1 profile; a column added here travels into every
 * consumer that will never use it.
 *
 * Products that already have a `tasks` table set `run_migrations => false` and
 * map their column names instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('tasks.tables.tasks', 'tasks');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();

            // Ring 1 — without these a task is not a task.
            $blueprint->string('title');
            $blueprint->string('status');

            // Ring 2 — measured against iCalendar VTODO.
            $blueprint->text('description')->nullable();
            $blueprint->string('priority')->nullable();
            $blueprint->dateTime('due_date')->nullable();
            $blueprint->dateTime('completed_at')->nullable();
            $blueprint->foreignId('parent_id')->nullable()->constrained($blueprint->getTable())->nullOnDelete();
            $blueprint->unsignedBigInteger('assigned_to')->nullable();

            // The handle an application hangs its own meaning on, without a
            // column of its own in here.
            $blueprint->string('subject_type')->nullable();
            $blueprint->unsignedBigInteger('subject_id')->nullable();

            $blueprint->timestamps();

            $blueprint->index('status');
            $blueprint->index('due_date');
            $blueprint->index('assigned_to');
            $blueprint->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('tasks.tables.tasks', 'tasks'));
    }
};
