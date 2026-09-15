<?php

namespace Peppermint\Tasks\Kinds;

/**
 * A kind of task. The consuming application subclasses this once per kind and
 * registers it — the package ships none of its own.
 *
 * A kind answers what the core cannot: what this sort of work is called, which
 * extra fields it carries, which states it can be in, and — most importantly —
 * which core fields it must never have.
 *
 * ## Why the last one is the whole point
 *
 * Without `forbiddenAttributes()` a kind decays into a label. Three Peppermint
 * systems proved this independently before the concept existed: the CRM kept a
 * nullable free-text `category`, the Verwaltung a proper enum on tickets, the
 * Manager a boolean for exactly one kind. All three describe the same idea, and
 * none of them could state what its values rule out — so none of them could be
 * relied on for anything.
 *
 * The test for a kind is therefore: name a field it must not carry, or a field
 * it cannot exist without. If neither exists, it is a property, not a kind.
 */
abstract class TaskKind
{
    /** Key as stored in the task's `kind` column. Keep it stable — it lives in the data. */
    abstract public function key(): string;

    /** Label for the user interface and for the filter bar. */
    abstract public function label(): string;

    /**
     * Profile model holding this kind's own fields (1:1 with the task), or null
     * when the kind needs no extra fields.
     *
     * This is how a kind carries what the core must not know: a ticket's
     * customer and submitter, a recurring task's pattern. The core table stays
     * the fields every task has; everything else hangs off the kind.
     *
     * @return class-string|null
     */
    public function profileModel(): ?string
    {
        return null;
    }

    /**
     * May a person pick this kind when creating a task by hand?
     *
     * Not every kind is something you choose. A ticket appears because somebody
     * wrote to the support address; offering it in a "what would you like to
     * create?" dialogue asks a question with no useful answer — and the entry
     * created that way would be missing the message it exists for.
     */
    public function isUserCreatable(): bool
    {
        return true;
    }

    /**
     * The statuses this kind can be in — null means the whole registered
     * vocabulary.
     *
     * Not a convenience. A routine is the thing you do every day; it does not
     * get completed, it comes back. The Manager expressed that with two
     * controllers refusing a status change and a message saying so — a rule
     * living in the request path, where the third caller forgets it. Stated
     * here it holds for every caller, including the ones written later.
     *
     * @return array<int, string>|null
     */
    public function statusKeys(): ?array
    {
        return null;
    }

    /**
     * Restricts urgency to these factors. Null means all of them.
     *
     * Belongs to the kind, not the individual task: "a routine does not race
     * against real work" is a statement about routines, not about this one row.
     * It sat on the Manager's task model before kinds existed, where every
     * other application would have had to rediscover it.
     *
     * @return array<int, string>|null
     */
    public function restrictUrgencyTo(): ?array
    {
        return null;
    }

    /**
     * Validation rules for this kind's extra fields. The core does not apply
     * them itself — the application pulls them into its own requests.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Core fields this kind must never set.
     *
     * A routine with a due date is the example that gave this method its shape:
     * the Manager allows one today, and it does nothing, because the urgency
     * calculation ignores it. A field that is accepted and silently ignored is
     * worse than one that is refused — somebody sets it, expects an effect, and
     * never learns that there was none.
     *
     * @return array<int, string>
     */
    public function forbiddenAttributes(): array
    {
        return [];
    }

    /**
     * Fields this kind cannot be saved without, read off its own `rules()`.
     *
     * Derived rather than declared a second time: a kind that states `required`
     * in its rules and then repeats the same list for the form has two places
     * to forget, and they drift apart quietly.
     *
     * @return array<int, string>
     */
    public function requiredAttributes(): array
    {
        $required = [];

        foreach ($this->rules() as $field => $rule) {
            $tokens = match (true) {
                is_string($rule) => explode('|', $rule),
                is_array($rule) => $rule,
                default => [],
            };

            foreach ($tokens as $token) {
                if (is_string($token) && $token === 'required') {
                    $required[] = $field;

                    break;
                }
            }
        }

        return $required;
    }

    /** Colour for the filter bar and the list. Null means the application decides. */
    public function colour(): ?string
    {
        return null;
    }
}
