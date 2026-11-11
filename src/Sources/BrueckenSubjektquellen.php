<?php

namespace Peppermint\Tasks\Sources;

use Illuminate\Support\Facades\Auth;
use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;

/**
 * Meldet für jedes antwortende System eine Quelle an — gemessen, nicht gepflegt.
 *
 * ## Warum nicht eine Quelle je Nachbar von Hand
 *
 * Drei Produkte mit je zwei Nachbarn wären sechs Klassen, die dasselbe täten.
 * Genau das stand im Mailbox-Paket, bevor `BridgeWerkzeugquelle` es ersetzte:
 * fünf handgeschriebene Quellen über zwei Produkte, vier davon gleich.
 *
 * Hier fragt stattdessen das Fähigkeits-Register in Brain, WER antwortet
 * (`AiBrain::providers()`). Das eigene System steht nicht in der Antwort, und
 * nur Systeme, für die dieses hier freigeschaltet ist — die Freigabeliste wird
 * damit nicht zweimal geführt.
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
     * @param  array<string, string>  $farben  Slug => Farbe. Markenfarben sind
     *                                         Produktwissen, kein Paketwissen —
     *                                         deshalb aus der Konfiguration des
     *                                         Produkts und nicht von hier.
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

        $angemeldet = 0;

        foreach (AiBrain::providers(BrueckenSubjektquelle::CAPABILITY) as $anbieter) {
            $slug = isset($anbieter['slug']) ? (string) $anbieter['slug'] : '';

            if ($slug === '') {
                // Ohne Slug ist die Zeile unbrauchbar: Er ist es, mit dem der
                // nächste Aufruf adressiert wird. Eine kaputte Zeile lässt die
                // übrigen stehen, statt die Liste zu verwerfen.
                continue;
            }

            $register->register(new BrueckenSubjektquelle(
                slug: $slug,
                // Der kurze Schlüssel und die kurze Beschriftung kommen aus der
                // Brücke, an EINER Stelle abgeleitet. Hier nachzubilden hiesse,
                // dass dasselbe System in zwei Oberflächen verschieden heissen
                // kann — und ein gespeicherter Filter wäre stumm falsch.
                schluessel: (string) ($anbieter['key'] ?? $slug),
                beschriftung: (string) ($anbieter['label'] ?? $slug),
                farbe: $farben[$slug] ?? null,
            ));

            $angemeldet++;
        }

        return $angemeldet;
    }
}
