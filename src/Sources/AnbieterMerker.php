<?php

namespace Peppermint\Tasks\Sources;

use Peppermint\AiBrainBridge\Facades\AiBrain;

/**
 * Wer einen Vorgang bedient — gefragt EINMAL je Anfrage, nicht je Aufrufer.
 *
 * ## Das Problem, gezählt (Bug #1469)
 *
 * `BrueckenSubjektquelle::isAvailable()` fragt das Fähigkeits-Register in
 * Brain, und das Quellenregister ruft es je Quelle. `forPersons()` und
 * `forSubjects()` bauen beide auf `available()` — eine Maske, die beide Listen
 * braucht (seit #9887 der Normalfall), zahlte je Seitenaufbau:
 *
 * | | Aufrufe |
 * |---|---|
 * | Anmeldung ({@see BrueckenSubjektquellen::melden()}) | 2 |
 * | `forPersons()` über zwei Brücken-Quellen | 2 |
 * | `forSubjects()` über dieselben | 2 |
 * | **je Seitenaufbau** | **6** |
 *
 * Jeder davon mit der Zeitgrenze aus `gateway.timeout` — vorbelegt 20
 * Sekunden. Bei nicht erreichbarer Nabe wird daraus kein langsamer, sondern
 * ein ausgefallener Seitenaufbau.
 *
 * ## Ein Merker für EINE Anfrage ist kein Zwischenspeicher mit Frist
 *
 * `GatewayClient::providers()` in der Brücke sagt ausdrücklich, warum dort
 * keiner liegt: „Das Register wird in Brain gemessen und nicht gepflegt; ein
 * Zwischenspeicher hier wuerde ein neu angebundenes System verspaetet
 * erscheinen und ein abgeschaltetes nachwirken lassen. Wer den Netzweg sparen
 * will, legt den Speicher dort an, wo die Quellen gebaut werden — die
 * Entscheidung gehoert der Anwendung."
 *
 * Diese Klasse ist diese Stelle, und sie trifft die Entscheidung in der
 * kleinsten Form, die es gibt: Innerhalb EINES Seitenaufbaus liefert
 * `providers($vorgang)` zwangsläufig dieselbe Antwort. Sie mehrfach zu holen
 * ist reine Verschwendung — und nur die wird hier abgestellt.
 *
 * Keiner der Nachteile, vor denen die Brücke warnt, entsteht dabei:
 *
 * - Ein neu angebundenes System erscheint bei der nächsten Anfrage.
 * - Ein abgeschaltetes wirkt nicht nach.
 * - Die Begründung an {@see BrueckenSubjektquelle::isAvailable()} bleibt wahr:
 *   „zwischen registriert und gefragt liegt Zeit" — Anmeldung und Abfrage
 *   liegen in VERSCHIEDENEN Anfragen, und zwischen ihnen weiss dieser Merker
 *   nichts mehr.
 *
 * Geändert wird also nicht, WANN geprüft wird, sondern nur, dass dieselbe
 * Frage im selben Atemzug nicht dreimal über die Leitung geht. Aus 6 Aufrufen
 * werden 2.
 *
 * ## Wie lange „eine Anfrage" dauert, entscheidet die Bindung
 *
 * Diese Klasse hält bloss ein Array; dass es die Anfrage nicht überlebt, ist
 * Sache der Bindung im {@see \Peppermint\Tasks\TasksServiceProvider}. Dort
 * steht `scoped()` und ausdrücklich nicht `singleton()` — die Begründung steht
 * an der Bindung, weil sie dort gebraucht wird.
 */
final class AnbieterMerker
{
    /**
     * Vorgang => (Slug => Anbieterzeile).
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $gemerkt = [];

    /**
     * Die Anbieter eines Vorgangs, nach Slug greifbar.
     *
     * @return array<string, array<string, mixed>>
     */
    public function anbieter(string $vorgang): array
    {
        // Auch die LEERE Antwort wird gemerkt, und gerade sie: Ein Fehlschlag
        // beim Fragen IST die leere Liste (so sagt es `AiBrain::providers()`
        // zu). Würde sie nicht gemerkt, kostete ausgerechnet der Ausfall —
        // der Fall, in dem es weh tut — jeden weiteren Aufruf die volle
        // Zeitgrenze.
        return $this->gemerkt[$vorgang] ??= $this->holen($vorgang);
    }

    /** Beantwortet DIESES System den Vorgang? */
    public function bedient(string $vorgang, string $slug): bool
    {
        return isset($this->anbieter($vorgang)[$slug]);
    }

    /**
     * Die eine Stelle im Paket, an der nach Anbietern gefragt wird.
     *
     * Nach Slug abgelegt, weil jeder Aufrufer hier genau danach greift: Der
     * Anmelder bildet die Vereinigung zweier Vorgänge über den Slug, und die
     * Quelle fragt, ob ihrer darin steht.
     *
     * Ohne Slug ist eine Zeile unbrauchbar — er ist es, mit dem der nächste
     * Aufruf adressiert wird. Eine kaputte Zeile lässt die übrigen stehen,
     * statt die Liste zu verwerfen.
     *
     * Die Wache und die eingerichtete Brücke fragt NICHT dieser Merker,
     * sondern der Aufrufer, bevor er hierherkommt: Ohne Person gibt es
     * niemanden, dessen fremde Aufgaben gemeint wären, und auf dem Hub lässt
     * sich die Brücke gar nicht auflösen.
     *
     * @return array<string, array<string, mixed>>
     */
    private function holen(string $vorgang): array
    {
        $nachSlug = [];

        foreach (AiBrain::providers($vorgang) as $anbieter) {
            $slug = isset($anbieter['slug']) ? (string) $anbieter['slug'] : '';

            if ($slug === '') {
                continue;
            }

            $nachSlug[$slug] = $anbieter;
        }

        return $nachSlug;
    }
}
