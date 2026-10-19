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

describe('Gesamtzeit (#8307)', () => {
    it('stellt sie gross voran, damit sie in allen vier Produkten gleich steht', () => {
        render(<CategoryReport report={bericht()} />);

        const block = screen.getByTestId('category-report-total');

        expect(block.textContent).toContain('Gesamtzeit');
        expect(block.textContent).toContain('2 h');
        expect(block.textContent).toContain('2 Termine');
    });

    it('sagt „Termin" im Singular', () => {
        render(<CategoryReport report={bericht({ total: zeile('total', 60, 1) })} />);

        expect(screen.getByTestId('category-report-total').textContent).toContain('1 Termin');
    });
});

describe('Nach Terminart (#8307)', () => {
    const mitArten = () =>
        bericht({
            kinds: [
                { ...zeile('Zeitblock', 300, 4), categories: [] },
                { ...zeile('Termin', 120, 2), categories: [zeile('Kundentermin', 120, 2)] },
            ],
        });

    it('zeigt jede Art mit Dauer und Anzahl', () => {
        render(<CategoryReport report={mitArten()} />);

        const abschnitt = screen.getByTestId('category-report-kinds');

        expect(abschnitt.textContent).toContain('Zeitblock');
        expect(abschnitt.textContent).toContain('5 h · 4×');
    });

    it('rueckt die Kategorien unter ihrer Art ein, statt sie als zweite Liste zu zeigen', () => {
        render(<CategoryReport report={mitArten()} />);

        // „Kundentermin" steht zweimal auf der Seite: einmal als Kategorie,
        // einmal unter seiner Art. Genau das ist gewollt — die beiden
        // Abschnitte zaehlen verschieden.
        expect(screen.getAllByText('Kundentermin').length).toBe(2);
    });

    it('laesst den Abschnitt weg, wenn das Produkt keine Arten liefert', () => {
        render(<CategoryReport report={bericht()} />);

        expect(screen.queryByTestId('category-report-kinds')).toBeNull();
    });

    it('laesst ihn auch bei leerer Liste weg, statt eine leere Ueberschrift zu zeigen', () => {
        render(<CategoryReport report={bericht({ kinds: [] })} />);

        expect(screen.queryByTestId('category-report-kinds')).toBeNull();
    });
});

describe('Nach Vorgang (#8307)', () => {
    it('zeigt jeden Vorgang mit Dauer und Anzahl', () => {
        render(<CategoryReport report={bericht({ subjects: [zeile('Projekt Nord', 180, 3)] })} />);

        const abschnitt = screen.getByTestId('category-report-subjects');

        expect(abschnitt.textContent).toContain('Projekt Nord');
        expect(abschnitt.textContent).toContain('3 h · 3×');
    });

    it('steht getrennt von der Herkunfts-Aufschluesselung, weil beide anders zaehlen', () => {
        render(
            <CategoryReport
                report={bericht({
                    subjects: [zeile('Projekt Nord', 180, 3)],
                    groups: [zeile('Projekt Nord', 60, 1)],
                })}
            />,
        );

        // Zwei Abschnitte, zwei Zahlen zum selben Etikett — genau das soll
        // die Seite zeigen: 3 h gingen in den Vorgang, davon 1 h ohne
        // Kategorie. Eine gemeinsame Tabelle waere eine falsche Summe.
        expect(screen.getByTestId('category-report-subjects').textContent).toContain('3 h');
        expect(screen.getByTestId('category-report-groups').textContent).toContain('1 h');
    });

    it('bleibt weg, wenn das Produkt keine Vorgaenge liefert', () => {
        render(<CategoryReport report={bericht()} />);

        expect(screen.queryByTestId('category-report-subjects')).toBeNull();
    });
});

describe('Sprungziel eines Vorgangs (#8307)', () => {
    it('macht einen Vorgang mit Ziel zum Link', () => {
        render(<CategoryReport report={bericht({ subjects: [{ ...zeile('Projekt Nord', 60), url: '/projects/7' }] })} />);

        expect(screen.getByRole('link', { name: 'Projekt Nord' }).getAttribute('href')).toBe('/projects/7');
    });

    it('laesst ihn ohne Ziel Text bleiben, statt auf nichts zu zeigen', () => {
        render(<CategoryReport report={bericht({ subjects: [zeile('Projekt Nord', 60)] })} />);

        expect(screen.queryByRole('link', { name: 'Projekt Nord' })).toBeNull();
        expect(screen.getByTestId('category-report-subjects').textContent).toContain('Projekt Nord');
    });

    it('behandelt ein leeres Ziel wie keines', () => {
        render(<CategoryReport report={bericht({ subjects: [{ ...zeile('Projekt Nord', 60), url: '' }] })} />);

        expect(screen.queryByRole('link', { name: 'Projekt Nord' })).toBeNull();
    });
});
