<?php

namespace Peppermint\Tasks\Sources;

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
 * ## Wo das aufgerufen wird
 *
 * Im Produkt, beim Aufbau der Quellenliste — nicht beim Hochfahren. Der Aufruf
 * geht über die Leitung; ihn in einen Service-Provider zu legen hiesse, jeden
 * Konsolenbefehl und jede Warteschlangen-Aufgabe mit einem HTTP-Aufruf zu
 * belasten, der sie nichts angeht.
 */
final class BrueckenSubjektquellen
{
    /**
     * @param  array<string, string>  $farben  Slug => Farbe. Markenfarben sind
     *                                         Produktwissen, kein Paketwissen.
     * @return int Wie viele Quellen angemeldet wurden.
     */
    public static function anmelden(TaskSourceRegistry $register, array $farben = []): int
    {
        if (! Bruecke::eingerichtet()) {
            // Ohne eingerichtete Brücke keine fremden Quellen — und das ist
            // kein Fehlerzustand, sondern der Zustand von AI Brain selbst: Es
            // hat das Paket, ohne es einzurichten, weil es die Nabe ist.
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
