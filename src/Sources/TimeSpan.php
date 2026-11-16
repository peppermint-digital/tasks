<?php

namespace Peppermint\Tasks\Sources;

use Peppermint\Tasks\Exceptions\InvalidTimeSpan;

/**
 * The span of time that goes out when a task is planned.
 *
 * Two fields, and the interesting part of this class is everything that is
 * NOT in it.
 *
 * ## No person
 *
 * Measured three times in this house, three times the same decision:
 *
 * - `peppermint-verwaltung/app/Mcp/Tools/CreateCalendarEventTool.php:27-34`:
 *   "`owner_id` kommt NICHT aus der Anfrage. Wer von aussen einen Termin
 *   anlegt, legt ihn fuer sich an — alles andere waere das Recht, jemandem
 *   etwas in den Kalender zu schreiben, und das hat die Weboberflaeche daneben
 *   auch nicht."
 * - {@see BrueckenSubjektquelle::tasks()}: "Die handelnde Person reist am
 *   Komm-Layer mit … Eine Kennung im Argument waere die Einladung, nach
 *   fremden Aufgaben zu fragen."
 * - `peppermint-crm/app/Mcp/Tools/ListCalendarEventsTool.php:17-28`: ownership
 *   instead of roles, and the lesson from Bug #657.
 *
 * The acting person travels on the comm layer, and the answering system sets
 * the owner from it. What cannot be expressed cannot be misused.
 *
 * ## No kind
 *
 * Every system has exactly one task-block kind and the answering tool takes
 * its own. Asking for one would invite the caller to name one, and a wrongly
 * named kind files the thing in the wrong drawer — what `ForeignKind` warns
 * about expressly.
 *
 * ## No title, no description, no project, no category
 *
 * The other system derives them from its own task and knows them better.
 * `ManagerTaskKind:32-46` says it for the category outright: "Ein Zeitblock
 * zeigt AUF eine Aufgabe, und die traegt Projekt, Kunde und Organisation —
 * praeziser, als sechs Arbeitskategorien es koennen."
 *
 * ## No `all_day`
 *
 * Planned work is a span of hours. The opposite case — a DEADLINE as an
 * all-day entry — is argued at `src/Calendar/TaskDeadlineSource.php:29-36`:
 * "Eine Frist um 14:37 im Raster zu zeichnen versprach eine Genauigkeit, die
 * es nicht gibt." For a block the reverse holds.
 *
 * ## No local event id
 *
 * The thing travels over the owning system plus its identifier there, never
 * over `calendar_events.id`. Nothing of ours is addressable over there.
 *
 * ## Strings and not Carbon instances
 *
 * Same reasoning as `NewForeignTask::$dueDate`, one step stricter. A Carbon
 * instance would have to be formatted on the way out, and the formatting is
 * exactly where the zone gets lost — which is the one mistake this house has
 * already made twice. A string that carries its zone cannot lose it between
 * here and the wire.
 *
 * Not normalised either: what comes in goes out. Converting to UTC here would
 * mean the other system is told a zone nobody chose, and the second measured
 * failure at `CreateCalendarEventTool.php:68-93` was precisely that.
 */
final class TimeSpan
{
    /**
     * ISO-8601 with a zone, both of them — enforced, not asked for politely.
     *
     * The alternative is a value object that MAY hold a naked local time, and
     * then every consumer has to check. Whoever forgets is the one who sends
     * it, and what arrives over there is two hours wrong with nothing to
     * notice.
     *
     * @throws InvalidTimeSpan when a point in time carries no zone, or the end
     *                         is not after the beginning
     */
    public function __construct(
        public readonly string $startsAt,
        public readonly string $endsAt,
    ) {
        $beginn = self::alsZeitpunkt('starts_at', $startsAt);
        $ende = self::alsZeitpunkt('ends_at', $endsAt);

        if ($ende <= $beginn) {
            throw InvalidTimeSpan::endsBeforeItBegins($startsAt, $endsAt);
        }
    }

    /**
     * The two keys the answering tools read.
     *
     * `starts_at` / `ends_at` and not `beginn` / `ende`: they are the names the
     * shared event shape already uses on the wire
     * (`peppermint-crm/app/Mcp/Concerns/ReichtTermineHeraus.php:52-53`), and an
     * inner name that goes out under a different spelling is a field without a
     * reader — with nothing breaking while it happens.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ];
    }

    /**
     * One point in time, read strictly.
     *
     * `T` is required and the zone is required. `strtotime()` alone would
     * accept "tomorrow", "2026-11-16" and "09:00" and quietly fill in the
     * server's zone for all three — which is the failure mode, not a
     * convenience. So the shape is checked first and the parse only decides
     * whether the date exists.
     *
     * Failing closed costs an error message where a caller is sloppy; failing
     * open costs a block in the wrong hour that looks right.
     *
     * @throws InvalidTimeSpan
     */
    private static function alsZeitpunkt(string $feld, string $wert): int
    {
        $mitZone = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/';

        if (preg_match($mitZone, $wert) !== 1) {
            throw InvalidTimeSpan::withoutZone($feld, $wert);
        }

        $zeitpunkt = strtotime($wert);

        if ($zeitpunkt === false) {
            throw InvalidTimeSpan::notAMoment($feld, $wert);
        }

        return $zeitpunkt;
    }
}
