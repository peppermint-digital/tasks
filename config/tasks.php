<?php

use Peppermint\Tasks\Urgency\Factors\AgeFactor;
use Peppermint\Tasks\Urgency\Factors\DueDateFactor;
use Peppermint\Tasks\Urgency\Factors\PriorityFactor;
use Peppermint\Tasks\Urgency\Factors\StatusFactor;

return [
    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | The package has no user management of its own — it only holds a reference.
    */
    'user_model' => env('TASKS_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Status vocabulary
    |--------------------------------------------------------------------------
    |
    | Every application registers its own; the package ships none. Two systems
    | may legitimately call the same thing "open" and "todo" — forcing a shared
    | word would mean rewriting every row, query and filter of one of them for a
    | cosmetic gain.
    |
    | What they MUST agree on is the iCalendar VTODO equivalent, because that is
    | the external yardstick: it decides what "done" means for export and for
    | any comparison across systems.
    |
    | Each entry is the class name of a Peppermint\Tasks\Status\TaskStatus.
    */
    /*
    | The kinds of task this application has. Empty is a valid answer — a
    | product with one sort of task needs none, and a kind that forbids nothing
    | is a label wearing the word.
    */
    'kinds' => [],

    'statuses' => [],

    /*
    |--------------------------------------------------------------------------
    | Priority vocabulary
    |--------------------------------------------------------------------------
    |
    | Same principle. The urgency coefficient lives on the entry, not in a
    | central table — a priority that no application registered has no weight.
    |
    | Each entry is the class name of a Peppermint\Tasks\Priority\TaskPriority.
    */
    'priorities' => [],

    /*
    |--------------------------------------------------------------------------
    | Urgency factors
    |--------------------------------------------------------------------------
    |
    | The engine is shared, the factors are not. Nine of the fourteen factors
    | measured in the Peppermint Manager reach into application-owned relations
    | — "scheduled" reaches into calendar time blocks. Wiring those into the
    | package would chain two core packages together, and neither could then be
    | shipped without the other.
    |
    | The package ships the factors that need nothing but the task itself
    | (priority, due date, age, status). Everything else the application adds.
    |
    | Each entry is the class name of a Peppermint\Tasks\Urgency\UrgencyFactor.
    */
    'urgency_factors' => [
        PriorityFactor::class,
        DueDateFactor::class,
        StatusFactor::class,
        AgeFactor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Urgency coefficients
    |--------------------------------------------------------------------------
    |
    | Deliberately per application, not per package. A factor that means a lot
    | in one system may mean nothing in another: "scheduled" is decisive where
    | work is planned into a calendar and meaningless where it is not.
    |
    | The values below are the ones the Peppermint Manager has been running —
    | the only implementation that existed when this package was cut, and
    | therefore the reference.
    */
    'urgency' => [
        'due_date_max' => 12.0,
        'date_window_days' => 14,
        'age_per_day' => 0.05,
        'age_max' => 2.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Foreign task sources
    |--------------------------------------------------------------------------
    |
    | Tasks of other systems, shown alongside the local ones. Read-only: they
    | are displayed, never copied — a copy would be a second truth with a
    | reconciliation problem.
    |
    | Neither system is the centre. Each application registers the sources it
    | wants to see, the same way the calendar package does it.
    |
    | Each entry is the class name of a Peppermint\Tasks\Sources\TaskSource.
    */
    'sources' => [],

    /*
    |--------------------------------------------------------------------------
    | Tables
    |--------------------------------------------------------------------------
    |
    | For adopting tables that already exist under other names. A package that
    | only runs on fresh tables cannot take on a grown product — and then it
    | gets built twice.
    */
    'tables' => [
        'tasks' => 'tasks',
        'task_reminders' => 'task_reminders',
    ],

    /*
    |--------------------------------------------------------------------------
    | Columns
    |--------------------------------------------------------------------------
    |
    | Package field name => actual column name. Lets a product keep its
    | years-old column names while the package speaks its own.
    */
    'columns' => [
        'tasks' => [],
        'task_reminders' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deadline reminders
    |--------------------------------------------------------------------------
    |
    | A due date on its own tells nobody. These settings drive the daily
    | reconciliation that turns a deadline into real reminder rows — the same
    | rows a person sets by hand, delivered down the same path, dismissible in
    | the same list.
    |
    | `enabled` is false by default ON PURPOSE. Switching it on starts sending
    | notifications to real people; that is a product's decision, not a
    | package's, and a package that opts a product in by being installed is a
    | package that gets uninstalled.
    */
    'due_reminders' => [
        'enabled' => false,

        // How many days ahead the advance warning goes out. Null disables the
        // advance warning and leaves only the one on the day itself.
        'lead_days' => 1,

        // Local wall-clock hour for both. Morning, because a reminder that
        // arrives after the working day has started is a reminder about
        // something already missed.
        'hour' => 8,

        // The zone that hour is meant in. The column stores UTC; without this
        // "08:00" would arrive two hours late in summer.
        'timezone' => 'Europe/Berlin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | false for a product that already has its table and only adopts the
    | package on top of it.
    */
    'run_migrations' => true,
];
