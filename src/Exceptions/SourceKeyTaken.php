<?php

namespace Peppermint\Tasks\Exceptions;

use Peppermint\Tasks\Sources\TaskSource;
use RuntimeException;

/**
 * Zwei Quellen wollten denselben Schlüssel — Bug #1466.
 *
 * ## Warum das laut sein muss
 *
 * `TaskSourceRegistry` hält eine Quelle je Schlüssel. Bis zum 13.11.2026
 * ersetzte die zweite Anmeldung die erste wortlos, und das ist genau die Art
 * Fehler, die man nicht findet: Nichts bricht, nichts wird gemeldet, es
 * verschwindet nur eine Antwort.
 *
 * Gemessen im Peppermint Manager (#9887): Eine zweite Quelle je System —
 * Personen-Frage neben Datensatz-Frage — liess `forSubjects()` leer laufen und
 * tötete vier Tests eines Vorgangs, der am selben Tag ausgerollt worden war.
 * Die Suche danach begann an der falschen Stelle, weil die Anmeldung „geklappt"
 * hatte.
 *
 * Ein Schlüssel ist die Kennung EINES Systems, und welche Fragen es
 * beantwortet, ist seine Eigenschaft — nicht ein Grund für einen zweiten
 * Eintrag. Wer trotzdem ersetzen will (ein Produkt, das eine Paketquelle durch
 * seine eigene austauscht), sagt es ausdrücklich: `register($quelle,
 * ersetzen: true)`.
 *
 * Die Meldung nennt BEIDE Klassen. „Der Schlüssel crm ist belegt" schickt
 * jemanden suchen; „BrueckenSubjektquelle steht dort, RemoteTaskSource wollte
 * hin" sagt in einer Zeile, was passiert ist.
 */
final class SourceKeyTaken extends RuntimeException
{
    public static function by(string $key, TaskSource $vorhanden, TaskSource $neu): self
    {
        return new self(sprintf(
            'Der Quellen-Schlüssel "%s" ist schon belegt: %s steht dort, %s wollte hin. '
                .'Ein System ist EIN Eintrag — welche Fragen es beantwortet, ist seine Eigenschaft. '
                .'Soll die vorhandene Quelle wirklich ersetzt werden, sage es ausdrücklich: '
                .'register($quelle, ersetzen: true).',
            $key,
            $vorhanden::class,
            $neu::class,
        ));
    }
}
