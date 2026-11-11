<?php

namespace Peppermint\Tasks\Sources;

use Peppermint\AiBrainBridge\Facades\AiBrain;
use Peppermint\AiBrainBridge\Verbund\Bruecke;
use Peppermint\Tasks\Exceptions\ForeignSourceFailed;

/**
 * Die Aufgaben EINES anderen Produkts zu einem Datensatz — über den Komm-Layer.
 *
 * One instance per answering system, so each gets its own chip: the question
 * "what else belongs to this order?" is answered by several systems, and which
 * one said what is content, not plumbing. The status words of three systems are
 * NOT the same — showing them merged under one heading would claim an equality
 * that does not exist (see README).
 *
 * ## Why this class may know the bridge
 *
 * The product contract says a package must not know a peer address. It does not:
 * it names a CAPABILITY (`tasks.subject.list`) and a system SLUG that AI Brain
 * itself handed out. Where that system lives, who may call it and which tool
 * answers is decided by the capability register in Brain.
 *
 * The bridge is a `suggest`, not a `require` — same promise the calendar bridge
 * keeps: install this package without it and you get tasks without foreign
 * sources. This class is then never loaded, because autoloading is lazy.
 *
 * Precedent, and the reason this is not a hand-written caller per product:
 * `Peppermint\Mailbox\Werkzeuge\BridgeWerkzeugquelle` does exactly this for the
 * mail toolbar. Before it existed there were five hand-written sources across
 * two products, four of which did the same thing.
 *
 * ## It answers the SUBJECT question only
 *
 * Not "my tasks over there". That already flows product → Brain → product, and
 * a second path to the same rows would be a second truth to keep in step.
 * `supportsPerson()` is therefore false, and the registry does not offer this
 * source as a person-filter chip — rather than offering one that is always
 * empty.
 */
final class BrueckenSubjektquelle extends TaskSource
{
    /** Der Vorgang, den die Gegenseite anbietet. */
    public const CAPABILITY = 'tasks.subject.list';

    public function __construct(
        private readonly string $slug,
        private readonly string $schluessel,
        private readonly string $beschriftung,
        private readonly ?string $farbe = null,
    ) {}

    /**
     * Der KURZE Schlüssel, nicht der Slug.
     *
     * Er steckt in gespeicherten Filtern der Nutzer und in zusammengesetzten
     * Kennungen. Die Brücke leitet ihn an einer Stelle ab — würde ihn jedes
     * Paket selbst bilden, hiesse dasselbe System in zwei Oberflächen
     * irgendwann verschieden, und ein gespeicherter Filter wäre stumm falsch,
     * ohne dass etwas bricht.
     */
    public function key(): string
    {
        return $this->schluessel;
    }

    public function label(): string
    {
        return $this->beschriftung;
    }

    public function colour(): ?string
    {
        return $this->farbe;
    }

    public function supportsSubjects(): bool
    {
        return true;
    }

    public function supportsPerson(): bool
    {
        return false;
    }

    /**
     * Die Personenfrage beantwortet diese Quelle nicht — siehe Klassenkopf.
     *
     * Sie wirft trotzdem nicht: `supportsPerson()` sagt nein, und das Register
     * fragt sie damit gar nicht. Ein Wurf hier träfe nur jemanden, der an der
     * Registerprüfung vorbeigeht, und eine leere Liste ist für diesen Fall die
     * harmlosere Antwort als ein Abbruch der ganzen Aufgabenliste.
     *
     * @return array<int, ForeignTask>
     */
    public function tasks(int $userId): array
    {
        return [];
    }

    /**
     * Antwortet dieses System überhaupt noch auf den Vorgang?
     *
     * Gemessen, nicht konfiguriert: Verliert die Gegenseite die Fähigkeit oder
     * wird die Freigabe entzogen, verschwindet die Quelle von selbst — ohne
     * dass jemand daran denken muss, einen Schalter umzulegen.
     *
     * Die Prüfung steht hier und nicht nur im Registrierer, weil zwischen
     * „registriert" und „gefragt" Zeit liegt: Ein Seitenaufbau pflegt eine
     * Liste, ein Klick fragt sie ab.
     */
    public function isAvailable(): bool
    {
        if (! Bruecke::eingerichtet()) {
            return false;
        }

        foreach (AiBrain::providers(self::CAPABILITY) as $anbieter) {
            if (($anbieter['slug'] ?? null) === $this->slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Aufgaben, die dieses System zu dem Datensatz führt.
     *
     * `$type` ist das Wort des GEFRAGTEN Systems für seinen eigenen Datensatz.
     * Übersetzt wird hier nichts: Täte es das, wäre dieses Paket die Stelle,
     * an der drei Vokabulare abgeglichen werden — derselbe Fehler wie beim
     * Übersetzen der Zustandswörter.
     *
     * @return array<int, ForeignTask>
     *
     * @throws ForeignSourceFailed mit der Fehlerklasse des Komm-Layers
     */
    public function tasksForSubject(string $type, string|int $id): array
    {
        $antwort = AiBrain::gateway(self::CAPABILITY, [
            'subject_type' => $type,
            'subject_id' => (string) $id,
        ], $this->slug);

        // Gelesen wie auf der Personen-Seite, durch {@see RemoteResult}. Die
        // vier Schlüssel `ok`/`data`/`message`/`error` von Hand auseinander zu
        // nehmen stand hier ein zweites Mal — und eine zweite Stelle, an der
        // ein verrutschtes `??` einen Ausfall in einen leisen Erfolg dreht, ist
        // genau eine zu viel. Dieselbe Entpackung für beide Fragen (#9809).
        $ergebnis = RemoteResult::fromArray($antwort);

        // Strenger als `$ergebnis->ok`, und bewusst so gelassen: `fromArray()`
        // liest `(bool)`, hier gilt nur ein echtes `true`. Eine Antwort, die
        // etwas anderes sagt, ist eine kaputte Antwort — als Erfolg gelesen
        // ergäbe sie eine leere Liste, die sich als vollständige Auskunft
        // ausgibt, und das ist der Fehler, gegen den dieses Paket gebaut ist.
        //
        // Die beiden Lesarten gleichzuziehen ist eine Verhaltensänderung an
        // einer veröffentlichten Form und keine Aufräumarbeit — sie gehört
        // entschieden und nicht nebenbei gemacht (gemeldet am 11.11.2026).
        if (($antwort['ok'] ?? false) !== true) {
            // Die Fehlerklasse reist mit. „Nicht freigeschaltet" ist etwas
            // anderes als „antwortet nicht", und der Unterschied entscheidet,
            // was ein Mensch als nächstes tun kann — warten oder nachfragen.
            //
            // Und der TEXT reist jetzt auch mit. Vorher stand im Wurf nur der
            // allgemeine Satz „der Vorgang ist im System X gescheitert" — er
            // sagt, WO es gescheitert ist, und verschweigt, was drüben los
            // war, obwohl die Gegenstelle es mitgeschickt hatte.
            throw new ForeignSourceFailed(
                'Die Aufgaben von '.$this->label().' zu diesem Datensatz konnten nicht geholt werden: '
                    .($ergebnis->message ?? 'ohne Angabe der Gegenstelle'),
                $this->key(),
                'tasksForSubject',
                null,
                $ergebnis->error,
            );
        }

        // `rows()` gibt die Zeilen heraus — jede für sich, und nur die, die
        // eine ist. Es packt dabei einen Umschlag (`{count, data}`) aus, statt
        // ihn abzulehnen: Der Vertrag drüben ist die blanke Liste, aber die
        // Aufgaben-Werkzeuge haben den Umschlag geschickt, und die strenge
        // Lesart bestraft den Leser für den Fehler des Schreibers. Vorher fiel
        // hier in dem Fall ALLES weg — eine leere Liste, wo drüben Aufgaben
        // liegen.
        //
        // Das `id` bleibt Bedingung: Eine Aufgabe ohne Kennung ist nicht
        // anklickbar und nicht wiederfindbar. `is_array` nicht mehr — dafür
        // steht `rows()` schon ein.
        return array_values(array_map(
            fn (array $zeile) => $this->uebersetzen($zeile),
            array_filter($ergebnis->rows(), static fn (array $zeile): bool => isset($zeile['id'])),
        ));
    }

    /**
     * Eine fremde Zeile in einen {@see ForeignTask}.
     *
     * Was NICHT passiert: Zustand und Priorität werden nicht übersetzt. Die
     * Wörter der drei Systeme überschneiden sich in genau einem Status und in
     * drei von fünf Prioritäten — eine Zuordnung hier behauptete eine
     * Gleichheit, die es nicht gibt. Sie reisen mit ihrer Herkunft (dem Chip)
     * und werden so angezeigt.
     *
     * @param  array<string, mixed>  $zeile
     */
    private function uebersetzen(array $zeile): ForeignTask
    {
        return new ForeignTask(
            sourceKey: $this->key(),
            id: is_int($zeile['id']) ? $zeile['id'] : (string) $zeile['id'],
            title: (string) ($zeile['title'] ?? ''),
            status: isset($zeile['status']) ? (string) $zeile['status'] : null,
            priority: isset($zeile['priority']) ? (string) $zeile['priority'] : null,
            dueDate: isset($zeile['due_date']) ? (string) $zeile['due_date'] : null,
            urgency: isset($zeile['urgency_score']) ? (float) $zeile['urgency_score'] : null,
            // Die Adresse kommt von DRÜBEN. Raten kann sie niemand: Für die
            // Verwaltung liegen Vorgänge unter `/tickets/{id}`, im Manager
            // unter `/tasks/{id}` — eine geratene Adresse führt auf eine 404
            // und sieht dabei aus wie ein Weg, der funktioniert.
            url: isset($zeile['url']) ? (string) $zeile['url'] : null,
            kind: isset($zeile['kind']) ? (string) $zeile['kind'] : null,
            kindLabel: isset($zeile['kind_label']) ? (string) $zeile['kind_label'] : null,
            // Alles Übrige reist in `extra` mit — „anything the other system
            // wants shown".
            //
            // Nicht nach einer Liste erlaubter Felder gefiltert, und das ist
            // die Entscheidung: Was das Haus des Nachbarn verlässt, entscheidet
            // der NACHBAR, in seinem antwortenden Werkzeug. Eine Auswahl hier
            // wäre eine zweite Filterung an der falschen Stelle — und jedes
            // neue Anzeigefeld bräuchte eine Paketfreigabe.
            //
            // Was hier gebraucht wird, zeigt der Bestand: Die Auftragsseite
            // der Verwaltung zeigt heute `status_label` und den Zuständigen,
            // und beides hat in den festen Feldern von `ForeignTask` keinen
            // Platz. Ohne diesen Durchlass wäre der Umbau auf diese Quelle ein
            // Verlust an Anzeige.
            extra: array_diff_key($zeile, array_flip([
                'id', 'title', 'status', 'priority', 'due_date',
                'urgency_score', 'url', 'kind', 'kind_label',
            ])),
        );
    }
}
