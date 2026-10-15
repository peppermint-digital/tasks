<?php

namespace Peppermint\Tasks\Calendar;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Peppermint\Calendar\Sources\EventSource;
use Peppermint\Calendar\Sources\ExternalEvent;
use Peppermint\Tasks\Models\Task;

/**
 * Die Fristen offener Aufgaben, als Eintraege im Kalender.
 *
 * ## Warum eine Quelle und kein Termin
 *
 * Eine Frist ist keine Verabredung, sondern eine Eigenschaft der Aufgabe. Legte
 * man dafuer einen Termin an, gaebe es sie zweimal: Wer die Aufgabe verschiebt,
 * muesste den Termin mitziehen, wer den Termin verschiebt, die Aufgabe — und
 * eine der beiden Richtungen wird irgendwann vergessen.
 *
 * Als Quelle gelesen entsteht keine Zeile. Das Raster fragt beim Blaettern, die
 * Aufgabe bleibt die einzige Wahrheit, und eine verschobene Frist steht sofort
 * am richtigen Tag.
 *
 * Dieselbe Mechanik, mit der der Kalender die Termine fremder Systeme zeigt —
 * nur dass hier nichts ueber das Netz geht. `EventSource` verlangt genau drei
 * Antworten, und keine davon setzt ein anderes System voraus.
 *
 * ## Ganztags, immer
 *
 * `due_date` ist zwar ein Zeitstempel, aber die Fristenlogik des Pakets rechnet
 * durchgehend in Tagen (`DueDateReminders` vergleicht ueber `startOfDay()`).
 * Eine Frist um 14:37 im Raster zu zeichnen versprach eine Genauigkeit, die es
 * nicht gibt — und verdeckte im Tagesraster die Termine, die wirklich zu dieser
 * Stunde stattfinden.
 *
 * ## Nur offene Aufgaben
 *
 * Eine erledigte Aufgabe hat keine Frist mehr, sie hat ein Datum. Sie weiter zu
 * zeigen fuellte den Kalender mit Erledigtem — und genau die Vergangenheit
 * sieht sich niemand im Kalender an.
 *
 * ## Einbinden
 *
 * ```php
 * // config/calendar.php
 * 'sources' => [
 *     new TaskDeadlineSource(
 *         // Wohin ein Klick fuehrt. Das Paket kennt die Routen der Anwendung
 *         // nicht — ohne diese Angabe bleibt der Eintrag ohne Sprungziel,
 *         // was besser ist als eine geratene Adresse.
 *         url: fn (Task $aufgabe) => route('tasks.show', $aufgabe),
 *     ),
 * ],
 * ```
 */
class TaskDeadlineSource extends EventSource
{
    /**
     * @param  (callable(Task): ?string)|null  $url     Sprungziel je Aufgabe.
     * @param  (callable(\Illuminate\Database\Eloquent\Builder, int): void)|null  $scope
     *         Zusaetzliche Einschraenkung — etwa auf einen Mandanten. Ohne
     *         Angabe gilt: die Aufgaben, die dieser Person zugewiesen sind.
     */
    public function __construct(
        private readonly mixed $url = null,
        private readonly mixed $scope = null,
        private readonly string $sourceKey = 'task-deadlines',
        private readonly string $sourceLabel = 'Fristen',
        private readonly ?string $colour = '#ef4444',
    ) {}

    public function key(): string
    {
        return $this->sourceKey;
    }

    public function label(): string
    {
        return $this->sourceLabel;
    }

    public function colour(): ?string
    {
        return $this->colour;
    }

    /**
     * @return array<int, ExternalEvent>
     */
    public function events(int $userId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $spalte = Task::column('due_date');

        $abfrage = Task::query()
            ->open()
            ->whereNotNull($spalte)
            // Auf GANZE Tage geweitet: Eine Frist am 3.11. um 23:30 gehoert in
            // ein Raster, das am 3.11. um 23:00 endet — sie ist an diesem Tag
            // faellig, nicht in dieser Stunde.
            ->whereBetween($spalte, [
                CarbonImmutable::parse($from)->startOfDay(),
                CarbonImmutable::parse($to)->endOfDay(),
            ]);

        if ($this->scope !== null) {
            ($this->scope)($abfrage, $userId);
        } else {
            $abfrage->where(Task::column('assigned_to'), $userId);
        }

        $eintraege = [];

        foreach ($abfrage->get() as $aufgabe) {
            $tag = CarbonImmutable::parse($aufgabe->field('due_date'));

            $eintraege[] = new ExternalEvent(
                sourceKey: $this->key(),
                id: (string) $aufgabe->getKey(),
                title: (string) $aufgabe->field('title'),
                startsAt: $tag->startOfDay(),
                endsAt: $tag->endOfDay(),
                allDay: true,
                url: $this->url === null ? null : ($this->url)($aufgabe),
                // Was die Anwendung sonst noch zeigen mag — gezeigt und sonst
                // nichts: nie gespeichert, nie zurueckgeschrieben.
                extra: array_filter([
                    'status' => $aufgabe->field('status'),
                    'priority' => $aufgabe->field('priority'),
                ], static fn ($wert) => $wert !== null),
            );
        }

        return $eintraege;
    }
}
