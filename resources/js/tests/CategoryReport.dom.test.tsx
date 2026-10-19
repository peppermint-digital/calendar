/**
 * @vitest-environment jsdom
 */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { CategoryReport, formatDuration, type CategoryReportData } from '../src/CategoryReport';

afterEach(cleanup);

const zeile = (label: string, minutes: number, count = 1) => ({
    label,
    minutes,
    count,
    hours: Math.round((minutes / 60) * 100) / 100,
});

const bericht = (over: Partial<CategoryReportData> = {}): CategoryReportData => ({
    categories: [zeile('Kundentermin', 120, 2)],
    uncategorized: { label: null, minutes: 0, count: 0, hours: 0 },
    total: zeile('total', 120, 2),
    ...over,
});

describe('formatDuration', () => {
    it('spricht Stunden und Minuten aus, wie man sie sagt', () => {
        expect(formatDuration(45)).toBe('45 min');
        expect(formatDuration(60)).toBe('1 h');
        expect(formatDuration(135)).toBe('2:15 h');
    });
});

describe('CategoryReport', () => {
    it('zeigt Dauer und Anzahl je Kategorie', () => {
        render(<CategoryReport report={bericht()} />);

        expect(screen.getByText('Kundentermin')).toBeTruthy();
        expect(screen.getByText('2 h · 2×')).toBeTruthy();
    });

    it('sagt es, wenn kein Termin eine Kategorie traegt', () => {
        render(<CategoryReport report={bericht({ categories: [] })} />);

        expect(screen.getByText(/trägt kein Termin eine Kategorie/)).toBeTruthy();
    });

    it('zeigt „ohne Kategorie" nur, wenn es solche gibt', () => {
        const { unmount } = render(<CategoryReport report={bericht()} />);
        expect(screen.queryByText('Ohne Kategorie')).toBeNull();
        unmount();

        render(<CategoryReport report={bericht({ uncategorized: zeile('', 30, 1) })} />);
        expect(screen.getByText('Ohne Kategorie')).toBeTruthy();
    });

    /**
     * Der Maßstab ist die LÄNGSTE Kategorie, nicht die Gesamtzeit: Ein Termin
     * mit zwei Kategorien zählt bei beiden voll, die Summe kann also größer
     * sein als die Gesamtzeit — ein Balken gegen die Gesamtzeit wäre dann
     * länger als die Spur.
     */
    it('nimmt die laengste Kategorie als Massstab', () => {
        render(
            <CategoryReport
                report={bericht({ categories: [zeile('Viel', 300), zeile('Wenig', 150)] })}
            />,
        );

        const balken = document.querySelectorAll<HTMLElement>('.bg-primary');

        expect(balken[0].style.width).toBe('100%');
        expect(balken[1].style.width).toBe('50%');
    });

    /** Sonst sucht man einen Rechenfehler, wo keiner ist. */
    it('erklaert eine Summe, die groesser ist als die Gesamtzeit', () => {
        render(
            <CategoryReport
                report={bericht({
                    categories: [zeile('A', 120), zeile('B', 120)],
                    total: zeile('total', 120, 1),
                })}
            />,
        );

        expect(screen.getByText(/zählt bei jeder voll/)).toBeTruthy();
    });

    it('erklaert nichts, wenn die Summe aufgeht', () => {
        render(<CategoryReport report={bericht()} />);

        expect(screen.queryByText(/zählt bei jeder voll/)).toBeNull();
    });
});

describe('CategoryReport — die Aufschlüsselung ohne Kategorie', () => {
    const mitGruppen = (): CategoryReportData => ({
        categories: [zeile('Kundentermin', 60)],
        groups: [zeile('Projekt Nord', 120, 3), zeile('Projekt Süd', 30)],
        uncategorized: zeile('', 150, 4),
        total: zeile('total', 210, 5),
    });

    it('zeigt sie mit eigener Überschrift', () => {
        render(<CategoryReport report={mitGruppen()} />);

        expect(screen.getByText('Davon ohne Kategorie, nach Herkunft')).toBeTruthy();
        expect(screen.getByText('Projekt Nord')).toBeTruthy();
        expect(screen.getByText('2 h · 3×')).toBeTruthy();
    });

    /**
     * Der Hinweis ist nicht Deko: Gewählte und abgeleitete Zahlen, die gleich
     * aussehen, sind der Anfang eines lügenden Berichts.
     */
    it('sagt, dass sie abgeleitet ist', () => {
        render(<CategoryReport report={mitGruppen()} />);

        expect(screen.getByText(/Abgeleitet aus dem Vorgang/)).toBeTruthy();
    });

    it('zeigt nichts davon, wenn es keine Aufschlüsselung gibt', () => {
        render(<CategoryReport report={{ ...mitGruppen(), groups: [] }} />);

        expect(screen.queryByText('Davon ohne Kategorie, nach Herkunft')).toBeNull();
    });

    /** Ältere Produkte liefern das Feld gar nicht — das darf nicht krachen. */
    it('kommt ohne das Feld aus', () => {
        const { groups: _weg, ...ohne } = mitGruppen();

        render(<CategoryReport report={ohne as CategoryReportData} />);

        expect(screen.getByText('Kundentermin')).toBeTruthy();
    });
});
