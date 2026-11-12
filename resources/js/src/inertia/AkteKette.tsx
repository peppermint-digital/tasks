import { Deferred } from '@inertiajs/react';

/**
 * Die Kette der Akte zu einem Datensatz (AI Brain #9815).
 *
 * „Aus Deal 4312 · Projekt 884" — eine Zeile, kein vierter Karteikasten. Die
 * Akte ist das unsichtbare Bindeglied zwischen den drei Stufen und soll keine
 * eigene Fläche beanspruchen; sie sagt nur, wo der Rest des Vorgangs liegt.
 *
 * ## Warum das hier liegt und nicht als Steckplatz im Produkt
 *
 * Alle drei Produkte zeigen dasselbe. Die Prüffrage aus Wiki #744 lautet:
 * *Wenn das zweite Produkt diesen Platz nie füllt — ist das richtig, oder ein
 * Mangel, den niemand meldet?* Hier wäre es ein Mangel. Ein durchgereichter
 * `ReactNode` schriebe fest, dass zwei der drei es nicht zeichnen, und das
 * fiele niemandem auf: Eine Oberfläche ohne Zeile sieht nicht kaputt aus.
 *
 * Das Produkt gibt die Verweise, das Paket entscheidet die Darstellung.
 *
 * ## Drei Zustände, und der dritte ist der, den man vergisst
 *
 * | Lage | Anzeige |
 * |---|---|
 * | Akte da, Verweise da | die Kette |
 * | `gefunden: false` | nichts — der Normalfall beim Bestandskunden |
 * | Absage | ein Satz, der sagt WARUM |
 *
 * „Nichts dazu" und „ich konnte nicht fragen" sind zwei verschiedene Dinge.
 * Wer beides als leere Zeile zeigt, behauptet im zweiten Fall etwas Falsches —
 * und zwar lautlos. Dieselbe Unterscheidung wie in {@see FremdeVorgaenge}.
 *
 * ## Verweise ohne Adresse bleiben sichtbar
 *
 * Nur eben nicht anklickbar. Ein Verweis ohne Sprungziel ist weiterhin eine
 * richtige Aussage über die Kette — ihn zu verbergen wäre eine falsche.
 */

/** Ein Verweis, so wie `get-dossier-for-record-tool` ihn herausgibt. */
export interface AkteVerweis {
    system: string;
    art: string;
    kennung: string;
    /**
     * Der volle Verweis, vom Brain aus Pfad und `web_url` zusammengesetzt.
     *
     * Nullbar: Verweise von vor dem 12.11.2026 tragen keinen, und ein System,
     * das nie gebunden hat, trägt ihn auch nicht nach.
     */
    adresse?: string | null;
}

/** Ein System, das nicht antworten konnte — dieselbe Form wie in `SourceFailure`. */
export interface AkteAusfall {
    /** Die KLASSE: not_offered, not_allowed, unreachable oder tool_failed. */
    reason?: string | null;
    /** Der Satz, den das andere System selbst geschickt hat. */
    message?: string | null;
    may_retry?: boolean;
}

export interface AkteKetteDaten {
    gefunden: boolean;
    kennung?: string | null;
    verweise?: AkteVerweis[];
    /** Gesetzt, wenn gar nicht gefragt werden konnte — NICHT wenn nichts da ist. */
    ausfall?: AkteAusfall | null;
}

interface Props {
    /** Der Name der verzögerten Inertia-Eigenschaft. */
    eigenschaft: string;
    daten?: AkteKetteDaten | null;
    /**
     * Wie die Stufen heißen sollen. Die Wörter gehören dem Produkt, nicht dem
     * Paket: Was im CRM „Deal" heißt, heißt woanders vielleicht anders.
     */
    benennung?: Record<string, string>;
}

const VORGABE: Record<string, string> = {
    deal: 'Deal',
    auftrag: 'Auftrag',
    projekt: 'Projekt',
};

function satzZurAbsage(ausfall: AkteAusfall): string {
    if (ausfall.reason === 'not_allowed') {
        return 'Die Kette ist für diesen Zugriff nicht freigeschaltet.';
    }

    if (ausfall.reason === 'not_offered') {
        return 'Die Kette lässt sich hier nicht abfragen.';
    }

    // „Unerreichbar" und „das Werkzeug ist gescheitert" können vorübergehen —
    // nur dann darf ein erneutes Laden angeboten werden. Bei einer fehlenden
    // Freigabe wäre es ein falsches Versprechen.
    return ausfall.may_retry
        ? 'Die Kette ist gerade nicht abrufbar — ein erneutes Laden kann helfen.'
        : 'Die Kette konnte nicht abgefragt werden.';
}

export function AkteKette({ eigenschaft, daten, benennung = {} }: Props) {
    const wort = (art: string): string => benennung[art] ?? VORGABE[art] ?? art;

    return (
        <Deferred
            data={eigenschaft}
            fallback={<div className="h-5 w-48 animate-pulse rounded bg-muted" />}
        >
            <AkteKetteInhalt daten={daten} wort={wort} />
        </Deferred>
    );
}

function AkteKetteInhalt({
    daten,
    wort,
}: {
    daten?: AkteKetteDaten | null;
    wort: (art: string) => string;
}) {
    if (!daten) {
        return null;
    }

    // Zuerst die Absage: Sie ist das einzige, was man NICHT sieht, wenn es
    // fehlt — und sie darf nicht als „nichts dazu" durchgehen.
    if (daten.ausfall) {
        return (
            <p className="text-sm text-muted-foreground">
                {satzZurAbsage(daten.ausfall)}
                {daten.ausfall.message && (
                    <span className="ml-1 opacity-70">({daten.ausfall.message})</span>
                )}
            </p>
        );
    }

    const verweise = daten.verweise ?? [];

    // Keine Akte oder eine ohne weitere Stufen: Der Vorgang steht für sich.
    // Das ist der Normalfall beim Bestandskunden und verdient keine Zeile —
    // eine Meldung „gehört zu nichts" wäre Lärm auf jeder zweiten Seite.
    if (!daten.gefunden || verweise.length === 0) {
        return null;
    }

    return (
        <p className="text-sm text-muted-foreground">
            <span className="mr-1">Aus</span>
            {verweise.map((v, i) => (
                <span key={`${v.system}-${v.art}-${v.kennung}`}>
                    {i > 0 && <span className="mx-1 opacity-50">·</span>}
                    {v.adresse ? (
                        <a
                            href={v.adresse}
                            className="underline underline-offset-2 hover:text-foreground"
                        >
                            {wort(v.art)} {v.kennung}
                        </a>
                    ) : (
                        // Ohne Sprungziel, aber sichtbar: Der Verweis ist
                        // weiterhin eine richtige Aussage über die Kette.
                        <span title="Dieses System hat noch keine Adresse hinterlegt.">
                            {wort(v.art)} {v.kennung}
                        </span>
                    )}
                </span>
            ))}
            {daten.kennung && (
                <span className="ml-2 opacity-50" title="Kennung der Akte">
                    {daten.kennung}
                </span>
            )}
        </p>
    );
}
