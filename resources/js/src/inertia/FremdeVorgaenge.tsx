import { Deferred } from '@inertiajs/react';

import { Badge } from '../ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '../ui/card';

/**
 * Die Vorgänge der ANDEREN Systeme zu einem Datensatz (AI Brain #9669).
 *
 * ## Warum der Hinweis OBEN steht
 *
 * Wer eine Liste von oben liest, hat sie schon für vollständig genommen, bevor
 * er unten ankommt. „Drei von fünf" sieht aus wie „genau drei" — und auf der
 * Seite ist nichts, woran man das Fehlen merkt. Deshalb zuerst die Absage,
 * dann die Zeilen.
 *
 * ## Warum der Zustand NICHT übersetzt wird
 *
 * Die Zustandswörter der drei Systeme sind nicht dieselben: zwei von ihnen
 * überschneiden sich in genau einem Status und in drei von fünf Prioritäten.
 * Ein gemeinsames Vokabular zu behaupten wäre eine Gleichheit, die es nicht
 * gibt. Der Zustand steht deshalb neben seiner HERKUNFT — dem Quellen-Chip.
 *
 * ## Warum das hier liegt und nicht im Produkt
 *
 * Es lag am 11.11.2026 kurz zweimal da — in der Verwaltung und im CRM, Byte
 * für Byte gleich —, weil dieses Paket bis dahin keine JS-Seite hatte. Mit dem
 * Manager wäre die dritte Kopie fällig gewesen, und ab da läuft so etwas
 * auseinander: Wer die Karte in einem Produkt verbessert, verbessert sie nicht
 * in den anderen, und zwar lautlos, weil nichts bricht.
 *
 * Unter `/inertia` und nicht im Grundeinstieg, nach dem Vorbild des
 * Mailbox-Pakets: `Deferred` kommt von `@inertiajs/react`, und wer das Paket
 * ohne Inertia benutzt, soll es trotzdem installieren können.
 */

export interface FremderVorgang {
    source: string;
    id: string;
    foreign_id: string | number;
    title: string;
    status?: string | null;
    priority?: string | null;
    due_date?: string | null;
    url?: string | null;
    kind_label?: string | null;
    extra?: { status_label?: string; assigned_user?: { id: number; name: string } | null };
}

export interface FremdeQuelle {
    key: string;
    label: string;
    colour?: string | null;
}

/**
 * Ein System, das nicht antworten konnte — in der Form, die `SourceFailure`
 * herausgibt.
 */
export interface Ausfall {
    source: string;
    /**
     * Die KLASSE, nicht der Satz: not_offered, not_allowed, unreachable oder
     * tool_failed.
     *
     * Die Namen sind genau herum, wie sie klingen — das war im ersten Wurf
     * (meinem) vertauscht und ist in `SourceFailure` richtiggestellt worden:
     * `reason` ist einer der vier, `message` ist der Satz, den ein Mensch
     * liest.
     */
    reason?: string | null;
    /** Der Satz, den das andere System selbst geschickt hat. */
    message?: string | null;
    may_retry?: boolean;
}

/**
 * Die Form von `TaskHarvest::toArray()`, ergänzt um `sources`.
 *
 * `sources` steht NICHT in der Ernte: Welches System wie heisst und welche
 * Farbe es hat, ist eine Frage der Anzeige und nicht des Einsammelns. Das
 * Produkt legt es daneben.
 */
export interface FremdeVorgaengeDaten {
    tasks: FremderVorgang[];
    ok: string[];
    failures: Record<string, Ausfall>;
    complete: boolean;
    sources: Record<string, FremdeQuelle>;
}

interface Props {
    /** Der Name der verzögerten Inertia-Eigenschaft. */
    eigenschaft: string;
    daten?: FremdeVorgaengeDaten | null;
    titel?: string;
    beschreibung?: string;
    /** Was dasteht, wenn wirklich nichts dazugehört. */
    leer?: string;
    /**
     * Ein Satz des Produkts unter der Liste — etwa, dass ein Teil fehlt,
     * weil das Recht dafür fehlt (peppermint-manager #10342).
     *
     * Der Satz kommt vom Produkt, weil nur es weiß, was fehlt. Die
     * Darstellung liegt hier, damit sie in allen Produkten gleich ist und
     * kein Produkt einen eigenen Absatz unter die Karte setzt. Erscheint
     * erst mit den Daten, also innerhalb von `Deferred`.
     */
    hinweis?: string | null;
}

function satzZurAbsage(fehlt: Ausfall): string {
    if (fehlt.reason === 'not_allowed') {
        return 'ist für diesen Zugriff nicht freigeschaltet.';
    }

    if (fehlt.reason === 'not_offered') {
        return 'beantwortet diese Frage nicht.';
    }

    if (fehlt.reason === 'tool_failed') {
        // Die Gegenstelle HAT geantwortet — nur eben mit einem Fehler. „Antwortet
        // gerade nicht" war hier falsch (Bug #1551): Die Verwaltung lehnte jede
        // Frage nach einem Projekt mit demselben Satz ab, und die Karte bot
        // tagelang das Neuladen an, als sei sie nur kurz weg gewesen. Was der
        // Fehler bedeutet, sagt ihr eigener Satz darunter.
        return 'hat die Frage mit einem Fehler beantwortet.';
    }

    // „Unerreichbar" kann vorübergehen — nur dann darf ein erneutes Laden
    // angeboten werden. Bei einer fehlenden Freigabe wäre es ein falsches
    // Versprechen.
    return fehlt.may_retry ? 'antwortet gerade nicht — ein erneutes Laden kann helfen.' : 'konnte nicht gefragt werden.';
}

/**
 * Der Satz der Gegenstelle — nur, wenn sie überhaupt etwas gesagt hat.
 *
 * Bei `tool_failed`, `not_offered` und `not_allowed` hat das andere System
 * geantwortet, und sein Satz ist meist genauer als unserer: „Dieses System
 * führt Vorgänge zu auftrag" sagt, warum hier nichts kommt. Bei `unreachable`
 * (und ohne Klasse) hat es nichts gesagt; was dann in `message` steht, ist die
 * Leitung („cURL error 28 …") und taugt für den Menschen davor nicht. Es bleibt
 * im Titel, für wen es sucht.
 */
function satzDerGegenstelle(fehlt: Ausfall): string | null {
    const geantwortet = fehlt.reason === 'tool_failed' || fehlt.reason === 'not_offered' || fehlt.reason === 'not_allowed';
    const satz = fehlt.message?.trim();

    return geantwortet && satz ? satz : null;
}

export function FremdeVorgaenge({
    eigenschaft,
    daten,
    titel = 'Vorgänge der anderen Systeme',
    beschreibung,
    leer = 'In den anderen Systemen liegt nichts dazu.',
    hinweis,
}: Props) {
    const fehlend = daten ? Object.values(daten.failures) : [];

    return (
        <Card>
            <CardHeader>
                <CardTitle>{titel}</CardTitle>
                {beschreibung && <CardDescription>{beschreibung}</CardDescription>}
            </CardHeader>
            <CardContent>
                <Deferred
                    data={eigenschaft}
                    fallback={
                        <div className="space-y-2">
                            <div className="h-11 animate-pulse rounded-md bg-muted" />
                            <div className="h-11 animate-pulse rounded-md bg-muted" />
                        </div>
                    }
                >
                    <div className="space-y-3">
                        {fehlend.length > 0 && (
                            <div
                                role="status"
                                className="rounded-md border border-warning-border bg-warning-subtle p-2 text-sm text-warning-subtle-foreground"
                            >
                                <p className="font-medium">Diese Liste ist unvollständig.</p>
                                <ul className="mt-1 space-y-0.5 text-xs opacity-90">
                                    {fehlend.map((fehlt) => {
                                        const ihrSatz = satzDerGegenstelle(fehlt);

                                        return (
                                            <li key={fehlt.source}>
                                                {/*
                                                  * Vorne der verlaessliche, aus der Klasse gebaute
                                                  * Satz. Darunter der des anderen Systems, wenn es
                                                  * etwas gesagt hat (Bug #1551) — als roher Text aus
                                                  * einem fremden Haus nie als Markup, auf zwei
                                                  * Zeilen begrenzt, ganz im Titel.
                                                  */}
                                                <span className="font-medium">{daten?.sources[fehlt.source]?.label ?? fehlt.source}</span>{' '}
                                                <span title={fehlt.message ?? undefined}>{satzZurAbsage(fehlt)}</span>
                                                {ihrSatz && (
                                                    <span className="mt-0.5 line-clamp-2 opacity-80" title={ihrSatz}>
                                                        {ihrSatz}
                                                    </span>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        )}

                        {daten && daten.tasks.length > 0 ? (
                            <div className="space-y-2">
                                {daten.tasks.map((vorgang) => {
                                    const quelle = daten.sources[vorgang.source];
                                    const zustand = vorgang.extra?.status_label ?? vorgang.status;

                                    return (
                                        <div key={vorgang.id} className="flex items-center gap-2 rounded-md border p-2 text-sm">
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate font-medium">
                                                    {vorgang.url ? (
                                                        <a href={vorgang.url} target="_blank" rel="noreferrer" className="hover:underline">
                                                            {vorgang.title}
                                                        </a>
                                                    ) : (
                                                        vorgang.title
                                                    )}
                                                </p>
                                                {vorgang.extra?.assigned_user && (
                                                    <p className="text-xs text-muted-foreground">{vorgang.extra.assigned_user.name}</p>
                                                )}
                                            </div>
                                            {zustand && (
                                                <Badge variant="outline" className="shrink-0 text-xs">
                                                    {zustand}
                                                </Badge>
                                            )}
                                            {/*
                                              * Die Systemfarbe als PUNKT, nicht als Fuellung.
                                              *
                                              * Beim Ansehen im Browser aufgefallen, und zwar zweimal:
                                              * Eine gefuellte Marke in #f59e0b trug weisse Schrift mit
                                              * rund 2:1 Kontrast — lesbar nur, wenn man schon weiss,
                                              * was dasteht. Und sie sah aus wie der Warnkasten
                                              * darueber, der dieselbe Farbfamilie hat und das
                                              * GEGENTEIL sagt.
                                              *
                                              * Ein Punkt traegt dieselbe Auskunft, ohne dass die
                                              * Lesbarkeit an der Farbe haengt: Die Schrift bleibt die
                                              * der Oberflaeche, egal welche Farbe ein System bekommt.
                                              */}
                                            <Badge variant="secondary" className="shrink-0 gap-1.5 text-xs">
                                                {quelle?.colour && (
                                                    <span
                                                        aria-hidden="true"
                                                        className="size-1.5 shrink-0 rounded-full"
                                                        style={{ backgroundColor: quelle.colour }}
                                                    />
                                                )}
                                                {quelle?.label ?? vorgang.source}
                                            </Badge>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {daten && Object.keys(daten.sources).length === 0 ? 'Kein anderes System beantwortet diese Frage.' : leer}
                            </p>
                        )}

                        {hinweis && (
                            <p data-slot="fremde-vorgaenge-hinweis" className="text-xs text-muted-foreground">
                                {hinweis}
                            </p>
                        )}
                    </div>
                </Deferred>
            </CardContent>
        </Card>
    );
}
