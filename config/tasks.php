<?php

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
        Peppermint\Tasks\Urgency\Factors\PriorityFactor::class,
        Peppermint\Tasks\Urgency\Factors\DueDateFactor::class,
        Peppermint\Tasks\Urgency\Factors\StatusFactor::class,
        Peppermint\Tasks\Urgency\Factors\AgeFactor::class,
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
    | Tables
    |--------------------------------------------------------------------------
    |
    | For adopting tables that already exist under other names. A package that
    | only runs on fresh tables cannot take on a grown product — and then it
    | gets built twice.
    */
    'tables' => [
        'tasks' => 'tasks',
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
