/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { EventDetail, type EventDetailData } from '../src/EventDetail';

afterEach(cleanup);

const TERMIN: EventDetailData = {
    title: 'Jahresgespräch',
    date: '2026-09-08',
    start: '09:00',
    end: '10:30',
    location: 'Büro Hamburg',
    description: 'Erste Zeile\nZweite Zeile',
    kindLabel: 'Geschäftlich',
    colour: '#2563eb',
};

describe('der Zeitraum', () => {
    it('nennt Tag und Uhrzeit', () => {
        render(<EventDetail event={TERMIN} />);

        expect(screen.getByText(/Di\., 08\. September 2026, 09:00 – 10:30/)).toBeTruthy();
    });

    it('sagt bei ganztaegig keine Uhrzeit', () => {
        render(<EventDetail event={{ ...TERMIN, allDay: true, start: null, end: null }} />);

        expect(screen.getByText(/ganztägig/)).toBeTruthy();
    });

    /** Ohne `allDay`, aber ohne Uhrzeiten: auch das ist ganztägig. */
    it('erkennt ganztaegig auch an fehlenden Uhrzeiten', () => {
        render(<EventDetail event={{ ...TERMIN, start: null, end: null }} />);

        expect(screen.getByText(/ganztägig/)).toBeTruthy();
    });

    /**
     * Ein dreitägiger Termin sah im Manager aus wie einer von einem Tag —
     * „ganztägig" ohne zweiten Tag ist eine Lüge über die Länge.
     */
    it('nennt bei mehrtaegig den zweiten Tag', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, endDate: '2026-09-10', allDay: true, start: null, end: null }}
            />,
        );

        expect(screen.getByText(/08\. September 2026 – .*10\. September 2026/)).toBeTruthy();
    });

    it('nennt bei mehrtaegig mit Uhrzeit beide Seiten', () => {
        render(<EventDetail event={{ ...TERMIN, endDate: '2026-09-10' }} />);

        expect(screen.getByText(/09:00 – .*10\. September 2026, 10:30/)).toBeTruthy();
    });

    it('kommt ohne Endzeit aus', () => {
        render(<EventDetail event={{ ...TERMIN, end: null }} />);

        expect(screen.getByText(/2026, 09:00$/)).toBeTruthy();
    });

    /** Das Produkt darf die Beschriftung übernehmen. */
    it('laesst das Produkt den Tag beschriften', () => {
        render(<EventDetail event={TERMIN} formatDate={() => 'heute'} />);

        expect(screen.getByText(/heute, 09:00 – 10:30/)).toBeTruthy();
    });
});

describe('die Felder', () => {
    it('zeigt Titel, Art, Ort und Notiz', () => {
        render(<EventDetail event={TERMIN} />);

        expect(screen.getByText('Jahresgespräch')).toBeTruthy();
        expect(screen.getByText('Geschäftlich')).toBeTruthy();
        expect(screen.getByText('Büro Hamburg')).toBeTruthy();
        expect(screen.getByText(/Erste Zeile/)).toBeTruthy();
    });

    /**
     * Ein Etikett ohne Wert liest sich wie ein fehlender Wert, und beim dritten
     * davon hört man auf, die Liste zu lesen.
     */
    it('laesst leere Felder ganz weg', () => {
        render(<EventDetail event={{ ...TERMIN, location: null, description: '' }} />);

        expect(screen.queryByText('Wo')).toBeNull();
        expect(screen.queryByText('Notiz')).toBeNull();
    });

    it('zeigt Teilnehmer mit ihrer Antwort', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, attendees: [{ name: 'Anna', status: 'zugesagt' }, { name: 'Bert' }] }}
            />,
        );

        expect(screen.getByText('Anna')).toBeTruthy();
        expect(screen.getByText('zugesagt')).toBeTruthy();
        expect(screen.getByText('Bert')).toBeTruthy();
    });

    it('zeigt Kategorien', () => {
        render(<EventDetail event={{ ...TERMIN, categories: ['Kundentermin', 'Abrechenbar'] }} />);

        expect(screen.getByText('Kundentermin')).toBeTruthy();
        expect(screen.getByText('Abrechenbar')).toBeTruthy();
    });

    it('sagt es, wenn der Termin zu einer Serie gehoert', () => {
        render(<EventDetail event={{ ...TERMIN, recurring: true }} />);

        expect(screen.getByText('Teil einer Serie')).toBeTruthy();
    });

    it('zeigt die Farbmarke nur, wo eine Farbe bekannt ist', () => {
        render(<EventDetail event={{ ...TERMIN, colour: null }} />);

        expect(document.querySelector('[data-slot="event-detail-dot"]')).toBeNull();
    });
});

/**
 * Die Ansicht kennt keine Rechte. Ob jemand bearbeiten darf, weiss nur das
 * Produkt (privat, Teilnehmer, Projektleitung, Verwalter) — deshalb kommen die
 * Bedienelemente herein und nicht aus einem Schalter hier.
 */
describe('die produkteigenen Teile', () => {
    it('nimmt eigene Zeilen auf', () => {
        render(
            <EventDetail
                event={TERMIN}
                extra={
                    <div>
                        <dt>Projekt</dt>
                        <dd>Website-Umbau</dd>
                    </div>
                }
            />,
        );

        expect(screen.getByText('Website-Umbau')).toBeTruthy();
    });

    it('nimmt eigene Bedienelemente auf', () => {
        render(<EventDetail event={TERMIN} actions={<button type="button">Bearbeiten</button>} />);

        expect(screen.getByRole('button', { name: 'Bearbeiten' })).toBeTruthy();
    });

    /** Ohne Bedienelemente auch kein Trennstrich — sonst steht er allein da. */
    it('zeichnet ohne Bedienelemente keine Leiste', () => {
        const { container } = render(<EventDetail event={TERMIN} />);

        expect(container.querySelector('.border-t')).toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Kategorien wählen (#7846)
|--------------------------------------------------------------------------
|
| Gemessen im Manager am 19.10.2026: 483 von 1211 Terminen sind Zeitblöcke an
| einer Aufgabe, und keiner konnte eine Kategorie tragen — damit fiel dieser
| Teil in der Auswertung komplett unter „ohne Kategorie". Ein Block entsteht
| durch Ziehen, es gibt also keinen Dialog, in dem man sie mitwählen könnte.
| Deshalb hier.
*/

const kategorien = [
    { slug: 'kundentermin', label: 'Kundentermin' },
    { slug: 'vertrieb', label: 'Vertrieb' },
];

describe('EventDetail — Kategorien wählen', () => {
    it('bleibt eine Anzeige, wenn nichts zum Ändern übergeben wird', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, categories: ['Kundentermin'], categorySlugs: ['kundentermin'] }}
            />,
        );

        expect(screen.getByText('Kundentermin')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Kundentermin' })).toBeNull();
    });

    /** Der ganze Punkt: wählbar auch dann, wenn der Termin noch keine trägt. */
    it('bietet die Wahl auch bei einem Termin ohne Kategorie', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, categories: [], categorySlugs: [] }}
                availableCategories={kategorien}
                onCategoriesChange={() => {}}
            />,
        );

        expect(screen.getByRole('button', { name: 'Kundentermin' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Vertrieb' })).toBeTruthy();
    });

    it('zeigt, was gewählt ist', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, categorySlugs: ['vertrieb'] }}
                availableCategories={kategorien}
                onCategoriesChange={() => {}}
            />,
        );

        expect(screen.getByRole('button', { name: 'Vertrieb' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('button', { name: 'Kundentermin' }).getAttribute('aria-pressed')).toBe('false');
    });

    /** Die VOLLSTÄNDIGE neue Liste, nicht die Änderung — sonst muss der Empfänger rechnen. */
    it('meldet die neue vollstaendige Liste beim Hinzufuegen', () => {
        const gemeldet: string[][] = [];

        render(
            <EventDetail
                event={{ ...TERMIN, categorySlugs: ['kundentermin'] }}
                availableCategories={kategorien}
                onCategoriesChange={(slugs) => gemeldet.push(slugs)}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Vertrieb' }));

        expect(gemeldet).toEqual([['kundentermin', 'vertrieb']]);
    });

    it('meldet die neue vollstaendige Liste beim Abwaehlen', () => {
        const gemeldet: string[][] = [];

        render(
            <EventDetail
                event={{ ...TERMIN, categorySlugs: ['kundentermin', 'vertrieb'] }}
                availableCategories={kategorien}
                onCategoriesChange={(slugs) => gemeldet.push(slugs)}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Kundentermin' }));

        expect(gemeldet).toEqual([['vertrieb']]);
    });

    /** Ein leerer Auswahlbereich ist schlimmer als keiner. */
    it('zeigt nichts, wenn die Terminart keine Kategorien anbietet', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, categories: [], categorySlugs: [] }}
                availableCategories={[]}
                onCategoriesChange={() => {}}
            />,
        );

        expect(screen.queryByText('Kategorien')).toBeNull();
    });
});
