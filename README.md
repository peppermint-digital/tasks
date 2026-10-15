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

## The calendar bridge

A task has a due date and a planned timespan. The calendar is the instrument for
setting and seeing them — so this package depends on **`peppermint/calendar`**,
never the other way round.

```php
use Peppermint\Tasks\Calendar\TaskBlockKind;

class ManagerTaskKind extends TaskBlockKind
{
    public function key(): string           { return 'manager_task'; }
    public function profileModel(): ?string { return TimeBlockProfile::class; }
}
```

Two answers, and the kind exists. `label()`, `subjectModel()` and the field name
come from here; `subjectKey()` derives `task_id` from the model, so nothing
restates it.

### Why this direction, and not the other

Point the arrow the other way and the calendar has to know what a task is. Then
what a ticket is. Then what a deal is. That is the version of the calendar that
cannot be installed by anyone who has none of those.

`PlannedBlockKind` over there describes *something planned* and nothing more:
"a task, a ticket, a deal: the event is not the thing itself, it is its
scheduling." The core carries `subject_type` / `subject_id`, and that is all it
knows about the subject.

### Due dates in the calendar, without creating events

A due date is not an appointment — it is a property of the task. Store an event
for it and it exists twice: move the task and the event must follow, move the
event and the task must follow, and one of those directions eventually gets
forgotten.

`TaskDeadlineSource` reads instead of storing:

```php
// config/calendar.php
'sources' => [
    new TaskDeadlineSource(
        url: fn (Task $task) => route('tasks.show', $task),
    ),
],
```

The grid asks while paging, nothing is written, and a moved due date shows up on
the right day immediately.

This is the same mechanism the calendar uses for other systems' events — except
nothing crosses the network here. `EventSource` asks for exactly three answers
and none of them presumes another system.

Two decisions worth knowing: deadlines are drawn **all-day**, because the
package's own due-date logic compares whole days throughout
(`DueDateReminders` works off `startOfDay()`) — drawing one at 14:37 would
promise a precision that does not exist and would hide the appointments that
really happen at that hour. And **only open tasks** appear: a completed task has
no deadline, it has a date.

Pass `scope` when "whose deadlines" is not simply "assigned to this user" — a
tenant, a team, a project. Without that hook every application with a different
rule would have to rebuild the source.

### What stays with the product

**The key**, because it lives in the data (`calendar_events.kind`) and in users'
saved filters. One shared key across systems would make a Manager block look
like a local one inside the CRM.

**The profile**, because the table is the product's.

### What this replaced

Measured on 15.10.2026, three systems had built nearly the same kind:

| System | Class | Lines |
|---|---|---|
| Manager | `ManagerTaskKind` | 77 |
| CRM | `AufgabenZeitblockArt` | 60 |
| Verwaltung | `TicketZeitblockArt` | 68 |

All of them declared `rules() => ['task_id' => 'required']` — restating what
`subjectKey()` already derives.

### The coupling is optional

`suggest`, not `require`. Install this package without the calendar and you get
tasks without scheduling; the bridge classes are never loaded, because
autoloading is lazy.

That is the same promise the calendar keeps: it runs without AI Brain and
without the comm layer.

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
