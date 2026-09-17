<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a reminder came from.
 *
 * `null` means a person set it. Anything else means a rule generated it from
 * the task's due date, and the rule owns it: it moves the row when the
 * deadline moves and removes it when the deadline goes away.
 *
 * Without this column the two are indistinguishable, and the daily
 * reconciliation would either duplicate its own rows on every run or start
 * rewriting reminders a person set by hand. Both are worse than a column.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('tasks.tables.task_reminders', 'task_reminders');

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'source')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('source')->nullable()->after('note');

            // The reconciliation's own question: "is there already a generated
            // row of this kind on this task?"
            $blueprint->index(['task_id', 'source']);
        });
    }

    public function down(): void
    {
        $table = config('tasks.tables.task_reminders', 'task_reminders');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'source')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropIndex(['task_id', 'source']);
            $blueprint->dropColumn('source');
        });
    }
};
