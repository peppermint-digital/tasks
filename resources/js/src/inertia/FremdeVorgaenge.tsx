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
}

function satzZurAbsage(fehlt: Ausfall): string {
    if (fehlt.reason === 'not_allowed') {
        return 'ist für diesen Zugriff nicht freigeschaltet.';
    }

    if (fehlt.reason === 'not_offered') {
        return 'beantwortet diese Frage nicht.';
    }

    // „Unerreichbar" und „das Werkzeug ist gescheitert" können vorübergehen —
    // nur dann darf ein erneutes Laden angeboten werden. Bei einer fehlenden
    // Freigabe wäre es ein falsches Versprechen.
    return fehlt.may_retry
        ? 'antwortet gerade nicht — ein erneutes Laden kann helfen.'
        : 'konnte nicht gefragt werden.';
}

export function FremdeVorgaenge({
    eigenschaft,
    daten,
    titel = 'Vorgänge der anderen Systeme',
    beschreibung,
    leer = 'In den anderen Systemen liegt nichts dazu.',
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
                                className="rounded-md border border-amber-300 bg-amber-50 p-2 text-sm dark:border-amber-900 dark:bg-amber-950"
                            >
                                <p className="font-medium text-amber-900 dark:text-amber-200">Diese Liste ist unvollständig.</p>
                                <ul className="mt-1 space-y-0.5 text-xs text-amber-800 dark:text-amber-300">
                                    {fehlend.map((fehlt) => (
                                        <li key={fehlt.source}>
                                            {/*
                                              * `message` als Titel: Der Satz, den das andere
                                              * System geschickt hat, ist oft genauer als unser
                                              * aus der Klasse gebauter — aber er ist auch roher
                                              * Text aus einem fremden Haus. Vorne steht der
                                              * verlaessliche Satz, daneben steht seiner.
                                              */}
                                            <span className="font-medium">{daten?.sources[fehlt.source]?.label ?? fehlt.source}</span>{' '}
                                            <span title={fehlt.message ?? undefined}>{satzZurAbsage(fehlt)}</span>
                                        </li>
                                    ))}
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
                    </div>
                </Deferred>
            </CardContent>
        </Card>
    );
}
