<?php

namespace Peppermint\Tasks\Sources;

use Illuminate\Support\Facades\Auth;
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
 * ## It answers BOTH questions — one system, one entry
 *
 * Until 13.11.2026 this class answered the subject question only, and the
 * reason given was: "my tasks over there already flows product → Brain →
 * product, a second path to the same rows would be a second truth."
 *
 * That premise does not hold, and it was measured rather than argued (#9887,
 * 13.11.2026): the hub COLLECTS the tasks of all products, but no product
 * receives that collection. The Manager's `App\Tasks\Sources\AiBrainTaskSource`
 * asks `list-tasks-tool` — the hub's OWN tasks, not the collection
 * (`list-product-tasks-tool`) — and its own class head says it plainly:
 * "Beide Systeme zeigen die Aufgaben des jeweils anderen, keines ist die
 * Mitte." The path product → Brain exists; product → product did not.
 *
 * So the person question had no answer at all. A planning mask asking "which
 * system, then which task" could therefore only ever show its own tasks, which
 * is exactly what happened.
 *
 * Why it had to be THIS class and not a second one beside it: the registry
 * holds ONE source per key, and both halves derive their key from the same
 * system identity. A second source per system silently replaced the first —
 * measured in the Manager on 13.11.2026, `forSubjects()` went empty and four
 * tests of #9815 died without a word (Bug #1466 closed that silence).
 *
 * A system is ONE entry, and which questions it answers is a property of it.
 *
 * ## Und die SCHREIBENDE Haelfte, seit dem 16.11.2026 (#9898)
 *
 * Bis dahin war diese Klasse rein lesend, und damit der ganze Verbund: Die
 * schreibende Haelfte eines Aufgaben-Vertrags gab es nur in AI Brain
 * (`app/Tasks/Sources/ConnectedProductTaskSource.php`, `extends
 * WritableTaskSource`) — und das ist die Nabe, nicht ein Produkt. **Ueber die
 * Bruecke konnte kein Produkt in ein anderes schreiben.**
 *
 * Eine Maske, die „welches System, dann welche Aufgabe" fragt, endete also an
 * der Stelle, an der sie den Zeitraum hinschreiben muesste. Deshalb steht hier
 * jetzt {@see PlansForeignTasks} — ein ZUSAETZLICHER Vertrag, per `instanceof`
 * gefragt, und ausdruecklich keine Erweiterung von {@see WritableTaskSource}:
 * Dessen abstrakte Methoden zu vermehren braeche jede Umsetzung, und AI Brain
 * hat eine. Die Begruendung in ganzer Laenge steht am Vertrag.
 */
final class BrueckenSubjektquelle extends TaskSource implements PlansForeignTasks
{
    /** Der Vorgang, den die Gegenseite fuer die Datensatz-Frage anbietet. */
    public const CAPABILITY = 'tasks.subject.list';

    /** Der Vorgang fuer die Personen-Frage — „meine Aufgaben dort". */
    public const CAPABILITY_PERSON = 'tasks.list';

    /**
     * Wie viele Zeilen die Personen-Frage anfordert.
     *
     * Dieselbe Zahl und derselbe Grund wie bei {@see RemoteTaskSource}: Ohne
     * das Argument antwortet jedes der drei Produkte mit 50, und eine Liste,
     * die bei 50 aufhoert, ist nicht unvollstaendig, sondern falsch — nichts
     * sagt, dass sie abgeschnitten wurde.
     */
    public const ZEILEN = 500;

    /**
     * @param  bool  $personen  Beantwortet dieses System auch die Personen-Frage?
     *
     * Ein WERT und keine Abfrage, und das ist keine Geschmacksfrage: Die
     * Systemliste einer Maske muss beim Zeichnen schon dastehen. Der
     * `TaskSourcePicker` im Kalenderpaket hat deshalb absichtlich keinen Lade-
     * und keinen Ausfallzustand, und sein Docblock begruendet das damit, dass
     * die Liste „niemanden fragt". Eine Quelle, die beim Zeichnen ins Netz
     * ginge, naehme genau diese Zusicherung weg — und die Komponente haette
     * keine Stelle, an der sie den Ausfall anzeigen koennte.
     *
     * Gefragt wird deshalb EINMAL, im Anmelder ({@see BrueckenSubjektquellen}),
     * der ohnehin schon fragt. Hier liegt nur noch die Antwort.
     */
    public function __construct(
        private readonly string $slug,
        private readonly string $schluessel,
        private readonly string $beschriftung,
        private readonly ?string $farbe = null,
        private readonly bool $personen = false,
        private readonly bool $subjekte = true,
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

    /**
     * Auch hier nur der Wert — aus demselben Grund wie bei
     * {@see supportsPerson()}.
     *
     * Stand bis zum 13.11.2026 fest auf `true`, und das war richtig, solange
     * der Anmelder nur ueber `tasks.subject.list` lief: Wer dort stand, konnte
     * die Frage beantworten. Seit der Anmelder die VEREINIGUNG beider Vorgaenge
     * anmeldet, ist es eine Eigenschaft des Systems und keine der Klasse.
     */
    public function supportsSubjects(): bool
    {
        return $this->subjekte;
    }

    /**
     * Nur der Wert aus dem Konstruktor — hier wird niemand gefragt.
     *
     * Siehe die Begruendung am Konstruktor: Daran haengt die Zusicherung, dass
     * die Systemliste einer Maske lokale Angabe ist.
     */
    public function supportsPerson(): bool
    {
        return $this->personen;
    }

    /**
     * Die offenen Aufgaben DIESER Person im anderen System.
     *
     * ## Warum keine Person im Aufruf steht
     *
     * Weil sie nicht hineingehoert. Die handelnde Person reist am Komm-Layer
     * mit — dieselbe, die angemeldet ist —, und das Zielsystem antwortet fuer
     * sie und fuer niemanden sonst. Eine Kennung im Argument waere die
     * Einladung, nach fremden Aufgaben zu fragen; der Riegel steht drueben,
     * aber eine Frage, die abgewiesen werden MUSS, stellt man gar nicht erst.
     *
     * Bastian am 11.11.2026: „Natuerlich darf ich nur meine Aufgaben sehen."
     *
     * ## Warum trotzdem nicht fuer jede Kennung
     *
     * `$userId` ist die Person, deren Liste gemeint ist. Ist das nicht die
     * angemeldete, findet die Frage NICHT statt — und zwar hier und nicht
     * drueben: Ein Aufruf, dessen Identitaet unterwegs verlorengeht, macht die
     * Antwort WEITER statt enger. Das war Bug #657 im Kalender, und in einer
     * Aufgabenliste hiesse es, jemandem die Aufgaben eines anderen zu zeigen.
     *
     * ## Nur offene, und nicht die ersten funfzig
     *
     * Dieselben zwei Argumente, die {@see RemoteTaskSource} sendet, und aus
     * denselben gemessenen Gruenden (#8471): Ohne `limit` antworten alle drei
     * Produkte mit 50 — eine Liste, die aufhoert, ohne es zu sagen. Und ohne
     * `active_only` waeren am lebenden Manager 438 von 470 Zeilen erledigt:
     * vollstaendig und unbrauchbar im selben Schritt.
     *
     * @return array<int, ForeignTask>
     *
     * @throws ForeignSourceFailed mit der Fehlerklasse des Komm-Layers
     */
    public function tasks(int $userId): array
    {
        if (! $this->personen) {
            // Das Register fragt gar nicht erst — `supportsPerson()` sagt
            // nein. Der Wurf traefe nur jemanden, der an der Registerpruefung
            // vorbeigeht, und eine leere Liste ist dafuer die harmlosere
            // Antwort als ein Abbruch der ganzen Aufgabenliste.
            return [];
        }

        if ($userId !== (int) (Auth::id() ?? 0)) {
            // Fail-closed. Siehe oben: eine fremde Liste gibt es hier nicht,
            // auch nicht fuer Leitungsrollen.
            return [];
        }

        $ergebnis = RemoteResult::fromArray(AiBrain::gateway(self::CAPABILITY_PERSON, [
            'limit' => self::ZEILEN,
            'active_only' => true,
        ], $this->slug));

        if (! $ergebnis->ok) {
            // Ein Ausfall ist kein leeres Ergebnis. Das Register protokolliert
            // und laesst die Quelle aus (`collect()`) oder nennt sie in der
            // Antwort (`harvest()`) — gelesen wird beides aus diesem Wurf.
            throw new ForeignSourceFailed(
                $this->label().' nicht erreichbar: '
                    .($ergebnis->message ?? 'ohne Angabe der Gegenstelle'),
                $this->key(),
                'tasks',
                null,
                $ergebnis->error,
            );
        }

        return array_values(array_map(
            fn (array $zeile) => $this->uebersetzen($zeile),
            array_filter($ergebnis->rows(), static fn (array $zeile): bool => isset($zeile['id'])),
        ));
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
     *
     * Das bleibt so, und der {@see AnbieterMerker} ändert daran nichts: Er
     * merkt die Antwort nur für die Dauer EINER Anfrage. Anmeldung und Abfrage
     * liegen in verschiedenen Anfragen — die Zeit dazwischen bleibt also
     * genauso ungeprüft, wie dieser Absatz es verlangt. Gespart wird allein,
     * dieselbe Frage im selben Atemzug mehrfach zu stellen (Bug #1469).
     */
    public function isAvailable(): bool
    {
        if (! Bruecke::eingerichtet()) {
            return false;
        }

        // Den Merker zur AUFRUFZEIT holen und nicht im Konstruktor halten.
        //
        // Bis zum 17.11.2026 stand hier als Grund, das Register sei ein
        // Singleton und ein festgehaltener Merker ueberlebte deshalb seine
        // Anfrage. Das Register ist seit Bug #1479 `scoped`, der Griff bleibt
        // aber derselbe — und der Grund ist jetzt der allgemeinere: Die
        // Lebensdauer des Merkers darf nicht davon abhaengen, wie lange der
        // lebt, der ihn HAELT. Wer diese Quelle in etwas Langlebiges legt — ein
        // Produkt, das das Register in einen eigenen `singleton()` spritzt —,
        // bekaeme sonst lautlos den Zwischenspeicher mit Frist zurueck, den die
        // Bruecke ablehnt. Der Container gibt je Anfrage einen frischen
        // (`scoped`, siehe TasksServiceProvider).
        $merker = app(AnbieterMerker::class);

        // Gefragt wird nur nach den Vorgaengen, die diese Quelle ueberhaupt
        // FUEHRT, und es wird beim ersten Treffer aufgehoert. Ein System, das
        // beide beantwortet — heute alle drei —, kostet damit genau einen
        // Aufruf, so viele wie vorher. Ein System, das nur einen fuehrt, wird
        // nach dem RICHTIGEN gefragt statt nach dem anderen.
        foreach ($this->gefuehrteVorgaenge() as $vorgang) {
            if ($merker->bedient($vorgang, $this->slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Die Vorgaenge, die diese Quelle beantwortet.
     *
     * @return array<int, string>
     */
    private function gefuehrteVorgaenge(): array
    {
        return array_values(array_filter([
            $this->subjekte ? self::CAPABILITY : null,
            $this->personen ? self::CAPABILITY_PERSON : null,
        ]));
    }

    /**
     * Beantwortet dieses System einen der drei Planungs-Vorgänge?
     *
     * Gemessen am Fähigkeits-Register, nicht am Konstruktor — und damit
     * anders als {@see supportsPerson()} und {@see supportsSubjects()}, die
     * Werte sind. Der Unterschied hat einen Grund und ist keiner aus
     * Bequemlichkeit:
     *
     * Jene beiden stehen in der Systemliste, die eine Maske beim ZEICHNEN
     * braucht — der `TaskSourcePicker` im Kalenderpaket hat deshalb
     * absichtlich keinen Lade- und keinen Ausfallzustand. Die Planung steht
     * nicht dort: Sie ist der SCHRITT NACH der Auswahl, und an ihm hängt kein
     * Versprechen über eine Liste, die ohne Netz dastehen muss.
     *
     * Umgekehrt kostet es nichts: Der {@see AnbieterMerker} hält die Antwort
     * für die Dauer EINER Anfrage, also ein Netzaufruf je Vorgang je
     * Seitenaufbau — und nur, wenn überhaupt jemand nach der Planung fragt.
     * Drei weitere Konstruktorwerte hiessen drei weitere Abfragen im Anmelder,
     * bei jedem Seitenaufbau, auch wenn niemand plant (Bug #1469 zählt nach,
     * was das kostet).
     *
     * Und es ist dieselbe Prüfung, die {@see isAvailable()} schon macht,
     * mitsamt deren Begründung: Zwischen „registriert" und „gefragt" liegt
     * Zeit.
     *
     * Das Register fragt hier NICHT mehr (#10056). Diese Antwort bildet vier
     * Lagen auf ein `false` ab — Nabe weg, Fehlerantwort, Freigabe fehlt,
     * Fähigkeit fehlt —, und eine Maske, die damit VOR dem Zeichnen filtert,
     * schickt den Leser bei drei davon an die falsche Stelle.
     * {@see TaskSourceRegistry::plannable()} fragt deshalb nur `instanceof`,
     * und den Grund liefert der Versuch: {@see plan()} wirft mit der
     * Fehlerklasse der Gegenstelle.
     *
     * Ein Vorgang, der keiner der drei ist, bekommt hier `false` und keinen
     * Wurf — laut wird die eine Stelle, die eine Maske fragt
     * ({@see TaskSourceRegistry::plannableFor()}). Zwei Stellen, die über
     * denselben Tippfehler werfen, sind eine zu viel.
     */
    public function offers(string $capability): bool
    {
        if (! in_array($capability, self::CAPABILITIES, true)) {
            return false;
        }

        if (! Bruecke::eingerichtet()) {
            return false;
        }

        // Zur Aufrufzeit geholt und nicht im Konstruktor gehalten — siehe die
        // Begruendung an {@see isAvailable()}: Die Lebensdauer des Merkers darf
        // nicht an der des Halters haengen.
        return app(AnbieterMerker::class)->bedient($capability, $this->slug);
    }

    /**
     * Plant Zeit an einer Aufgabe des anderen Systems ein.
     *
     * Hin gehen die Kennung der Aufgabe DRÜBEN und der Zeitraum, und sonst
     * nichts — was nicht mitgeht und warum, steht an {@see TimeSpan}. Zurück
     * kommt die Fassung des anderen Systems.
     */
    public function plan(int $userId, string|int $taskId, TimeSpan $span): PlannedBlock
    {
        return PlannedBlock::fromRow(
            $this->key(),
            self::CAPABILITY_CREATE,
            $this->schreibend($userId, self::CAPABILITY_CREATE, [
                'task_id' => (string) $taskId,
            ] + $span->toArray()),
        );
    }

    /**
     * Verschiebt einen Block, den es drüben schon gibt.
     *
     * Die Kennung ist die des BLOCKS in seinem eigenen System. Eine lokale
     * Terminkennung gibt es hier nicht und wäre drüben nicht adressierbar.
     */
    public function move(int $userId, string|int $blockId, TimeSpan $span): PlannedBlock
    {
        return PlannedBlock::fromRow(
            $this->key(),
            self::CAPABILITY_UPDATE,
            $this->schreibend($userId, self::CAPABILITY_UPDATE, [
                'block_id' => (string) $blockId,
            ] + $span->toArray()),
        );
    }

    /**
     * Gibt die geplante Zeit zurück.
     *
     * Ohne Rückgabe, wie `WritableTaskSource::delete()`: Was es nicht mehr
     * gibt, kann nichts zurückgeben. Die Antwort wird trotzdem gelesen — nur
     * eben auf „hat es geklappt" und nicht auf eine Zeile.
     */
    public function unplan(int $userId, string|int $blockId): void
    {
        $this->schreibend($userId, self::CAPABILITY_DELETE, [
            'block_id' => (string) $blockId,
        ]);
    }

    /**
     * Ein schreibender Vorgang über die Brücke — die eine Stelle für alle drei.
     *
     * ## Warum die fremde Person hier abgewiesen wird und nicht drüben
     *
     * Dieselbe Sperre wie in {@see tasks()}, aus demselben gemessenen Grund:
     * Ein Aufruf, dessen Identität unterwegs verlorengeht, macht die Antwort
     * WEITER statt enger (Bug #657 im Kalender). Beim Lesen hiesse das,
     * jemandem fremde Aufgaben zu zeigen; beim Planen hiesse es, einem anderen
     * Menschen Zeit in den Kalender zu schreiben — und genau das verweigert
     * die Weboberfläche daneben auch
     * (`CreateCalendarEventTool.php:27-34` in der Verwaltung).
     *
     * Beim Lesen ist die Antwort darauf eine leere Liste. Hier nicht: Ein
     * Schreibweg, der nichts tut und nichts sagt, hinterlässt einen Block, den
     * jemand ANGELEGT ZU HABEN GLAUBT und der nirgends existiert
     * ({@see ForeignSourceFailed}).
     *
     * `not_allowed` und keine fünfte Fehlerklasse. Die Ablehnung steht zwar
     * hier und nicht drüben, aber die vier Namen sagen, was ein Mensch als
     * nächstes tun kann, und „es fehlt eine Erlaubnis" ist die richtige
     * Auskunft. Dazu passt, dass `mightPassByItself()` dafür falsch ist:
     * Warten hilft nicht.
     *
     * ## Warum die Antwort als nackte Nutzlast gelesen wird
     *
     * Weil das die Form ist, die 15 der 18 Kalender-Werkzeuge in den drei
     * Produkten haben, und die zugesagte für die drei neuen (Vorschlag #9898,
     * Abschnitt 6). Die drei Manager-Werkzeuge antworten heute mit einer Hülle
     * und einem `status`, dessen Wortschatz je Werkzeug anders ist —
     * `confirmation_required` heisst „nichts getan" und sieht aus wie Erfolg.
     *
     * Ein inneres `ok` wird deshalb NICHT gelesen: Keines der 18 führt eines,
     * und wer einem Aufrufer sagt „lies es selbst", schickt ihn an eine
     * Stelle, an der nichts steht. Gelesen wird die äussere Zusage über
     * {@see RemoteResult} — streng, nur ein echtes `true` gilt.
     *
     * Hält ein Produkt die Form nicht ein, kommt die Nutzlast ohne `id`,
     * `starts_at` oder `ends_at` an, und {@see PlannedBlock::fromRow()} wirft
     * mit `tool_failed`. Laut, nicht lautlos — das ist der Zweck dieser
     * Arbeitsteilung.
     *
     * @param  array<string, mixed>  $argumente
     * @return array<string, mixed> die nackte Nutzlast, bei `delete` leer
     *
     * @throws ForeignSourceFailed
     */
    private function schreibend(int $userId, string $capability, array $argumente): array
    {
        if ($userId !== (int) (Auth::id() ?? 0)) {
            throw new ForeignSourceFailed(
                'In '.$this->label().' wird nur die eigene Zeit geplant.',
                $this->key(),
                $capability,
                null,
                SourceFailure::NOT_ALLOWED,
            );
        }

        $ergebnis = RemoteResult::fromArray(
            AiBrain::gateway($capability, $argumente, $this->slug)
        );

        if (! $ergebnis->ok) {
            // Die Fehlerklasse UND der Text reisen mit, wie auf den lesenden
            // Wegen: „nicht freigeschaltet" ist etwas anderes als „antwortet
            // nicht", und der Unterschied entscheidet, was ein Mensch als
            // naechstes tun kann.
            throw new ForeignSourceFailed(
                'Der Zeitblock in '.$this->label().' ist nicht zustande gekommen: '
                    .($ergebnis->message ?? 'ohne Angabe der Gegenstelle'),
                $this->key(),
                $capability,
                null,
                $ergebnis->error,
            );
        }

        // `data` als EINE Zeile und nicht ueber `rows()`: Dort wird eine LISTE
        // erwartet, und `array_filter(..., 'is_array')` liesse von einer
        // einzelnen Zeile genau die Skalare weg — heraus kaeme eine leere
        // Zeile, die wie eine Antwort aussieht.
        return $ergebnis->data ?? [];
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

        // `$ergebnis->ok` ist jetzt dieselbe strenge Lesart, die hier von Hand
        // stand: nur ein echtes `true` gilt. Sie ist nach `fromArray()`
        // gezogen worden (#9809), weil der Fall, den sie abfängt, nicht dieser
        // Quelle gehört, sondern der Antwortform — `ok: "false"` als `(bool)`
        // gelesen ergibt einen ERFOLG, und heraus kommt eine leere Liste, die
        // sich als vollständige Auskunft ausgibt. Die Begründung steht dort,
        // an der einen Stelle, die beide Fragen lesen.
        if (! $ergebnis->ok) {
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
