<?php

namespace Peppermint\Tasks\Sources;

/**
 * Tasks of another system, shown alongside the local ones.
 *
 * Neither system is the centre: each application registers the sources it
 * wants to see. The same mechanism exists for calendars, and it is the piece
 * both systems genuinely share — without it, every application would rebuild
 * the "merge foreign things into my list" logic, which is exactly the
 * duplication these packages exist to end.
 *
 * A source is READ-ONLY by construction. There is no write method here.
 */
abstract class TaskSource
{
    /** Stable identifier, used in the merged id and as the filter key. */
    abstract public function key(): string;

    /** What a human reads on the filter chip. */
    abstract public function label(): string;

    /**
     * The tasks this source offers for the given person, in this system's
     * user id.
     *
     * Resolving that person in the OTHER system is the source's job — the
     * caller must not have to know how two systems recognise the same human.
     *
     * @return array<int, ForeignTask>
     */
    abstract public function tasks(int $userId): array;

    /**
     * Does this source answer the PERSON question at all?
     *
     * Default true, because that is what a source was for until 11.11.2026 and
     * no existing one has to say so.
     *
     * A source that answers only subject questions says false here, and then
     * the registry neither asks it in `collect()` nor offers it as a person
     * filter — rather than offering a chip that is always empty. An always
     * empty chip is not a small blemish: it reads as "nothing of mine over
     * there", which is a claim, not an absence.
     */
    public function supportsPerson(): bool
    {
        return true;
    }

    /**
     * Does this source answer questions about a SUBJECT?
     *
     * Default false, and that default is the whole reason this method exists.
     * Making `tasksForSubject()` abstract would have been tidier and would
     * have broken every source in every product the moment the package moved
     * — a shared package may not do that.
     *
     * Asked before `tasksForSubject()`. A source that says no is not asked,
     * rather than asked and silently returning nothing: those two look the
     * same from outside, and only one of them is an answer.
     */
    public function supportsSubjects(): bool
    {
        return false;
    }

    /**
     * The tasks this source holds for ONE record of another system.
     *
     * Not "the tasks of the case file". Tasks hang on their own local record —
     * the Verwaltung task on its order, the Manager task on its project, the
     * CRM task on its deal. Whoever wants everything belonging to one case
     * resolves the case file into its references first and then asks each
     * system about ITS record.
     *
     * That is deliberate, and it was wrong the other way round until
     * 10.11.2026: a task that existed before the case file would have had to
     * be moved; this way it is found the moment the case file adopts its
     * record. It also means this package never has to know what a case file
     * is — it gets a type and an id, which is what the extension point is for.
     *
     * `$type` is the other system's word for its own record ("deal", "order",
     * "project"), not ours for it. Translating it here would make this package
     * the place where three systems' vocabularies are reconciled, and that
     * place must not exist (see the status words in README).
     *
     * @return array<int, ForeignTask>
     *
     * @throws \LogicException if this source does not support subjects
     * @throws \Peppermint\Tasks\Exceptions\ForeignSourceFailed if the other system could not be asked
     */
    public function tasksForSubject(string $type, string|int $id): array
    {
        // Loud, not empty. An empty list here would be indistinguishable from
        // "this record has no tasks over there" — a wrong answer that nobody
        // can see is worse than a crash in development.
        throw new \LogicException(
            static::class.' does not answer subject queries. Ask supportsSubjects() first.'
        );
    }

    /**
     * Is this source usable at all right now?
     *
     * The autonomy rule: a product that is not connected, not allowed to be
     * called back, or does not offer the operation must disappear by itself —
     * no tasks shown, no filter entry, nothing to create. A person without CRM
     * access has no CRM tasks, and that is not an error state to report but the
     * correct answer.
     *
     * Measured, not configured: the calendar package learned this the same way.
     * A source that asks the registry loses its entry the moment the other side
     * loses the capability, without anyone remembering to switch it off.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /** Colour for the chip. Null means the application decides. */
    public function colour(): ?string
    {
        return null;
    }
}
