// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { FremdeVorgaenge, type FremdeVorgaengeDaten } from '../src/inertia/FremdeVorgaenge';

/**
 * Ein Hinweis des Produkts unter der Liste (peppermint-manager #10342).
 *
 * Am Auftrag zeigt der Manager die Aufgaben eines Projekts nur, wenn man das
 * Projekt sehen darf. Fehlt das Recht, soll die Karte das SAGEN — sonst sieht
 * eine kuerzere Liste genauso vollstaendig aus wie eine lange. Den Satz kennt
 * nur das Produkt; die Darstellung gehoert hierher, damit sie in allen
 * Produkten gleich ist.
 */
vi.mock('@inertiajs/react', () => ({
    Deferred: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

afterEach(cleanup);

const HINWEIS = 'Zu diesem Auftrag gehört ein Projekt, dessen Aufgaben du nicht siehst.';

function daten(tasks: FremdeVorgaengeDaten['tasks']): FremdeVorgaengeDaten {
    return {
        tasks,
        ok: ['manager'],
        failures: {},
        complete: true,
        sources: { manager: { key: 'manager', label: 'Manager' } },
    };
}

const AUFGABE = { id: 'manager:1', source: 'manager', foreign_id: 1, title: 'Druckdaten prüfen' } as FremdeVorgaengeDaten['tasks'][number];

describe('der Hinweis des Produkts', () => {
    it('steht unter einer gefuellten Liste', () => {
        render(<FremdeVorgaenge eigenschaft="fremdeVorgaenge" daten={daten([AUFGABE])} hinweis={HINWEIS} />);

        expect(screen.getByText('Druckdaten prüfen')).toBeTruthy();
        expect(screen.getByText(HINWEIS)).toBeTruthy();
    });

    it('steht auch neben dem Leer-Satz', () => {
        render(<FremdeVorgaenge eigenschaft="fremdeVorgaenge" daten={daten([])} leer="Nichts dazu." hinweis={HINWEIS} />);

        expect(screen.getByText('Nichts dazu.')).toBeTruthy();
        expect(screen.getByText(HINWEIS)).toBeTruthy();
    });

    it('fehlt ohne Prop ganz', () => {
        const { container } = render(<FremdeVorgaenge eigenschaft="fremdeVorgaenge" daten={daten([AUFGABE])} />);

        expect(container.querySelector('[data-slot="fremde-vorgaenge-hinweis"]')).toBeNull();
    });
});
