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
