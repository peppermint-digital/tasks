<?php

namespace Peppermint\Tasks\Sources;

use Illuminate\Support\Facades\Auth;
use Peppermint\AiBrainBridge\Verbund\Bruecke;

/**
 * Meldet für jedes antwortende System eine Quelle an — gemessen, nicht gepflegt.
 *
 * ## Eine Quelle je System, nicht je Frage
 *
 * Gefragt wird nach ZWEI Vorgängen: `tasks.subject.list` („was gehört zu
 * diesem Datensatz") und `tasks.list` („meine Aufgaben dort"). Angemeldet wird
 * daraus trotzdem EINE Quelle je System, die beide Flaggen trägt.
 *
 * Nicht zwei Quellen nebeneinander, und das ist nicht Geschmack: Das Register
 * hält eine Quelle je Schlüssel, und beide Hälften leiten ihren Schlüssel aus
 * derselben Systemkennung ab. Zwei Anmeldungen überschrieben einander — im
 * Manager gemessen am 13.11.2026, `forSubjects()` lief leer und vier Tests
 * starben, ohne dass irgendwo etwas gemeldet wurde (#9887, Bug #1466).
 *
 * Beide Abfragen stehen HIER und nicht in der Quelle: Die Systemliste einer
 * Maske muss beim Zeichnen schon dastehen, sonst nimmt sie dem
 * `TaskSourcePicker` im Kalenderpaket die Zusicherung weg, auf der dessen
 * fehlender Lade- und Ausfallzustand beruht.
 *
 * ## Warum nicht eine Quelle je Nachbar von Hand
 *
 * Drei Produkte mit je zwei Nachbarn wären sechs Klassen, die dasselbe täten.
 * Genau das stand im Mailbox-Paket, bevor `BridgeWerkzeugquelle` es ersetzte:
 * fünf handgeschriebene Quellen über zwei Produkte, vier davon gleich.
 *
 * Hier fragt stattdessen das Fähigkeits-Register in Brain, WER antwortet
 * (`AiBrain::providers()`, über den {@see AnbieterMerker}). Das eigene System
 * steht nicht in der Antwort, und nur Systeme, für die dieses hier
 * freigeschaltet ist — die Freigabeliste wird damit nicht zweimal geführt.
 *
 * Kommt ein Produkt dazu, erscheint seine Quelle von selbst. Verliert eines
 * die Fähigkeit, verschwindet sie — ohne dass jemand daran denken muss.
 *
 * ## Ohne angemeldete Person wird nicht gefragt
 *
 * Erst die Wache, dann die Leitung. Ein Konsolenbefehl, eine
 * Warteschlangen-Aufgabe und ein anonymer Zugriff bekommen eine leere Liste,
 * ohne dass ein HTTP-Aufruf stattfindet — und zwar nicht aus Sparsamkeit: Ohne
 * Person gibt es auch niemanden, dessen fremde Aufgaben gemeint wären.
 *
 * Dieselbe Reihenfolge wie bei `Peppermint\Calendar\Sources\BrainCalendarSources`,
 * von dort übernommen. Die Wache ist angebbar, weil ein Produkt mehrere haben
 * kann; die Verwaltung nennt ausdrücklich `web`.
 *
 * ## Warum das über die Konfiguration läuft und nicht über `extend()`
 *
 * Der Kalender wird im `AppServiceProvider` des Produkts verdrahtet, mit
 * `$this->app->extend(...)`. Hier steht stattdessen ein Eintrag in
 * `config('tasks.sources')` — ein bewusster Unterschied, und der Grund steht
 * im Kalender selbst: Weil seine Fremdquellen NICHT in der Konfiguration
 * stehen, bedeutete die Liste dort „nie ‚keine fremden Termine‘ — nur ‚keine,
 * die über die Konfiguration kommen‘". Genau diese Fehllesung ist dort
 * passiert und als Korrektur vermerkt.
 *
 * Mit dem Vertrag {@see MeldetQuellenAn} bleibt die Konfiguration die eine
 * Stelle, an der man nachsieht, was ein Produkt an Quellen hat.
 */
final class BrueckenSubjektquellen implements MeldetQuellenAn
{
    /**
     * @param  array<string, string>  $farben  KURZER SCHLÜSSEL => Farbe, also
     *                                         `['manager' => '#f59e0b']`.
     *
     * Der kurze Schlüssel und nicht der Slug, weil es diese Zuordnung schon
     * gibt: `config('calendar.source_colours')` ist genau so aufgebaut. Die
     * Farbe kennzeichnet das SYSTEM und nicht die Sache — dasselbe System soll
     * im Terminraster und in der Aufgabenliste dieselbe Farbe haben. Ein
     * Produkt gibt deshalb seine VORHANDENE Zuordnung mit, statt hier eine
     * zweite zu pflegen.
     *
     * Leer lassen ist richtig, wenn es keine gibt: `colour()` null zu melden
     * heisst „die Anwendung entscheidet", und das ist besser als eine
     * erfundene Farbe, die neben einer bestehenden steht.
     */
    /**
     * @param  array<string, string>  $farben  Slug => Farbe.
     * @param  string|null  $wache  Die Wache, die „ist jemand angemeldet"
     *                              beantwortet. `null` = die Vorgabe der
     *                              Anwendung.
     */
    public function __construct(
        private readonly array $farben = [],
        private readonly ?string $wache = null,
    ) {}

    public function anmelden(TaskSourceRegistry $register): void
    {
        $this->melden($register);
    }

    /**
     * Dasselbe, aber mit der Zahl als Antwort — für Tests und für ein Produkt,
     * das selbst entscheiden will, was bei null Anbietern passiert.
     *
     * @return int Wie viele Quellen angemeldet wurden.
     */
    public function melden(TaskSourceRegistry $register): int
    {
        $farben = $this->farben;

        if (! Bruecke::eingerichtet()) {
            // Ohne eingerichtete Brücke keine fremden Quellen — und das ist
            // kein Fehlerzustand, sondern der Zustand von AI Brain selbst: Es
            // hat das Paket, ohne es einzurichten, weil es die Nabe ist.
            return 0;
        }

        if (Auth::guard($this->wache)->guest()) {
            // Erst die Wache, dann die Leitung. Ohne Person gibt es niemanden,
            // dessen fremde Aufgaben gemeint wären — und kein Grund, dafür
            // über die Systemgrenze zu gehen.
            return 0;
        }

        // ZWEI Abfragen, und zwar hier und nur hier. Die Quelle selbst fragt
        // danach niemanden mehr: Die Systemliste einer Maske muss beim
        // Zeichnen schon dastehen — siehe die Begründung am Konstruktor von
        // {@see BrueckenSubjektquelle}.
        $subjekte = $this->anbieterNachSlug(BrueckenSubjektquelle::CAPABILITY);
        $personen = $this->anbieterNachSlug(BrueckenSubjektquelle::CAPABILITY_PERSON);

        $angemeldet = 0;

        // Die VEREINIGUNG, nicht einer der beiden Vorgänge. Ein System, das
        // nur die Personen-Frage beantwortet, bekäme sonst gar keine Quelle —
        // und zwar lautlos: Es stünde einfach nicht in der Liste, ohne dass
        // irgendwo ein Fehler entstünde.
        //
        // Die Reihenfolge ist die der Subjekt-Antwort zuerst, damit sich an
        // bestehenden Oberflächen nichts verschiebt; was nur Personen führt,
        // kommt dahinter.
        foreach ($subjekte + $personen as $slug => $anbieter) {
            $register->register(new BrueckenSubjektquelle(
                slug: $slug,
                // Der kurze Schlüssel und die kurze Beschriftung kommen aus der
                // Brücke, an EINER Stelle abgeleitet. Hier nachzubilden hiesse,
                // dass dasselbe System in zwei Oberflächen verschieden heissen
                // kann — und ein gespeicherter Filter wäre stumm falsch.
                schluessel: (string) ($anbieter['key'] ?? $slug),
                beschriftung: (string) ($anbieter['label'] ?? $slug),
                // Nach dem KURZEN Schlüssel, wie `calendar.source_colours`.
                farbe: $farben[(string) ($anbieter['key'] ?? $slug)] ?? null,
                // Welche Fragen dieses System beantwortet — gemessen, nicht
                // angenommen, und als WERT übergeben.
                personen: isset($personen[$slug]),
                subjekte: isset($subjekte[$slug]),
            ));

            $angemeldet++;
        }

        return $angemeldet;
    }

    /**
     * Die Anbieter eines Vorgangs, nach Slug greifbar.
     *
     * Gefragt wird über den {@see AnbieterMerker}: Beide Vorgänge hier und
     * jedes spätere `isAvailable()` derselben Anfrage teilen sich damit EINEN
     * Netzaufruf je Vorgang (Bug #1469). Die Antwort ist dieselbe wie vorher —
     * der Merker hält sie nur für die Dauer dieser Anfrage fest.
     *
     * Aus dem Container und nicht aus dem Konstruktor, aus demselben Grund wie
     * in {@see BrueckenSubjektquelle::isAvailable()}: Ein hier gehaltener
     * Merker überlebte das Ende der Anfrage, wenn der Anmelder es täte.
     *
     * @return array<string, array<string, mixed>>
     */
    private function anbieterNachSlug(string $vorgang): array
    {
        return app(AnbieterMerker::class)->anbieter($vorgang);
    }
}
