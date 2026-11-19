<?php

namespace Peppermint\Tasks\Sources;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Peppermint\Tasks\Exceptions\ForeignSourceFailed;
use Peppermint\Tasks\Exceptions\SourceKeyTaken;
use Throwable;

/** The foreign task sources of THIS application. */
class TaskSourceRegistry
{
    /** @var array<string, TaskSource> */
    protected array $sources = [];

    /**
     * Eine Quelle anmelden — und bei belegtem Schlüssel laut werden.
     *
     * ## Warum das wirft, statt zu ersetzen (Bug #1466)
     *
     * Bis zum 13.11.2026 stand hier eine Zeile, die wortlos überschrieb. Das
     * hielt, solange niemand einen Fehler machte, und schwieg in dem Moment,
     * in dem es jemand tat: Im Peppermint Manager (#9887) nahm eine zweite
     * Quelle je System die erste weg, `forSubjects()` lief leer, und vier
     * Tests eines am selben Tag ausgerollten Vorgangs starben — ohne eine
     * Meldung, die gesagt hätte, woran es lag.
     *
     * Ein Schlüssel ist die Kennung EINES Systems. Zwei Quellen mit demselben
     * Schlüssel sind kein zu verfeinernder Fall, sondern ein falscher
     * Zuschnitt: Welche Fragen ein System beantwortet, ist seine Eigenschaft
     * und kein Grund für einen zweiten Eintrag.
     *
     * @param  bool  $ersetzen  Ausdrücklich ersetzen — für ein Produkt, das
     *                          eine Paketquelle durch seine eigene austauscht.
     *                          Ausdrücklich, weil die stille Ersetzung genau
     *                          das war, was niemand bemerkte.
     *
     * @throws SourceKeyTaken wenn der Schlüssel belegt ist und nicht ersetzt werden soll
     */
    public function register(TaskSource $source, bool $ersetzen = false): void
    {
        $key = $source->key();

        if (! $ersetzen && isset($this->sources[$key])) {
            throw SourceKeyTaken::by($key, $this->sources[$key], $source);
        }

        $this->sources[$key] = $source;
    }

    /**
     * Ausfälle beim ANMELDEN, getrennt nach der Frage, deren Liste dadurch
     * unvollständig ist (Bug #1513).
     *
     * @var array{persons: array<string, SourceFailure>, subjects: array<string, SourceFailure>}
     */
    protected array $registrationFailures = ['persons' => [], 'subjects' => []];

    /**
     * Festhalten, dass beim Anmelden etwas nicht gefragt werden konnte.
     *
     * ## Warum das Register das wissen muss (Bug #1513)
     *
     * Eine Quelle, die nie angemeldet wurde, fehlt in jeder Liste, ohne eine
     * Spur zu hinterlassen. Gemessen am 19.11.2026 mit dem echten Client: Ist
     * die Nabe beim Aufbau weg, steht keine Brückenquelle im Register — und
     * `harvest()` meldete daraufhin `complete`, mit leerem `failures`. Das ist
     * nicht bloß ein fehlender Grund, sondern eine falsche Behauptung: „das
     * ist alles", wo niemand gefragt werden konnte.
     *
     * Wer anmeldet (heute {@see BrueckenSubjektquellen::melden()}), meldet
     * deshalb hier, WAS nicht gefragt werden konnte. `harvest()` und
     * `collectForSubject()` tragen es in ihre Antwort, und `complete()` ist
     * dann falsch — so wie bei einer Quelle, die beim Ernten nicht antwortet.
     *
     * ## Nach Frage getrennt
     *
     * Die Personen-Liste und die Datensatz-Liste werden aus verschiedenen
     * Vorgängen gefüllt. Fällt nur einer aus, ist auch nur eine der beiden
     * Antworten unvollständig — und nur die darf es sagen. Ein Warnhinweis
     * an der Antwort, die vollständig ist, wäre die umgekehrte Lüge.
     *
     * Nicht betroffen sind `all()`, `available()`, `plannable()` und die
     * übrigen Listen: Sie antworten weiter, was angemeldet IST. Der Grund,
     * warum etwas fehlt, gehört an die Antwort, die eine Maske als vollständig
     * lesen könnte — und das ist die Ernte.
     *
     * @param  string  $for  `persons` oder `subjects`
     */
    public function failedToRegister(SourceFailure $failure, string $for): void
    {
        if (! array_key_exists($for, $this->registrationFailures)) {
            throw new InvalidArgumentException("[{$for}] is neither 'persons' nor 'subjects'.");
        }

        // Je Schlüssel EINE Meldung: Der erste Grund bleibt stehen. Zwei
        // Meldungen derselben Stelle für dieselbe Liste sagten nichts Neues.
        $this->registrationFailures[$for][$failure->sourceKey] ??= $failure;
    }

    /**
     * Was beim Anmelden nicht gefragt werden konnte.
     *
     * @param  string  $for  `persons` oder `subjects`
     * @return array<string, SourceFailure>
     */
    public function registrationFailures(string $for): array
    {
        return $this->registrationFailures[$for] ?? [];
    }

    /** @return array<string, TaskSource> */
    public function all(): array
    {
        return $this->sources;
    }

    public function has(string $key): bool
    {
        return isset($this->sources[$key]);
    }

    /**
     * The sources that are usable right now.
     *
     * This is where autonomy becomes visible: a product the person has no
     * access to does not appear as an empty section or a dead filter entry, it
     * is simply not there. Asked each time rather than cached — access can be
     * withdrawn between two page loads, and a stale "yes" shows a section that
     * then cannot be filled.
     *
     * @return array<string, TaskSource>
     */
    public function available(): array
    {
        return array_filter($this->sources, fn (TaskSource $source) => $source->isAvailable());
    }

    /**
     * The sources you may create in.
     *
     * An interface asks for this instead of checking itself — whoever repeats
     * the check at every control eventually forgets it somewhere. And the check
     * is two conditions, not one: the source has to BE writable (own class, no
     * flag) and to say it may be written to right now (`isWritable()` — the
     * capability can be gone, the person's rights can be withdrawn).
     *
     * Built on `available()`, not on `$sources`: a product nobody has access to
     * is not offered for creating either.
     *
     * @return array<string, WritableTaskSource>
     */
    public function writable(): array
    {
        return array_filter(
            $this->available(),
            fn (TaskSource $source) => $source instanceof WritableTaskSource && $source->isWritable(),
        );
    }

    /**
     * The sources you may perform THIS operation in.
     *
     * Narrower than `writable()`, and that is the point: a product can be
     * writable and still not offer every operation. The Verwaltung is the case
     * that made this necessary — a ticket can be ticked off and deleted, but
     * not created from another system, because a ticket always has a concern
     * behind it.
     *
     * `writable()` stays for the question „may this person write here at all",
     * which is what a page asks before it shows any editing at all.
     *
     * @param  string  $operation  one of WritableTaskSource::CREATE, CHANGE, DELETE
     * @return array<string, WritableTaskSource>
     */
    public function writableFor(string $operation): array
    {
        return array_filter(
            $this->writable(),
            fn (TaskSource $source) => $source instanceof WritableTaskSource && $source->supports($operation),
        );
    }

    /**
     * The sources you may plan time in at all.
     *
     * The mirror of `writable()` in purpose — an interface asks for this
     * instead of checking itself, because whoever repeats the check at every
     * control eventually forgets it somewhere — and deliberately NOT in shape:
     * it asks nobody over the wire (#10056).
     *
     * ## Local and static, because the question is
     *
     * Two questions sit next to each other here, and they have different
     * natures:
     *
     * | question                                    | nature           | answered by                               |
     * |---------------------------------------------|------------------|-------------------------------------------|
     * | "can one plan into this system at all?"     | local, static    | `instanceof PlansForeignTasks` — cannot fail |
     * | "did it work?"                              | remote, dynamic  | the attempt itself, with its reason       |
     *
     * Until 19.11.2026 this method was built on `available()`, and
     * `plannableFor()` filtered over {@see PlansForeignTasks::offers()}. Both
     * ask the hub's register, and the bridge maps FOUR distinguishable states
     * onto ONE empty list there — hub unreachable, hub answers with an error,
     * grant missing, capability really not offered
     * (`ai-brain-bridge/src/Gateway/GatewayClient.php`, `providers()`). A
     * check before the deed over that path turned three different reasons
     * into "not plannable", and the planning mask had explicitly decided on
     * four reasons and four sentences: `unreachable` means wait,
     * `not_allowed` means fetch somebody. Confusing them sends the reader to
     * the wrong place.
     *
     * A pre-flight network call that goes quiet on failure is the worse half
     * of both: it costs a call and says less than the attempt. So the reason
     * stays where it arises — in the {@see \Peppermint\Tasks\Exceptions\ForeignSourceFailed}
     * that `plan()`, `move()` and `unplan()` throw, with the error class of the
     * other side.
     *
     * That is also why this is `all()` and not `available()`: `isAvailable()`
     * is the same register question for the reading capabilities, and an
     * unreachable hub would empty this list just as quietly.
     *
     * ## Why there is no `isWritable()` equivalent in this pair
     *
     * `writable()` asks two questions — is the source writable by
     * construction, and may this person write there right now. Here the second
     * one has no place: whether somebody may touch a block is decided by the
     * ANSWERING system's policy, over the acting person the comm layer carries
     * along (`denyUnlessAllowed($request, 'update', $ticket)` in the
     * Verwaltung is the running precedent).
     *
     * A switch here would be the beginning of a second rights list, and
     * `RemoteTaskSystem` says what happens to those: "A second rights list
     * here would age without anyone noticing it had."
     *
     * ## Kept, although the probation said otherwise
     *
     * Until #10056 this docblock said: if the planning mask of #9898 step 4
     * ships without calling this pair, delete it. The mask's bee did not call
     * it — and named the reason, which was this method asking the network.
     * With the local version that reason is gone, and the pair gets the caller
     * it was meant for: which systems the mask marks as plannable at all now
     * lives HERE, once, instead of four times in the products.
     *
     * The warning behind the probation stands: an unused convenience in a
     * shared package reads as the sanctioned way while every product does it
     * by hand. `writable()` / `writableFor()` had no caller at all outside this
     * package's tests when measured on 16.11.2026, and the calendar package's
     * `EventSourceRegistry::writable()` had three character-identical hand
     * copies in Manager, CRM and Verwaltung (`66bbf043`, reverted in
     * `c9b3348f` ten minutes later — measure the tree, not the fix).
     *
     * @return array<string, PlansForeignTasks>
     */
    public function plannable(): array
    {
        return array_filter(
            $this->sources,
            fn (TaskSource $source) => $source instanceof PlansForeignTasks,
        );
    }

    /**
     * The sources you may ATTEMPT this planning operation in.
     *
     * Since #10056 the same list as `plannable()` for every valid operation.
     * It no longer narrows over {@see PlansForeignTasks::offers()}: whether
     * the other side offers the operation RIGHT NOW is a remote, dynamic
     * question, and the answer that keeps its reason is the attempt. The
     * Verwaltung — `UpdateTaskTool` and `DeleteTaskTool`, no `CreateTaskTool`
     * — therefore appears here for `create` too, and its no arrives from
     * `plan()` as `not_offered`, distinguishable from `unreachable` and
     * `not_allowed`. Filtered out beforehand, all three looked the same.
     *
     * `offers()` itself stays, and stays right for what it says: "does the
     * other side offer this capability at the moment". It just is not a
     * question a mask can ask BEFORE drawing without lying in three of four
     * cases.
     *
     * Why the method is kept rather than folded into `plannable()`: the
     * operation is still checked, and that check is local. An unknown
     * operation THROWS rather than returning an empty list. An empty list
     * would be the wrong answer in the one shape that cannot be seen: a mask
     * asking for a mistyped capability would show no systems at all, which
     * reads as "nobody offers this" — a claim, not an absence. Same reasoning
     * as the type check in `TasksServiceProvider::buildSources()`.
     *
     * @param  string  $capability  one of {@see PlansForeignTasks::CAPABILITIES}
     * @return array<string, PlansForeignTasks>
     *
     * @throws InvalidArgumentException for anything that is not one of the three
     */
    public function plannableFor(string $capability): array
    {
        if (! in_array($capability, PlansForeignTasks::CAPABILITIES, true)) {
            throw new InvalidArgumentException(
                "[{$capability}] is not one of the planning capabilities: "
                .implode(', ', PlansForeignTasks::CAPABILITIES).'.'
            );
        }

        return $this->plannable();
    }

    /**
     * The sources that answer the PERSON question right now.
     *
     * What an interface builds its filter chips from. A source that only
     * answers about records does not belong there — see
     * {@see TaskSource::supportsPerson()}.
     *
     * @return array<string, TaskSource>
     */
    public function forPersons(): array
    {
        return array_filter($this->available(), fn (TaskSource $source) => $source->supportsPerson());
    }

    /**
     * The sources that can answer about a subject right now.
     *
     * Two conditions, not one: available (the person has access at all) AND
     * willing to answer subject questions. Built on `available()` for the same
     * reason `writable()` is — a product nobody can reach is not asked about
     * records either.
     *
     * @return array<string, TaskSource>
     */
    public function forSubjects(): array
    {
        return array_filter($this->available(), fn (TaskSource $source) => $source->supportsSubjects());
    }

    /**
     * Collects the foreign tasks belonging to ONE record — and what failed.
     *
     * ## Why this does not swallow like `collect()` does
     *
     * `collect()` logs a broken source and moves on, and the reasoning holds
     * there: that is the person's own task list, it is long, and one missing
     * chip is invisible next to a blank page.
     *
     * Here the list is short and the question is closed. Someone opens an
     * order and asks "what else belongs to this?". Three entries where there
     * should be five looks exactly like a complete answer — there is nothing
     * on the page to notice. So the failure is not logged away, it is returned
     * (see {@see TaskHarvest}), and the interface says which system is missing.
     *
     * Still not thrown: the other sources keep showing, and the page works.
     *
     * Answers in the same shape as {@see harvest()} — same class, same three
     * fields. A page that shows a record's tasks next to a person's reads one
     * form, not two, and the caveat line is built the same way on both halves.
     *
     * @param  string  $type  the other system's word for its own record
     * @param  array<int, string>  $skip  sources the person switched off
     */
    public function collectForSubject(string $type, string|int $id, array $skip = []): TaskHarvest
    {
        $tasks = [];
        $ok = [];
        $failures = [];

        $gefragt = 0;
        $abgewaehlt = 0;

        foreach ($this->forSubjects() as $key => $source) {
            if (in_array($key, $skip, true)) {
                // Switched off is NOT a failure: nobody is waiting for this
                // answer, so its absence needs no caveat.
                $abgewaehlt++;

                continue;
            }

            $gefragt++;

            try {
                foreach ($source->tasksForSubject($type, $id) as $task) {
                    $tasks[] = $task;
                }

                // Also noted when it brought nothing, and here the reason is
                // sharper than for a person: "this order has no CRM tasks" is
                // an answer somebody acts on, and it must not look like "the
                // CRM was never asked".
                $ok[] = $key;
            } catch (Throwable $e) {
                if (! $e instanceof ForeignSourceFailed) {
                    // Anything else is a defect on our side, not an outage over
                    // there — it still must not blank the page, and it still
                    // must not pass as "nothing found".
                    Log::warning('tasks.subject_source_failed', [
                        'source' => $key,
                        'subject_type' => $type,
                        'message' => $e->getMessage(),
                    ]);
                }

                $failures[$key] = $this->failureFrom($key, $e);
            }
        }

        return new TaskHarvest($tasks, $ok, $this->withRegistrationFailures($failures, 'subjects', $skip, $gefragt, $abgewaehlt));
    }

    /**
     * Collects from every source, skipping the ones named in $skip.
     *
     * A source that is switched off is NOT ASKED, not merely hidden
     * afterwards: it means a call to another system, and that call should not
     * happen when nobody wants to see the result.
     *
     * A source that is not available is not asked either — see available().
     *
     * A source that fails is logged and skipped. One unreachable system must
     * not take down the local task list — a page that shows nothing because
     * somebody else's server is down is worse than a page missing one chip.
     *
     * @param  array<int, string>  $skip
     * @return array<int, ForeignTask>
     */
    public function collect(int $userId, array $skip = []): array
    {
        // Asks `harvest()` and drops everything but the tasks. Same signature,
        // same behaviour, same order — what a caller of this method gets does
        // not change.
        //
        // One body rather than two: the loop carries three decisions (do not
        // ask a skipped source, do not ask an unavailable one, log a failing
        // one and carry on), and two copies of three decisions stay in step
        // only until the first fix touches one of them.
        return $this->harvest($userId, $skip)->tasks;
    }

    /**
     * Collects like `collect()` — and says what did not answer.
     *
     * ## Why there are two methods and not one
     *
     * `collect()` returns tasks, and that is all a page drawing a list needs:
     * an unreachable system is left out, the rest stays visible, the reason
     * goes in the log.
     *
     * A FORM needs more. The planning mask asks "which system, then which
     * task", and through `collect()` the two answers it must tell apart look
     * the same — "no open tasks" and "was not reachable" are both an empty
     * list. The first is correct to show as "nothing to do"; the second is a
     * lie, and somebody plans their day around it.
     *
     * The log does not help there: it does not reach the person looking at the
     * screen. So the failure comes back WITH the result, and `collect()` keeps
     * its old shape for the callers that want the quiet reading — the hub calls
     * it, and nothing there should change because a form needed more detail.
     *
     * Reading stays quiet either way: nothing throws out of here. The
     * difference is only whether the reason is recoverable afterwards.
     *
     * @param  array<int, string>  $skip
     */
    public function harvest(int $userId, array $skip = []): TaskHarvest
    {
        $tasks = [];
        $ok = [];
        $failures = [];

        // `forPersons()`, not `available()`: a subject-only source would be
        // asked and would answer nothing, which costs a call across a system
        // boundary for a question it does not hold.
        $gefragt = 0;
        $abgewaehlt = 0;

        foreach ($this->forPersons() as $key => $source) {
            if (in_array($key, $skip, true)) {
                $abgewaehlt++;

                continue;
            }

            $gefragt++;

            try {
                foreach ($source->tasks($userId) as $task) {
                    $tasks[] = $task;
                }

                // Noted even when it brought nothing: "answered with nothing"
                // and "was not asked" must stay distinguishable, or the
                // distinction this method exists for is lost one level up.
                $ok[] = $key;
            } catch (Throwable $e) {
                // Logged AS WELL, not instead. The log is what somebody reads
                // when they ask why a system has been quiet for two days; the
                // returned reason is what the person in front of the form reads
                // right now. They answer different questions.
                Log::warning('tasks.source_failed', [
                    'source' => $key,
                    'message' => $e->getMessage(),
                ]);

                $failures[$key] = $this->failureFrom($key, $e);
            }
        }

        return new TaskHarvest($tasks, $ok, $this->withRegistrationFailures($failures, 'persons', $skip, $gefragt, $abgewaehlt));
    }

    /**
     * Die Ausfälle beim Anmelden dazulegen (Bug #1513).
     *
     * Was ausdrücklich abgewählt ist, bleibt draußen — dieselbe Regel wie bei
     * den Quellen: Niemand wartet auf diese Antwort. Ein Ausfall beim Ernten
     * desselben Schlüssels geht vor; er ist der jüngere Befund.
     *
     * ## Eine Ernte, die niemanden gefragt hat, braucht die Nabe nicht (#1523)
     *
     * Hat der Aufrufer Quellen dieses Registers abgewählt und ist dabei KEINE
     * übrig geblieben, fragt die Ernte das Register gar nicht — sie will nur,
     * was der Aufrufer selbst beisteuert. So wählt der Manager „nur das eigene
     * System": `skip` = alle Schlüssel des Registers. Ein Ausfall der Nabe
     * beim Anmelden ist für diese Ernte belanglos; meldete sie ihn, stünde sie
     * als unvollständig da, und die Maske verlöre ihr „hier ist nichts offen"
     * für Aufgaben, die die Nabe nie berührt haben.
     *
     * Bis #1523 hielt das nur aus Zufall: Der Platzhalter hiess `ai-brain`,
     * und der Manager führt eine eigene Quelle unter diesem Schlüssel — sie
     * stand also in `skip` und nahm den Nabe-Ausfall mit hinaus.
     *
     * Bewusst NICHT „keine Quelle gefragt" allein: Ist die Nabe ganz
     * ausgefallen, steht oft GAR KEINE Quelle im Register. Dann hat niemand
     * etwas abgewählt, und gerade dann ist der Ausfall die ganze Auskunft
     * (#1513). Erst Abwählen UND niemand übrig heisst „nur das Eigene".
     *
     * @param  array<string, SourceFailure>  $failures
     * @param  array<int, string>  $skip
     * @param  int  $gefragt  wie viele Quellen dieser Frage die Ernte gefragt hat
     * @param  int  $abgewaehlt  wie viele Quellen dieser Frage `skip` ausgelassen hat
     * @return array<string, SourceFailure>
     */
    protected function withRegistrationFailures(array $failures, string $for, array $skip, int $gefragt, int $abgewaehlt): array
    {
        if ($abgewaehlt > 0 && $gefragt === 0) {
            return $failures;
        }

        foreach ($this->registrationFailures($for) as $key => $failure) {
            if (! in_array($key, $skip, true)) {
                $failures[$key] ??= $failure;
            }
        }

        return $failures;
    }

    /**
     * A throw from a source, turned into the answer's failure entry.
     *
     * One place for both queries, because the reading is the same one twice:
     * the MESSAGE is the other end's own words, passed through — "Verwaltung
     * nicht erreichbar: timeout" tells somebody what to do next, "Fehler" does
     * not — and the REASON is the comm layer's class, when the source knew one.
     *
     * A plain throw carries no class, and that stays null rather than being
     * guessed at: a source that cannot tell "not allowed" from "not reachable"
     * must not have one of them invented for it here. Guessing would be the
     * expensive kind of wrong, because the interface acts on the reason — it
     * offers a reload for one and tells somebody to ask for access for another.
     */
    protected function failureFrom(string $key, Throwable $e): SourceFailure
    {
        return new SourceFailure(
            $key,
            $e->getMessage(),
            $e instanceof ForeignSourceFailed ? $e->errorClass : null,
        );
    }
}
