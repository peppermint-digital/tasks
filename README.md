# peppermint/tasks

Task core for Laravel. Tasks, hierarchy, application-defined status and priority
vocabularies, and a Taskwarrior-style urgency engine whose factors each
application contributes itself.

Companion to [`peppermint/calendar`](https://github.com/peppermint-digital/calendar) —
and deliberately independent of it. Neither package requires the other.

## What is in the core, and why

The yardstick for the core is **iCalendar VTODO**, not our own screens. A field
that a task can carry out of the house belongs here; one that only makes sense
inside one product does not.

| Ring | Fields |
|---|---|
| 1 — mandatory | `title`, `status` |
| 2 — core standard (VTODO) | `description`, `priority`, `due_date`, `completed_at`, `parent_id`, `assigned_to` |
| 3 — per product | everything else: profile table, or `subject_type`/`subject_id` |

### Fields that do NOT belong in the core

Named here because the calendar package learned this the expensive way:

- **`estimated_hours`** — one product's way of sizing work. Its own table.
- **`project_id`** — both products have projects, and they are different
  projects. Application field; the "has a project" urgency factor is registered
  by whoever has projects.
- **`deadline`** as a second date — the Manager weighs it 1.5x above `due_date`.
  That is a product's opinion about its own dates. It registers a second urgency
  factor and reuses `DueDateFactor::proximity()` for the curve.
- **Tags** — a JSON column in one product, a relation with a `next` flag in the
  other. Same word, different thing.

**A new column in the shared table is always a mistake.** It travels into every
product that will never need it.

## Status and priority are registries, not enums

The two Peppermint systems overlap in exactly one status value (`in_progress`)
and three of five priorities. An enum in the package would force our words on
every consumer — and it would not even hold up here.

An application registers its own vocabulary. What the entries must agree on is
their **VTODO meaning**, because export, the terminal check and any comparison
across systems hang on it.

```php
// config/tasks.php
'statuses'   => [Todo::class, InProgress::class, Done::class],
'priorities' => [Low::class, Medium::class, High::class],
```

## Urgency

A weighted sum whose parts stay visible — `urgencyBreakdown()` returns the score
*and* the reason for it. A number alone never shows that a coefficient is wrong.

**It is computed, never stored.** Age and proximity to a due date change without
anyone writing to the row; a stored column would be wrong by the first night. If
ordering by urgency gets expensive, the answer is a cache with an expiry.

The package ships the four factors that need nothing but the task itself —
priority, due date, status, age. Everything else the application registers,
because everything else reaches into something the package does not know:

```php
'urgency_factors' => [
    ...,
    App\Urgency\ScheduledFactor::class,  // reaches into calendar time blocks
    App\Urgency\BlockedFactor::class,    // reaches into a blocker relation
],
```

This is not purity for its own sake. Wiring "scheduled" into the package would
mean `peppermint/tasks` could not ship without `peppermint/calendar`, and a
calendar could not ship without a task manager.

## Adopting an existing table

A package that only runs on fresh tables cannot take on a grown product — and
then it gets built twice.

```php
'tables'  => ['tasks' => 'tasks'],
'columns' => ['tasks' => ['due_date' => 'faellig_am']],
'run_migrations' => false,
```

The product extends the model, it does not copy it:

```php
class Task extends \Peppermint\Tasks\Models\Task { /* own relations */ }
```
