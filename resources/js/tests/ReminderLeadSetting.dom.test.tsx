/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ReminderLeadSetting, type ReminderLeadState } from '../src/ReminderLeadSetting';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const STAND: ReminderLeadState = {
    lead_minutes: 15,
    eigen: false,
    vorgabe: 15,
    choices: [15, 30],
    enabled: true,
    person: true,
};

/**
 * Ein `fetch`, das eine Antwort liefert und die Anfragen mitschreibt.
 *
 * Mitschreiben und nicht nur zaehlen: Bei dieser Maske ist der KOERPER die
 * Aussage — „30" und „keine" und „Vorgabe" sind drei verschiedene Anfragen,
 * und genau darin liegt der Fehler, den man machen kann.
 */
function fetchAttrappe(antwort: ReminderLeadState) {
    const anfragen: Array<{ url: string; method?: string; body?: unknown }> = [];

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init?: RequestInit) => {
            anfragen.push({
                url,
                method: init?.method,
                body: init?.body === undefined ? undefined : JSON.parse(String(init.body)),
            });

            return { ok: true, json: async () => antwort } as unknown as Response;
        }),
    );

    return anfragen;
}

describe('die Anzeige', () => {
    beforeEach(() => {
        fetchAttrappe(STAND);
    });

    it('zeigt die Stufen, die DAS SYSTEM anbietet — und keine geratenen', () => {
        render(<ReminderLeadSetting initial={{ ...STAND, choices: [10, 20] }} />);

        expect(screen.getByRole('button', { name: '10 Min.' })).toBeTruthy();
        expect(screen.getByRole('button', { name: '20 Min.' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: '15 Min.' })).toBeNull();
    });

    it('sagt „Vorgabe", solange niemand entschieden hat — und nennt sie', () => {
        // Der ganze Witz: „steht auf 15" und „folgt der Vorgabe, die gerade 15
        // ist" sind zwei verschiedene Dinge.
        const { container } = render(<ReminderLeadSetting initial={STAND} />);

        // Ueber den gesamten Text und nicht ueber `getByText`: Der Hinweis
        // steht aus drei Textknoten zusammengesetzt da („Vorgabe", „: ",
        // „15 Min."), und „15 Min." gibt es ausserdem als Knopf. Eine Suche
        // nach dem Teilstueck faende zwei Treffer und waere zweideutig.
        expect(container.textContent).toContain('Vorgabe: 15 Min.');

        // Kein Knopf ist als eigene Wahl markiert, solange niemand
        // entschieden hat.
        expect(screen.queryByRole('button', { name: 'Vorgabe verwenden' })).toBeNull();
        expect(screen.getByRole('button', { name: '15 Min.' }).className).not.toContain('bg-primary');
    });

    it('bietet den Weg zurueck nur an, wenn es eine Entscheidung gibt', () => {
        render(<ReminderLeadSetting initial={{ ...STAND, eigen: true, lead_minutes: 30 }} />);

        expect(screen.getByRole('button', { name: 'Vorgabe verwenden' })).toBeTruthy();
    });

    it('sagt, wenn dieses System gar keine Termin-Erinnerungen schickt', () => {
        // Und blendet die Knoepfe NICHT aus: Wer hier etwas einstellt, soll es
        // tun koennen, bevor das System eingeschaltet wird.
        render(<ReminderLeadSetting initial={{ ...STAND, enabled: false }} />);

        expect(screen.getByText(/keine Termin-Erinnerungen/)).toBeTruthy();
        expect(screen.getByRole('button', { name: '30 Min.' })).toBeTruthy();
    });

    it('nennt das System, wenn die Maske mehrere zeigt', () => {
        render(<ReminderLeadSetting initial={STAND} systemName="Peppermint Manager" />);

        expect(screen.getByText(/Peppermint Manager/)).toBeTruthy();
    });
});

describe('das Speichern', () => {
    it('schickt die Minuten', async () => {
        const anfragen = fetchAttrappe({ ...STAND, eigen: true, lead_minutes: 30 });

        render(<ReminderLeadSetting initial={STAND} />);
        fireEvent.click(screen.getByRole('button', { name: '30 Min.' }));

        await waitFor(() => expect(anfragen).toHaveLength(1));
        expect(anfragen[0].method).toBe('PUT');
        expect(anfragen[0].body).toEqual({ lead_minutes: 30 });
    });

    it('schickt fuer „Keine" einen leeren Wert — nicht „Vorgabe"', () => {
        // Wer Erinnerungen abgestellt hat, soll von einer spaeteren Anhebung
        // der Vorgabe nicht wieder eingeschaltet werden. Das sind zwei
        // verschiedene Anfragen, und sie duerfen nicht zusammenfallen.
        const anfragen = fetchAttrappe({ ...STAND, eigen: true, lead_minutes: null });

        render(<ReminderLeadSetting initial={STAND} />);
        fireEvent.click(screen.getByRole('button', { name: 'Keine' }));

        return waitFor(() => {
            expect(anfragen[0].body).toEqual({ lead_minutes: null });
            expect(anfragen[0].body).not.toHaveProperty('reset');
        });
    });

    it('schickt fuer „Vorgabe verwenden" das Zuruecksetzen', () => {
        const anfragen = fetchAttrappe(STAND);

        render(<ReminderLeadSetting initial={{ ...STAND, eigen: true, lead_minutes: 30 }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Vorgabe verwenden' }));

        return waitFor(() => expect(anfragen[0].body).toEqual({ reset: true }));
    });

    it('uebernimmt den Stand aus der ANTWORT, nicht aus dem Klick', async () => {
        // Bei „Vorgabe verwenden" ist das der ganze Punkt: Was danach gilt,
        // weiss nur das System drueben. Hier antwortet es mit 5 — wer den
        // Klick optimistisch anzeigt, zeigt 15.
        fetchAttrappe({ ...STAND, eigen: false, lead_minutes: 5, vorgabe: 5 });

        render(<ReminderLeadSetting initial={{ ...STAND, eigen: true, lead_minutes: 30 }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Vorgabe verwenden' }));

        await waitFor(() => expect(screen.getByText(/5 Min\./)).toBeTruthy());
    });

    it('sagt es, wenn das Speichern nicht geklappt hat', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({}) }) as unknown as Response));

        render(<ReminderLeadSetting initial={STAND} />);
        fireEvent.click(screen.getByRole('button', { name: '30 Min.' }));

        await waitFor(() => expect(screen.getByText(/nicht gespeichert/)).toBeTruthy());
    });
});

describe('das Laden', () => {
    it('holt den Stand, wenn keine Vorbefuellung kommt', async () => {
        const anfragen = fetchAttrappe(STAND);

        render(<ReminderLeadSetting routes={{ show: '/x/lead', update: '/x/lead' }} />);

        await waitFor(() => expect(anfragen[0].url).toBe('/x/lead'));
        await waitFor(() => expect(screen.getByRole('button', { name: '15 Min.' })).toBeTruthy());
    });

    it('holt NICHT, wenn der Server schon vorbefuellt hat', () => {
        const anfragen = fetchAttrappe(STAND);

        render(<ReminderLeadSetting initial={STAND} />);

        expect(anfragen).toHaveLength(0);
    });

    it('sagt es, wenn das Laden nicht geklappt hat', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, json: async () => ({}) }) as unknown as Response));

        render(<ReminderLeadSetting />);

        await waitFor(() => expect(screen.getByText(/nicht geladen/)).toBeTruthy());
    });
});
