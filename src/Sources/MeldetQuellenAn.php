<?php

namespace Peppermint\Tasks\Sources;

/**
 * Ein Eintrag in `config('tasks.sources')`, der MEHRERE Quellen anmeldet.
 *
 * ## Warum es das braucht
 *
 * Die Konfiguration nennt Klassennamen, und der Container löst jeden zu EINER
 * Quelle auf. Das genügt, solange eine Quelle ohne Argumente auskommt.
 *
 * Es genügt nicht, wenn erst zur Laufzeit feststeht, WIE VIELE Quellen es gibt
 * — etwa weil ein Register im AI Brain sagt, welche Systeme antworten. Der
 * naheliegende Ausweg wäre eine dünne Unterklasse je Nachbarsystem: bei drei
 * Produkten mit je zwei Nachbarn sechs Klassen, die dasselbe tun. Genau das
 * stand im Mailbox-Paket, bevor eine einzige Quelle sie ersetzte.
 *
 * Mit diesem Vertrag bleibt die Konfiguration der eine Ort, an dem ein Produkt
 * seine Quellen nennt — nur steht dort dann „frag nach" statt „nimm diese".
 *
 * @see BrueckenSubjektquellen
 */
interface MeldetQuellenAn
{
    public function anmelden(TaskSourceRegistry $register): void;
}
