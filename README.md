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
        // Your own model, not the package's — yours extends it and carries
        // your fields. Leave this out and the query returns package models,
        // and a `url` closure typed to your class gets handed the wrong one.
        model: \App\Models\Task::class,
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

## Other systems' tasks

Four Peppermint systems hold tasks, and they are not the same tasks: the
Verwaltung's are tickets, the CRM's hang off deals, the Manager's have projects
and estimates. Yet the question somebody asks is one question — "what do I have
to do?" — and answering it means showing all of them in one list.

A **task source** is another system's half of that list.

```php
// config/tasks.php
'sources' => [App\Tasks\ManagerSource::class],
```

Everything below is about not building that source four times.

### Fetched, never stored

The tasks stay where they belong. `ForeignTask` is a value object, not a model:
no table, no migration, nothing to reconcile. A copy here would be a second
truth, and the question of which one wins would have no good answer — the same
stance the calendar takes with foreign events ("never persisted").

So there is no sync, no cron, nothing to clean up when a product is switched
off. The list asks while it draws.

### The mechanics are shared, the way out is not

`RemoteTaskSource` is the mechanics: ask, translate, tell an outage from an
empty answer, resolve the person, pass the remaining fields through. That part
is identical in every application — it existed three times, once per product,
before it moved here.

The way to the other system is not identical, and that is the whole reason for
the split:

| Application | How it calls |
|---|---|
| AI Brain (hub) | its own product gateway |
| a product | the bridge (`AiBrain::gateway(...)`) |
| anything else | something else |

Were the way inside the source, the source would exist once per application.
Were the hub's types inside the package (`ConnectedProduct`, `ProductGateway`),
every other product would carry hub fields it has no use for — and that is the
worse duplication of the two.

So the way lives behind `RemoteTaskSystem`, a contract with four questions only
the host can answer: **what is it called** (`key`, `label`), **what does it
offer** (`offers`), **who is this person over there** (`identify`), and
**fetch it** (`call`).

`RemoteTaskSource` therefore knows no single system. It does not know that there
is a Manager, and it knows none of its field names. The moment it knew one, it
would be the special-purpose client again.

### Why the way out is a closure

```php
use Peppermint\Tasks\Sources\CallableRemoteTaskSystem;
use Peppermint\Tasks\Sources\RemoteTaskSource;

new RemoteTaskSource(new CallableRemoteTaskSystem(
    key: 'verwaltung',
    label: 'Verwaltung',
    capabilities: ['tasks.list', 'tasks.kinds.list'],
    transport: fn (string $operation, array $data) => $client->call($operation, $data, 'peppermint-verwaltung'),
    identify: fn (int $userId) => User::find($userId)?->email,
));
```

A contract wants fulfilling, and at the first consumer the fulfilment looked
product-specific. At the second it was character-for-character the same, bar the
user model and the auth guard — so it moved into the package before the second
copy started drifting with the first fix.

The closure is not a trick to avoid an interface. It is the exact description of
what differs: *give me an answer to an operation*. Requiring a transport
dependency instead would force a gateway on every consumer, and two out of three
have a different one. The application writes one line; whoever needs more
fulfils `RemoteTaskSystem` itself.

### A source that cannot answer does not exist

`isAvailable()` asks `offers('tasks.list')` — measured against the other side's
capability register, not configured here.

That is the answer to "not switched on", and it has no second place to maintain:
a system that does not hand out its tasks never appears as a filter chip, never
as an empty section, never as a choice in a form. Lose the tool over there and
the source disappears by itself; nobody has to remember to switch it off.

Asked every time, not cached. Access can be withdrawn between two page loads,
and a remembered "yes" shows a section that then cannot be filled.

### An outage is not an empty list

Both of these are an empty array, and they mean opposite things:

- **"no open tasks"** — the person is done there. "Nothing to do" is correct.
- **"was not reachable"** — unknown. "Nothing to do" is a lie, and whoever
  believes it plans their day around tasks they still have.

`collect()` cannot tell them apart, on purpose: it logs the failure, leaves the
source out, and returns what the others gave. For a page that draws a list that
is right — a blank page because somebody else's server is slow is worse than an
incomplete one.

A form needs the difference, and a log line does not reach the person looking at
the screen. So `harvest()` returns it:

```php
$ernte = $register->harvest($userId);

$ernte->tasks;        // array<ForeignTask>, every source that answered
$ernte->ok;           // keys that answered — including with nothing
$ernte->failed;       // key => reason, the other end's words
$ernte->complete();   // nobody failed
$ernte->empty();      // really nothing open, as opposed to nothing visible
```

**Empty `tasks` with empty `failed` means really nothing open.** That is the one
sentence a mask may rely on, and the only reason `TaskHarvest` exists.

`collect()` keeps its old shape — the hub calls it, and nothing there should
change because a form needed more detail. It asks `harvest()` for its answer and
drops everything but the tasks, so the two cannot drift.

### The permission check lives over there

The other system owns its tasks, knows which of them are private, and is the
only place that can still be right after somebody over there changes a rule. A
second rights list in this package would age without anyone noticing it had.

What this package does instead is ask in the name of a **named person**:

```php
if ($this->remote->identify($userId) === null) {
    return [];   // the operation does not take place
}
```

`null` means an empty list, always — never "then show everything". That
direction is not interchangeable: the calendar's Bug #657 was an argument that
EXPIRED when the identity was missing, which made the answer *wider* instead of
narrower, and an unknown person got the whole team calendar. The same shape of
mistake here hands somebody else's tasks to whoever is logged in.

And an empty answer says nothing about permissions. It means "this person has no
open tasks there" — never "may see none", never "the system is down".

Whoever implements the contract for a product is implementing the half that
trusts, so the other half has to be worth trusting. It has gone wrong twice, and
both times quietly (Wiki #595):

| Where | What it did |
|---|---|
| Manager `/emails/search-tasks` | handed out every matching task, private ones included |
| Verwaltung `mailbox/ticket-search` | asked `canEdit()` — the mail browser's gate — instead of "may read tickets" |

Both were harmless for as long as they were only reachable inside their own
product. A source makes them reachable from outside, and that is the moment a
lax check becomes a leak.

### Fields are passed through, not enumerated

A foreign task carries what any task carries — title, status, priority, due date,
urgency, url, kind — and then whatever its own product has to say: the
Verwaltung sends `ticket_number` and `customer`, the CRM `category`, the Manager
`description`, `project_id`, `assigned_to` and twenty more.

Those travel in `extra`, unexamined.

Three fields used to stand here by name, and everything else fell away silently
— while the CRM was already sending `category` and the Verwaltung
`ticket_number`. Nothing reported anything. That is a known class of bug:
enumerating fields breaks again with every new field, and QUIETLY, because the
tool reports success and the interface still does not show it. The search then
happens at the wrong end.

So the rule is the other way round: what `ForeignTask` carries as a field of its
own is consumed, the rest travels on. A product that sends something new
tomorrow needs no change here.

Two consequences worth knowing:

- **No field in the wire format that no product fills.** `due_date` comes from
  the Manager and the CRM but not the Verwaltung (a ticket there has none);
  `status_label` only from the Manager. "If present, then with it" — the rest
  simply arrives in `extra`.
- **Only flat values.** `extra` is displayed, not evaluated, and a nested
  structure arrives in the row as "[object Object]". The two known objects
  (`project`, `assigned_to`) are flattened to their `name`.

Colliding keys are kept out rather than overwritten: unlike the calendar's
`ExternalEvent`, `ForeignTask` does not throw them away, so the same value must
not be put in twice — once as a core field and once as alleged extra
information, differing.

**And the url is only ever the product's own.** There used to be a fallback
beside it — web address plus `/tasks/{id}` — which was right for the Manager and
wrong for the Verwaltung, whose tasks are tickets under `/tickets/{id}`. It was
noticed by accident. A system that sends no address gets no link and the
interface shows text; a link to a 404 looks like a working route and costs the
reader the detour before they find out it was not one.

### Kinds come from the system that defined them

```php
$source->kinds();   // array<ForeignKind>, via `tasks.kinds.list`
```

Kinds are product-owned — this package knows neither `ticket` nor `routine`.
Guessing would be the worse answer: a wrong kind files the task in the wrong
drawer, and that only shows up over there. A system that does not offer the
operation gets an empty list, and the caller falls back on its default.

`requires` travels with each kind so a form can ask for those fields up front
instead of showing the error after sending — the Manager's `routine` requires
`assigned_to`.

A failure here stays quiet, unlike `tasks()`: a missing list of kinds costs a
form its preselection, which is a worse form and not a wrong one. Taking the
whole source down for it would be the larger damage.

### Read-only, for now

`RemoteTaskSource` extends `TaskSource`, not `WritableTaskSource`. The writing
half exists in the package's contract (`create`, `changeStatus`, `delete`) and
can be built on the same mechanics later; shipping it along the way would mean a
write path into other people's systems that nothing has exercised.

Which direction fails loudly is already settled, and the two are not symmetric:
reading swallows an outage, writing must not. Minutes pass between "page opened"
and "save pressed", and a task somebody believes they created and that exists
nowhere is worse than an error message. `ForeignSourceFailed` carries that.

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

## Foreign tasks about a record, not about a person

`TaskSource::tasks(int $userId)` answers "my open tasks, from everywhere". The
other question a page asks is closed: *what belongs to this order?* That is
`tasksForSubject(string $type, string|int $id)`.

```php
$answer = app(TaskSourceRegistry::class)->collectForSubject('order', $order->id);

$answer->tasks;            // array<ForeignTask>, merged across systems
$answer->isIncomplete();   // did a system fail to answer?
$answer->missingSources(); // which ones
```

### Tasks hang on their own record — never on the case file

The Verwaltung task on its order, the Manager task on its project, the CRM task
on its deal. Whoever wants everything belonging to one case resolves the case
file into its references first, then asks each system about ITS record.

Three things follow, and all three are why it is not the other way round:

1. A task that existed before the case file does not have to be moved — it is
   found the moment the case file adopts its record.
2. This package never has to know what a case file is. It gets a type and an
   id, which is what the extension point is for. A shared package that had to
   know a word from one company's world would be cut wrong.
3. It works without a case file at all. An order with no predecessor and no
   successor has none and needs none; its tasks still hang correctly.

`$type` is the OTHER system's word for its own record. Translating it here
would make this package the place where three vocabularies are reconciled —
the same mistake as translating status words, and for the same reason.

### An empty answer and a failed one are different

`collect()` logs a broken source and moves on, deliberately: that list is long,
and one missing chip is invisible next to a blank page.

`collectForSubject()` must not do that. The list is short and the question is
closed — three entries where there should be five looks exactly like a complete
answer, and there is nothing on the page to notice. So the failure travels back
as data (`SubjectSourceFailure`, carrying one of `not_offered`, `not_allowed`,
`unreachable`, `tool_failed`) and the interface says which system is missing.

Still not thrown: the remaining sources keep showing.

### Asking the other products: one source per answering system

```php
// config/tasks.php — the one place a product names its sources.
'sources' => [
    App\Tasks\Sources\AiBrainTaskSource::class,     // a single source, as before
    Peppermint\Tasks\Sources\BrueckenSubjektquellen::class,  // "go and ask"
],
```

A **class name, never a closure**: `config:cache` runs on every deploy and
cannot serialise one. Colours and guard come from a container binding in the
product, the same way the calendar binds `BrainCalendarSources('web')` — and for
the second reason stated there too: a binding can be replaced in a test, a `new`
at the call site cannot, and such a test "would keep reporting green while
checking nothing".

Colours are keyed by the **short key** (`['manager' => '#f59e0b']`), because that
map already exists as `config('calendar.source_colours')`. A colour marks the
SYSTEM, not the feature — the same system should look the same in the calendar
grid and in a task list. Pass the existing map; do not grow a second one. No map
is a fine answer: `colour()` returning null means "the application decides",
which beats an invented colour standing next to an established one.

An entry may now also be a `MeldetQuellenAn` and register SEVERAL sources,
because how many there are is only known at runtime. The alternative was a thin
subclass per neighbour: three products with two neighbours each is six classes
doing the same thing.

The register is a lazily resolved singleton, and the registrar asks the guard
**before** the wire: a console command, a queue job and an anonymous request get
an empty list without any HTTP call. Not out of thrift — without a person there
is nobody whose foreign tasks are meant. Same order as
`Peppermint\Calendar\Sources\BrainCalendarSources`; the guard is nameable,
because a product can have several (`new BrueckenSubjektquellen([], 'web')`).

A failure asking is an empty list, not an exception: the product's own sources
stay.

Who answers is **measured, not configured**: `AiBrain::providers()` asks the
capability register in Brain. A product that joins appears by itself; one that
loses the capability disappears — nobody has to remember a switch. Its own
system is never in that list, and neither is a system this one may not call, so
the grant list is not kept twice.

One source per answering system, because each gets its own chip. Which system
said what is content: the status words of three systems are not the same, and
merging them under one heading would claim an equality that does not exist.

`peppermint/ai-brain-bridge` is a `suggest`, not a `require` — same promise as
the calendar bridge. Without it the package works and there are simply no
foreign sources; `BrueckenSubjektquelle` is then never loaded, because
autoloading is lazy.

This replaces the shape it would otherwise have taken: three products with two
neighbours each is six hand-written classes doing the same thing. That exact
thing stood in the mailbox package — five hand-written sources across two
products, four of them identical — until `BridgeWerkzeugquelle` replaced them.

### A subject-only source gets no person chip

`supportsPerson()` defaults to true, so no existing source has to say anything.
`BrueckenSubjektquelle` says false: "my tasks over there" already flows
product → Brain → product, and a second path to the same rows would be a second
truth to keep in step.

The registry then neither asks it in `collect()` nor lists it in `forPersons()`.
An always-empty chip is not a small blemish — it reads as "nothing of mine over
there", which is a claim, not an absence.

### Why `tasksForSubject()` is not abstract

Because making it abstract would have broken every source in every product the
moment this package moved. `supportsSubjects()` defaults to false, and a source
that says no is never asked — rather than asked and quietly returning nothing,
which is the one answer that cannot be told apart from a real empty result.

Called anyway, the default implementation throws. Loud in development beats
wrong in production.
