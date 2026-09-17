<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminders on a task — several per task, dismissible, with a history.
 *
 * The foreign key points at whatever the product calls its task table
 * (`tasks.tables.tasks`). That matters: in the Peppermint Verwaltung the
 * tickets ARE the tasks and there is no `tasks` table at all. A hardcoded
 * reference would have shut that product out of the feature.
 *
 * Skipped when the table already exists — the Manager has carried one since
 * 12/2025 and adopts the package on top of it rather than being migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('tasks.tables.task_reminders', 'task_reminders');

        if (Schema::hasTable($table)) {
            return;
        }

        $aufgaben = config('tasks.tables.tasks', 'tasks');

        Schema::create($table, function (Blueprint $blueprint) use ($aufgaben) {
            $blueprint->id();

            $blueprint->foreignId('task_id')->constrained($aufgaben)->cascadeOnDelete();

            // Whose reminder it is. NOT a foreign key: the user table is named
            // per product (`tasks.user_model`), and a package that insists on
            // `users` locks out the product that calls them something else.
            $blueprint->unsignedBigInteger('user_id');

            $blueprint->dateTime('remind_at');

            // Optional wording. Without it a reminder still works — it points
            // at its task — but "before the customer call" is the difference
            // between a nudge and a note to nobody.
            $blueprint->string('title')->nullable();
            $blueprint->text('note')->nullable();

            // Dismissed rows STAY. They are the record that someone was
            // reminded and acted.
            $blueprint->boolean('is_dismissed')->default(false);
            $blueprint->dateTime('dismissed_at')->nullable();

            $blueprint->timestamps();

            // The query the delivery runs every few minutes: mine, due, open.
            $blueprint->index(['user_id', 'remind_at', 'is_dismissed']);
            $blueprint->index(['task_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('tasks.tables.task_reminders', 'task_reminders'));
    }
};
