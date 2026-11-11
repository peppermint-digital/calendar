/**
 * @vitest-environment jsdom
 */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import {
    PlannableList,
    PLANNABLE_ATTRIBUTE,
    PLANNABLE_SOURCE_ATTRIBUTE,
    type PlannableEntry,
} from '../src/PlannableList';

const zerstoeren = vi.fn();
const gebaut: Array<{ element: HTMLElement; options: Record<string, unknown> }> = [];

vi.mock('@fullcalendar/interaction', () => ({
    Draggable: class {
        constructor(element: HTMLElement, options: Record<string, unknown>) {
            gebaut.push({ element, options });
        }

        destroy() {
            zerstoeren();
        }
    },
}));

afterEach(() => {
    cleanup();
    gebaut.length = 0;
    zerstoeren.mockClear();
});

const aufgaben: PlannableEntry[] = [
    { id: 7, title: 'Angebot schreiben', minutes: 90 },
    { id: 8, title: 'Serverwartung', recurring: true, subjectType: 'habit' },
];

const karte = (item: PlannableEntry) => <span>{item.title}</span>;

describe('PlannableList', () => {
    it('haengt an jede Karte, was der Ablegevorgang liest', () => {
        const { container } = render(<PlannableList items={aufgaben} renderItem={karte} />);

        const erste = container.querySelector(`[${PLANNABLE_ATTRIBUTE}="7"]`);

        expect(erste).not.toBeNull();
        expect(erste?.getAttribute('data-duration-minutes')).toBe('90');
        expect(erste?.getAttribute('data-plannable-title')).toBe('Angebot schreiben');
        expect(screen.getByText('Serverwartung')).toBeTruthy();
    });

    it('nimmt eine Stunde an, wo keine Dauer steht', () => {
        const { container } = render(<PlannableList items={[{ id: 1, title: 'Ohne Dauer' }]} renderItem={karte} />);

        expect(container.querySelector(`[${PLANNABLE_ATTRIBUTE}="1"]`)?.getAttribute('data-duration-minutes')).toBe('60');
    });

    it('baut den Draggable nach einer Aenderung der Liste NEU', () => {
        // Der Fallstrick, den Manager und AI Brain unabhaengig voneinander
        // entdecken mussten: Ein einmal gebauter Draggable kennt die spaeter
        // gezeichneten Karten nicht. Sie sehen ziehbar aus und sind es nicht.
        const { rerender } = render(<PlannableList items={aufgaben} renderItem={karte} />);

        expect(gebaut).toHaveLength(1);

        rerender(<PlannableList items={[{ id: 9, title: 'Neu dazugekommen' }]} renderItem={karte} />);

        expect(gebaut).toHaveLength(2);
        expect(zerstoeren).toHaveBeenCalledTimes(1);
    });

    it('liefert beim Ablegen Titel und Dauer des gezogenen Gegenstands', () => {
        const { container } = render(<PlannableList items={aufgaben} renderItem={karte} />);

        const karteImDom = container.querySelector(`[${PLANNABLE_ATTRIBUTE}="7"]`) as HTMLElement;
        const daten = (gebaut[0].options.eventData as (el: HTMLElement) => Record<string, unknown>)(karteImDom);

        expect(daten.title).toBe('Angebot schreiben');
        expect(daten.duration).toEqual({ minutes: 90 });
    });

    it('reicht durch, was die Anwendung zusaetzlich am Termin braucht', () => {
        const { container } = render(
            <PlannableList items={aufgaben} renderItem={karte} eventData={(item) => ({ kind: 'verwaltung_ticket', ticketId: item.id })} />,
        );

        const karteImDom = container.querySelector(`[${PLANNABLE_ATTRIBUTE}="7"]`) as HTMLElement;
        const daten = (gebaut[0].options.eventData as (el: HTMLElement) => Record<string, unknown>)(karteImDom);

        expect(daten.kind).toBe('verwaltung_ticket');
        expect(daten.ticketId).toBe(7);
    });

    it('zeigt den Leertext, wenn nichts zu planen ist', () => {
        render(<PlannableList items={[]} renderItem={karte} empty={<p>Alles verplant</p>} />);

        expect(screen.getByText('Alles verplant')).toBeTruthy();
    });
});

/**
 * Das System am Posten (#9798).
 *
 * Dieselbe Art von Grenze, die `subjectType` schon zieht, eine Ebene hoeher:
 * „Aufgabe 7" im Manager und „Aufgabe 7" in der Verwaltung sind zwei Posten.
 * Solange eine Liste nur ein System zeigt, aendert die Angabe nichts — und
 * genau das ist die Zusicherung fuer CRM und Verwaltung, die heute schon aus
 * dieser Liste ins Raster ziehen.
 */
describe('das System eines Postens', () => {
    const zweiSysteme: PlannableEntry[] = [
        { id: 7, title: 'Angebot schreiben', minutes: 90, sourceKey: 'manager' },
        { id: 7, title: 'Ticket beantworten', minutes: 30, sourceKey: 'verwaltung' },
    ];

    it('steht als eigener Griff an der Karte', () => {
        const { container } = render(<PlannableList items={zweiSysteme} renderItem={karte} />);

        // Neben der Kennung und nicht in ihr: Eine zusammengesetzte Kennung
        // („manager:7") muesste beim Ablegen wieder zerlegt werden, und wer sie
        // weiterreicht, schickt dem Zielsystem eine Kennung, die es nicht kennt.
        const karten = Array.from(container.querySelectorAll(`[${PLANNABLE_ATTRIBUTE}]`));

        expect(karten.map((k) => k.getAttribute(PLANNABLE_ATTRIBUTE))).toEqual(['7', '7']);
        expect(karten.map((k) => k.getAttribute(PLANNABLE_SOURCE_ATTRIBUTE))).toEqual([
            'manager',
            'verwaltung',
        ]);
    });

    it('zeigt zwei Posten mit derselben Kennung nebeneinander', () => {
        render(<PlannableList items={zweiSysteme} renderItem={karte} />);

        // Ohne das System im React-Schluessel waere der zweite Posten der
        // erste gewesen — und einer von beiden unsichtbar.
        expect(screen.getByText('Angebot schreiben')).toBeTruthy();
        expect(screen.getByText('Ticket beantworten')).toBeTruthy();
    });

    it('legt beim Ablegen den Posten DES Systems ab, nicht den mit derselben Kennung', () => {
        const { container } = render(<PlannableList items={zweiSysteme} renderItem={karte} />);

        const ausDerVerwaltung = container.querySelector(
            `[${PLANNABLE_SOURCE_ATTRIBUTE}="verwaltung"]`,
        ) as HTMLElement;
        const daten = (gebaut[0].options.eventData as (el: HTMLElement) => Record<string, unknown>)(
            ausDerVerwaltung,
        );

        // Vorher traf die Suche den ERSTEN Posten mit der Kennung — die
        // abgelegte Karte haette Titel und Dauer der falschen Aufgabe getragen.
        expect(daten.title).toBe('Ticket beantworten');
        expect(daten.duration).toEqual({ minutes: 30 });
    });

    /**
     * Die Zusicherung fuer die Produkte, die heute aufrufen: Ohne Systemangabe
     * bleibt alles, wie es war. Keine Zeile Aenderung dort.
     */
    it('aendert nichts an einer Liste ohne Systemangabe', () => {
        const { container } = render(<PlannableList items={aufgaben} renderItem={karte} />);

        const erste = container.querySelector(`[${PLANNABLE_ATTRIBUTE}="7"]`) as HTMLElement;

        expect(erste.getAttribute(PLANNABLE_SOURCE_ATTRIBUTE)).toBe('');

        const daten = (gebaut[0].options.eventData as (el: HTMLElement) => Record<string, unknown>)(
            erste,
        );

        expect(daten.title).toBe('Angebot schreiben');
        expect(daten.duration).toEqual({ minutes: 90 });
    });
});
