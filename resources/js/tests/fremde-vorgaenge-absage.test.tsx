// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { FremdeVorgaenge, type Ausfall, type FremdeVorgaengeDaten } from '../src/inertia/FremdeVorgaenge';

/**
 * Wie die Karte eine Absage liest (Bug #1551, peppermint-manager).
 *
 * Gesehen am 23.11.2026 in Produktion: „Verwaltung antwortet gerade nicht —
 * ein erneutes Laden kann helfen", auf jeder Projekt- und jeder Deal-Seite,
 * auch nach dem Neuladen. Die Verwaltung hatte aber geantwortet, und zwar
 * jedes Mal mit demselben Satz: Sie fuehrt Vorgaenge nur zu Auftraegen. Die
 * Karte machte aus `tool_failed` dasselbe wie aus `unreachable` und zeigte den
 * Satz der Gegenstelle nur beim Darueberfahren.
 */
vi.mock('@inertiajs/react', () => ({
    // Die verzoegerte Eigenschaft ist hier schon da: gezeigt wird der Inhalt.
    Deferred: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

afterEach(cleanup);

const ABSAGE_DER_VERWALTUNG =
    'Unbekannte Art „projekt“. Dieses System führt Vorgänge zu auftrag.';

function karte(ausfall: Ausfall) {
    const daten: FremdeVorgaengeDaten = {
        tasks: [],
        ok: [],
        failures: { [ausfall.source]: ausfall },
        complete: false,
        sources: { verwaltung: { key: 'verwaltung', label: 'Verwaltung' } },
    };

    return render(<FremdeVorgaenge eigenschaft="fremdeVorgaenge" daten={daten} />);
}

describe('eine Gegenstelle, die mit einem Fehler antwortet', () => {
    it('heisst nicht „antwortet gerade nicht"', () => {
        karte({ source: 'verwaltung', reason: 'tool_failed', message: ABSAGE_DER_VERWALTUNG, may_retry: true });

        expect(screen.queryByText(/antwortet gerade nicht/)).toBeNull();
        expect(screen.getByText(/mit einem Fehler beantwortet/)).toBeTruthy();
    });

    it('zeigt ihren eigenen Satz sichtbar, nicht nur beim Darueberfahren', () => {
        karte({ source: 'verwaltung', reason: 'tool_failed', message: ABSAGE_DER_VERWALTUNG, may_retry: true });

        expect(screen.getByText(ABSAGE_DER_VERWALTUNG, { exact: false })).toBeTruthy();
    });
});

describe('eine Gegenstelle, die diese Frage nicht beantwortet', () => {
    it('sagt das und nennt ihren Satz', () => {
        karte({ source: 'verwaltung', reason: 'not_offered', message: ABSAGE_DER_VERWALTUNG, may_retry: false });

        expect(screen.getByText(/beantwortet diese Frage nicht/)).toBeTruthy();
        expect(screen.getByText(ABSAGE_DER_VERWALTUNG, { exact: false })).toBeTruthy();
        expect(screen.queryByText(/erneutes Laden/)).toBeNull();
    });
});

describe('eine Gegenstelle, die nicht erreichbar ist', () => {
    it('heisst weiter „antwortet gerade nicht" und bietet das Neuladen an', () => {
        karte({ source: 'verwaltung', reason: 'unreachable', message: 'cURL error 28: Operation timed out', may_retry: true });

        expect(screen.getByText(/antwortet gerade nicht — ein erneutes Laden kann helfen/)).toBeTruthy();
    });

    it('zeigt die Transportmeldung nicht als Satz der Gegenstelle', () => {
        // Sie hat nichts gesagt — was da steht, ist die Leitung, nicht sie.
        karte({ source: 'verwaltung', reason: 'unreachable', message: 'cURL error 28: Operation timed out', may_retry: true });

        expect(screen.queryByText(/cURL error 28/)).toBeNull();
    });
});
