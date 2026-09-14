/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { EventForm } from '../src/EventForm';
import { emptyDraft, emptyRecurrence, type EventDraft, type EventKind } from '../src/types';

afterEach(cleanup);

const kind = (over: Partial<EventKind> = {}): EventKind => ({ key: 'private', label: 'Privat', ...over });

const draft = (over: Partial<EventDraft> = {}): EventDraft => ({
    ...emptyDraft('private'),
    title: 'Zahnarzt',
    date: '2026-09-14',
    ...over,
});

function show(props: Partial<Parameters<typeof EventForm>[0]> = {}) {
    return render(
        <EventForm
            kinds={[kind()]}
            value={draft()}
            onChange={() => {}}
            onSubmit={() => {}}
            {...props}
        />,
    );
}

describe('EventForm', () => {
    it('laesst weg, was die Art verbietet', () => {
        show({ kinds: [kind({ forbids: ['meeting_url', 'recurrence_rules'] })] });

        expect(screen.queryByLabelText('Meeting-Link')).toBeNull();
        expect(screen.queryByText('Wiederholt sich')).toBeNull();
        expect(screen.queryByLabelText('Ort')).not.toBeNull();
    });

    it('stellt keine Frage, wenn es nur eine Antwort gibt', () => {
        show();
        expect(screen.queryByLabelText('Terminart')).toBeNull();

        cleanup();
        show({ kinds: [kind(), kind({ key: 'business', label: 'Geschaeftlich' })] });
        expect(screen.queryByLabelText('Terminart')).not.toBeNull();
    });

    it('haengt die Beanstandung unter das Feld, das sie meint', () => {
        show({
            value: draft({ recurrence: emptyRecurrence() }),
            fieldErrors: { location: 'Ort fehlt', 'recurrence.until': 'Enddatum fehlt' },
        });

        // Nicht nur „steht irgendwo": Der Fehler muss beim richtigen Feld haengen.
        const ort = screen.getByLabelText('Ort').parentElement;
        expect(ort?.textContent).toContain('Ort fehlt');

        const bis = screen.getByLabelText('Wiederholen bis *').parentElement;
        expect(bis?.textContent).toContain('Enddatum fehlt');
    });

    it('laesst nicht absenden, solange die Art noch etwas verlangt', () => {
        const onSubmit = vi.fn();
        show({ kinds: [kind({ requires: ['location'] })], onSubmit });

        const knopf = screen.getByRole('button', { name: 'Speichern' });
        expect(knopf).toHaveProperty('disabled', true);

        fireEvent.click(knopf);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('zeigt ein Enddatum nur, wo der Kalender Mehrtagestermine kennt', () => {
        show();
        expect(screen.queryByLabelText('Enddatum')).toBeNull();

        cleanup();
        show({ multiDay: true });
        // Und nicht vor dem Starttag: Ein Ende vor dem Anfang ist kein Termin.
        expect(screen.getByLabelText('Enddatum')).toHaveProperty('min', '2026-09-14');
    });

    it('gibt bei einer gepflegten Liste eine Auswahl statt eines Textfelds', () => {
        const kategorien = [
            { id: 7, label: 'Kundentermin' },
            { id: 8, label: 'Intern' },
        ];
        const onChange = vi.fn();

        show({
            kinds: [kind({ usesCategories: true })],
            categories: kategorien,
            categoryMode: 'closed',
            onChange,
        });

        const auswahl = screen.getByLabelText('Kategorie');
        expect(auswahl.tagName).toBe('SELECT');

        fireEvent.change(auswahl, { target: { value: '8' } });
        expect(onChange.mock.calls[0]?.[0].categoryId).toBe('8');
    });

    it('gibt bei einer persoenlichen Liste ein Textfeld mit Vorschlaegen', () => {
        show({
            kinds: [kind({ usesCategories: true })],
            categories: [{ label: 'Sport' }],
            categoryMode: 'personal',
        });

        // Der Unterschied ist nicht Geschmack: Bei einer gepflegten Liste liesse
        // ein Textfeld etwas eintippen, was der Server hinterher ablehnt.
        expect(screen.getByLabelText('Kategorie').tagName).toBe('INPUT');
    });

    it('haengt eine Erklaerung unter das Kategoriefeld, wenn das Produkt eine hat', () => {
        const kategorien = [{ id: 7, label: 'Kundentermin' }];

        show({
            kinds: [kind({ usesCategories: true })],
            categories: kategorien,
            categoryMode: 'closed',
            labels: { categoryHint: 'Pflicht ohne Projekt.' },
        });

        // Unter dem Feld, nicht irgendwo: Ein Hinweis, der drei Felder weiter
        // steht, liest sich wie die Erklaerung des falschen Feldes.
        const kategorie = screen.getByLabelText('Kategorie').parentElement;
        expect(kategorie?.textContent).toContain('Pflicht ohne Projekt.');
    });

    it('laesst die Erklaerung weg, wenn es keine gibt', () => {
        show({
            kinds: [kind({ usesCategories: true })],
            categories: [{ id: 7, label: 'Kundentermin' }],
            categoryMode: 'closed',
        });

        expect(screen.getByLabelText('Kategorie').parentElement?.querySelector('p')).toBeNull();
    });

    it('laesst ein Produkt sein eigenes Zeit-Bedienelement einsetzen', () => {
        const onChange = vi.fn();

        show({
            renderTime: ({ id, value: wert, onChange: setzen }) => (
                <select id={id} value={wert} onChange={(e) => setzen(e.target.value)}>
                    <option value="">—</option>
                    <option value="09:15">09:15</option>
                </select>
            ),
            value: draft({ start: '', end: '' }),
            onChange,
        });

        // Kein natives Zeitfeld mehr, und der eigene Baustein schreibt in denselben Entwurf.
        const von = screen.getByLabelText('Von');
        expect(von.tagName).toBe('SELECT');

        fireEvent.change(von, { target: { value: '09:15' } });
        expect(onChange.mock.calls[0]?.[0].start).toBe('09:15');
    });

    it('legt beim Einschalten der Serie Wochentag und Ende schon hin', () => {
        const onChange = vi.fn();
        show({ onChange });

        fireEvent.click(screen.getByLabelText('Wiederholt sich'));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange.mock.calls[0]?.[0].recurrence).toMatchObject({
            frequency: 'weekly',
            byDay: ['MO'],
            until: '2027-09-14',
        });
    });
});
