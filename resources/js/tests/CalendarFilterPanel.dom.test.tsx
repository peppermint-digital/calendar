/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CalendarFilterPanel, type FilterGroup } from '../src/CalendarFilterPanel';

afterEach(cleanup);

function arten(selected: (string | number)[] = [], onChange = () => {}): FilterGroup {
    return {
        key: 'kinds',
        label: 'Terminarten ausblenden',
        mode: 'hide',
        selected,
        onChange,
        options: [
            { value: 'termin', label: 'Termin', colour: '#3b82f6' },
            { value: 'aufgabe', label: 'Aufgabe' },
        ],
    };
}

function projekte(selected: (string | number)[] = [], onChange = () => {}): FilterGroup {
    return {
        key: 'projects',
        label: 'Nur diese Projekte',
        mode: 'only',
        selected,
        onChange,
        options: [
            { value: 7, label: 'Website-Umbau' },
            { value: 9, label: 'Messeauftritt' },
        ],
    };
}

describe('das Aufklappen', () => {
    it('zeigt die Gruppen erst auf Druck', () => {
        render(<CalendarFilterPanel groups={[arten()]} />);

        expect(screen.queryByText('Terminarten ausblenden')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));

        expect(screen.getByText('Terminarten ausblenden')).toBeTruthy();
    });

    /**
     * Ohne die Ausnahme für den Knopf würde ein Klick darauf erst schliessen
     * (der Zuhörer) und dann wieder öffnen (der Knopf) — die Tafel bliebe offen
     * und der Knopf wirkte kaputt.
     */
    it('schliesst bei einem Klick daneben', () => {
        render(<CalendarFilterPanel groups={[arten()]} />);

        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));
        expect(screen.getByText('Terminarten ausblenden')).toBeTruthy();

        fireEvent.mouseDown(document.body);

        expect(screen.queryByText('Terminarten ausblenden')).toBeNull();
    });

    /** Eine Tafel ohne Tastaturweg ist eine Falle. */
    it('schliesst mit Escape', () => {
        render(<CalendarFilterPanel groups={[arten()]} />);

        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));
        fireEvent.keyDown(document, { key: 'Escape' });

        expect(screen.queryByText('Terminarten ausblenden')).toBeNull();
    });

    it('zeichnet nichts, wenn es nichts zu filtern gibt', () => {
        render(<CalendarFilterPanel />);

        expect(document.querySelector('[data-slot="calendar-filter-panel"]')).toBeNull();
    });
});

describe('die Haken', () => {
    it('schaltet einen Wert um', () => {
        const geaendert = vi.fn();

        render(<CalendarFilterPanel groups={[arten([], geaendert)]} />);
        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));
        fireEvent.click(screen.getByLabelText('Termin'));

        expect(geaendert).toHaveBeenCalledWith(['termin']);
    });

    it('nimmt einen gesetzten Wert wieder heraus', () => {
        const geaendert = vi.fn();

        render(<CalendarFilterPanel groups={[arten(['termin', 'aufgabe'], geaendert)]} />);
        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));
        fireEvent.click(screen.getByLabelText('Termin'));

        expect(geaendert).toHaveBeenCalledWith(['aufgabe']);
    });

    /**
     * Zahlen bleiben Zahlen. Eine Vereinheitlichung auf Zeichenketten wäre
     * bequemer und falsch — beim Zurückgeben käme `"7"` statt `7` heraus, und
     * ein `includes(7)` im Produkt schlägt dann still fehl.
     */
    it('gibt eine Zahl als Zahl zurueck', () => {
        const geaendert = vi.fn();

        render(<CalendarFilterPanel groups={[projekte([], geaendert)]} />);
        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));
        fireEvent.click(screen.getByLabelText('Website-Umbau'));

        expect(geaendert).toHaveBeenCalledWith([7]);
    });

    it('zeigt die Farbmarke, wo eine Farbe bekannt ist', () => {
        render(<CalendarFilterPanel groups={[arten()]} />);
        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));

        expect(document.querySelectorAll('[data-slot="calendar-filter-panel-body"] span.rounded-full')).toHaveLength(1);
    });
});

describe('der Zaehler und die Marken', () => {
    /** Die Zahl beantwortet „warum sehe ich weniger als sonst". */
    it('zaehlt gesetzte Haken und eingeschaltete Schalter', () => {
        render(
            <CalendarFilterPanel
                groups={[arten(['termin']), projekte([7])]}
                toggles={[
                    { key: 'a', label: 'Urlaub zeigen', value: true, onChange: () => {} },
                    { key: 'b', label: 'Aufgaben zeigen', value: false, onChange: () => {} },
                ]}
            />,
        );

        expect(screen.getByRole('button', { name: /^Filter\s*\d*$/ }).textContent).toContain('3');
    });

    it('zaehlt nichts, wenn nichts gesetzt ist', () => {
        render(<CalendarFilterPanel groups={[arten()]} />);

        expect(screen.getByRole('button', { name: /^Filter\s*\d*$/ }).textContent?.trim()).toBe('Filter');
    });

    /**
     * Die Marken stehen NEBEN dem Knopf: Sonst muss man aufklappen, um zu
     * sehen, warum der Kalender leerer ist als erwartet.
     */
    it('zeigt das Aktive als Marke, ohne aufzuklappen', () => {
        render(<CalendarFilterPanel groups={[projekte([7])]} />);

        expect(screen.getByText('Website-Umbau')).toBeTruthy();
        expect(document.querySelector('[data-slot="calendar-filter-panel-body"]')).toBeNull();
    });

    it('nimmt einen Filter ueber seine Marke heraus', () => {
        const geaendert = vi.fn();

        render(<CalendarFilterPanel groups={[projekte([7], geaendert)]} />);
        fireEvent.click(screen.getByRole('button', { name: 'Filter entfernen' }));

        expect(geaendert).toHaveBeenCalledWith([]);
    });

    /** Eine Marke für einen Wert, den die Liste nicht kennt, waere leer. */
    it('laesst eine Marke weg, deren Wert es nicht mehr gibt', () => {
        render(<CalendarFilterPanel groups={[projekte([999])]} />);

        expect(document.querySelectorAll('[data-slot="calendar-filter-badge"]')).toHaveLength(0);
    });
});

describe('die Schalter', () => {
    it('buendelt sie unter ihrem Abschnitt', () => {
        render(
            <CalendarFilterPanel
                toggles={[
                    { key: 'a', label: 'Nicht zugewiesene ausblenden', value: false, onChange: () => {}, section: 'Meine Einträge', hint: 'Filtert nach deiner Rolle' },
                    { key: 'b', label: 'Nicht Teilnehmer ausblenden', value: false, onChange: () => {}, section: 'Meine Einträge' },
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));

        expect(screen.getAllByText('Meine Einträge')).toHaveLength(1);
        expect(screen.getByText('Filtert nach deiner Rolle')).toBeTruthy();
    });

    it('schaltet einen Schalter um', () => {
        const geaendert = vi.fn();

        render(<CalendarFilterPanel toggles={[{ key: 'a', label: 'Urlaub zeigen', value: false, onChange: geaendert }]} />);
        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));
        fireEvent.click(screen.getByLabelText('Urlaub zeigen'));

        expect(geaendert).toHaveBeenCalledWith(true);
    });
});

describe('Zuruecksetzen und Eigenes', () => {
    it('setzt alles zurueck', () => {
        const zurueck = vi.fn();

        render(<CalendarFilterPanel groups={[arten(['termin'])]} onReset={zurueck} />);
        fireEvent.click(screen.getByRole('button', { name: 'Alle löschen' }));

        expect(zurueck).toHaveBeenCalled();
    });

    /** Ohne `onReset` verspricht kein Knopf etwas, das nicht geschieht. */
    it('zeigt keinen Zuruecksetzen-Knopf, wenn es nichts zurueckzusetzen gibt', () => {
        render(<CalendarFilterPanel groups={[arten(['termin'])]} />);

        expect(screen.queryByRole('button', { name: 'Alle löschen' })).toBeNull();
    });

    it('nimmt Eigenes am Ende der Tafel auf', () => {
        render(
            <CalendarFilterPanel groups={[arten()]}>
                <button type="button">Nur meine Mandanten</button>
            </CalendarFilterPanel>,
        );

        fireEvent.click(screen.getByRole('button', { name: /^Filter\s*\d*$/ }));

        expect(screen.getByRole('button', { name: 'Nur meine Mandanten' })).toBeTruthy();
    });
});

describe('Leere Gruppen (#8307)', () => {
    const gruppe = (over: Partial<FilterGroup> = {}): FilterGroup => ({
        key: 'projekte',
        label: 'Projekt',
        mode: 'only',
        options: [],
        selected: [],
        onChange: vi.fn(),
        ...over,
    });

    it('zeigt eine Gruppe ohne Auswahlmoeglichkeiten gar nicht erst', () => {
        // Sonst steht eine Ueberschrift mit Trennstrich darunter und sonst
        // nichts — ein Versprechen, das die Tafel nicht einloest.
        //
        // Ein Schalter ist dabei, damit die Tafel ueberhaupt erscheint: Mit
        // NUR einer leeren Gruppe bleibt sie ganz weg, und das prueft der
        // dritte Fall.
        render(
            <CalendarFilterPanel
                groups={[gruppe()]}
                toggles={[{ key: 'x', label: 'Irgendwas', value: false, onChange: vi.fn() }]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Filter/ }));

        expect(screen.queryByText('Projekt')).toBeNull();
        expect(screen.getByText('Irgendwas')).toBeTruthy();
    });

    it('zeigt die Gruppen daneben weiterhin', () => {
        render(
            <CalendarFilterPanel
                groups={[
                    gruppe(),
                    gruppe({ key: 'arten', label: 'Terminart', options: [{ value: 'business', label: 'Geschäftlich' }] }),
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Filter/ }));

        expect(screen.queryByText('Projekt')).toBeNull();
        expect(screen.getByText('Terminart')).toBeTruthy();
    });

    it('bleibt ganz weg, wenn ALLE Gruppen leer sind und es keine Schalter gibt', () => {
        render(<CalendarFilterPanel groups={[gruppe()]} />);

        expect(screen.queryByRole('button', { name: /Filter/ })).toBeNull();
    });
});
