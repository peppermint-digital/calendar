/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { EventComposer } from '../src/EventComposer';
import { emptyDraft, type EventKind } from '../src/types';
import type { RemoteSource } from '../src/useRemoteTarget';

/**
 * Die vollstaendige Terminmaske aus dem Paket (#7765).
 *
 * Sie liegt hier, weil bis zum 15.10.2026 jedes System denselben Vorgang selbst
 * zusammensetzte — und die vier Fassungen auseinanderliefen. Diese Tests halten
 * fest, was ueberall gleich sein muss.
 */
const EIGENE: EventKind[] = [
    { key: 'crm_termin', label: 'Termin', creatable: true },
];

const FREMDE_ARTEN = [
    { key: 'business', label: 'Geschäftlich' },
    { key: 'meeting_digital', label: 'Meeting digital', requires: ['meeting_url'] },
];

const ZIELE: RemoteSource[] = [
    { key: 'manager', label: 'Manager', writable: true },
    { key: 'nur-lesen', label: 'Feiertage', writable: false },
];

let anfragen: Array<{ url: string; init?: RequestInit }>;

function antwortet(daten: unknown, ok = true, status = 200) {
    return Promise.resolve({ ok, status, json: () => Promise.resolve(daten) } as Response);
}

beforeEach(() => {
    anfragen = [];
    vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
        anfragen.push({ url, init });

        return url.endsWith('/kinds') ? antwortet({ kinds: FREMDE_ARTEN }) : antwortet({ id: 'manager:7' });
    });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('die Zielwahl', () => {
    it('zeigt nur Systeme, die Termine annehmen', () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        const wahl = screen.getByLabelText('Anlegen in') as HTMLSelectElement;
        const eintraege = Array.from(wahl.options).map((o) => o.textContent);

        // „Feiertage" ist lesbar, aber nicht beschreibbar — ein Eintrag dafuer
        // waere eine Einladung zu einem Vorgang, den das Zielsystem abweist.
        expect(eintraege).toEqual(['Mein Kalender', 'Manager']);
    });

    it('erscheint beim Bearbeiten gar nicht', () => {
        // Ein Termin, den es schon gibt, gehoert bereits einem System. Ihn
        // umzuziehen waere ein anderer Vorgang als ihn zu aendern.
        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                value={{
                    kind: 'crm_termin',
                    title: 'Bestehender Termin',
                    date: '2026-11-03',
                    endDate: '',
                    start: '09:00',
                    end: '10:00',
                    allDay: false,
                    location: '',
                    description: '',
                    meetingUrl: '',
                    category: '',
                    categoryId: '',
                    recurrence: null,
                }}
                onSaveHere={vi.fn()}
            />,
        );

        expect(screen.queryByLabelText('Anlegen in')).toBeNull();
    });

    it('fehlt ohne beschreibbare Ziele ganz', () => {
        render(<EventComposer kinds={EIGENE} onSaveHere={vi.fn()} />);

        expect(screen.queryByLabelText('Anlegen in')).toBeNull();
    });
});

describe('ein vorgewaehltes Ziel', () => {
    it('ist gleich gesetzt und holt die Arten des Systems', async () => {
        // Fuer den Weg „woanders anlegen" aus einem Auswahldialog: Wer dort ein
        // fremdes System anklickt, hat die Wahl schon getroffen.
        render(<EventComposer kinds={EIGENE} targets={ZIELE} initialTarget="manager" onSaveHere={vi.fn()} />);

        await waitFor(() => {
            expect((screen.getByLabelText('Anlegen in') as HTMLSelectElement).value).toBe('manager');
        });

        await waitFor(() => {
            expect(screen.getByRole('option', { name: 'Meeting digital' })).toBeTruthy();
        });
    });

    it('holt die Arten nur einmal, nicht bei jedem Rendern', async () => {
        const { rerender } = render(
            <EventComposer kinds={EIGENE} targets={ZIELE} initialTarget="manager" onSaveHere={vi.fn()} />,
        );

        await waitFor(() => expect(anfragen.filter((a) => a.url.endsWith('/kinds'))).toHaveLength(1));

        rerender(<EventComposer kinds={EIGENE} targets={ZIELE} initialTarget="manager" onSaveHere={vi.fn()} />);
        rerender(<EventComposer kinds={EIGENE} targets={ZIELE} initialTarget="manager" onSaveHere={vi.fn()} />);

        // Ein Netzweg je Rendern waere eine Schleife, die niemand bemerkt,
        // bis das andere System drosselt.
        expect(anfragen.filter((a) => a.url.endsWith('/kinds'))).toHaveLength(1);
    });
});

describe('ein fremdes Ziel', () => {
    it('laesst die Maske nach SEINEN Arten zeichnen', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        // Vorher: die eigene Art.
        expect(screen.queryByRole('option', { name: 'Meeting digital' })).toBeNull();

        fireEvent.change(screen.getByLabelText('Anlegen in'), { target: { value: 'manager' } });

        // Nachher: die des Zielsystems — und damit auch deren Felder.
        await waitFor(() => {
            expect(screen.getByRole('option', { name: 'Meeting digital' })).toBeTruthy();
        });
    });

    it('blendet die systemeigenen Felder aus', async () => {
        // Wer aus dem CRM im Manager anlegt, hat dort keinen Deal — ein Feld
        // dafuer naehme eine Eingabe entgegen, die nirgends ankommt.
        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                onSaveHere={vi.fn()}
                extraFields={<label htmlFor="deal">Deal</label>}
            />,
        );

        expect(screen.getByText('Deal')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Anlegen in'), { target: { value: 'manager' } });

        await waitFor(() => {
            expect(screen.queryByText('Deal')).toBeNull();
        });
    });

    it('kehren die eigenen Felder zurueck, wenn man wieder umschaltet', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                onSaveHere={vi.fn()}
                extraFields={<label htmlFor="deal">Deal</label>}
            />,
        );

        fireEvent.change(screen.getByLabelText('Anlegen in'), { target: { value: 'manager' } });
        await waitFor(() => expect(screen.queryByText('Deal')).toBeNull());

        fireEvent.change(screen.getByLabelText('Anlegen in'), { target: { value: '' } });
        await waitFor(() => expect(screen.getByText('Deal')).toBeTruthy());
    });
});

describe('der Entwurf', () => {
    it('liegt beim Produkt, wenn es ihn mitbringt', () => {
        // Manche Masken brauchen ihn draussen: Der Manager spiegelt ihn in seine
        // Nutzlast und seine Zusatzfelder lesen ihn mit. Ein Composer, der ihn
        // einschloesse, zwaenge sie zu einem zweiten Zustand daneben.
        const geaendert = vi.fn();
        const entwurf = {
            kind: 'crm_termin',
            title: 'Vom Produkt gehalten',
            date: '2026-11-03',
            endDate: '',
            start: '09:00',
            end: '10:00',
            allDay: false,
            location: '',
            description: '',
            meetingUrl: '',
            category: '',
            categoryId: '',
            recurrence: null,
        };

        render(
            <EventComposer kinds={EIGENE} draft={entwurf} onDraftChange={geaendert} onSaveHere={vi.fn()} />,
        );

        expect((screen.getByLabelText('Titel') as HTMLInputElement).value).toBe('Vom Produkt gehalten');

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Geändert' } });

        // Der Composer schreibt NICHT selbst — er meldet und laesst das Produkt
        // entscheiden. Sonst gaebe es den Entwurf zweimal.
        expect(geaendert).toHaveBeenCalledTimes(1);
        expect(geaendert.mock.calls[0][0].title).toBe('Geändert');
        expect((screen.getByLabelText('Titel') as HTMLInputElement).value).toBe('Vom Produkt gehalten');
    });

    it('liegt beim Composer, wenn das Produkt keinen mitbringt', () => {
        render(<EventComposer kinds={EIGENE} onSaveHere={vi.fn()} />);

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Selbst gehalten' } });

        expect((screen.getByLabelText('Titel') as HTMLInputElement).value).toBe('Selbst gehalten');
    });
});

describe('speichern', () => {
    it('geht im eigenen Kalender ueber das Produkt', () => {
        const gespeichert = vi.fn();

        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={gespeichert} />);

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Rückruf' } });
        fireEvent.change(screen.getByLabelText('Datum'), { target: { value: '2026-11-03' } });
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(gespeichert).toHaveBeenCalledTimes(1);
        expect(gespeichert.mock.calls[0][0].title).toBe('Rückruf');
        // Kein Netzweg: Der eigene Kalender gehoert dem Produkt.
        expect(anfragen.filter((a) => a.url.endsWith('/events'))).toHaveLength(0);
    });

    it('geht bei fremdem Ziel ueber das Paket, nicht ueber das Produkt', async () => {
        const gespeichert = vi.fn();
        const fertig = vi.fn();

        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                onSaveHere={gespeichert}
                onSavedThere={fertig}
            />,
        );

        fireEvent.change(screen.getByLabelText('Anlegen in'), { target: { value: 'manager' } });
        await waitFor(() => expect(screen.getByRole('option', { name: 'Meeting digital' })).toBeTruthy());

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Abstimmung' } });
        fireEvent.change(screen.getByLabelText('Datum'), { target: { value: '2026-11-03' } });
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(fertig).toHaveBeenCalled());

        // Der Termin entsteht drueben — das Produkt wird gar nicht gefragt.
        expect(gespeichert).not.toHaveBeenCalled();

        const anlegen = anfragen.find((a) => a.url.endsWith('/sources/manager/events'));
        expect(anlegen).toBeTruthy();
        expect(JSON.parse(String(anlegen?.init?.body)).title).toBe('Abstimmung');
    });
});

/**
 * Der zweite Weg: eine bestehende Aufgabe einplanen.
 *
 * Er ist der Grund, warum der Moduswechsel im Paket steht und nicht in den
 * vier Produkten: „Neuer Termin" fragt überall dasselbe (#7846).
 */
describe('der Aufgaben-Weg', () => {
    const offen = [{ id: 7, title: 'Abgabe vorbereiten' }];

    /** Ohne angestecktes Aufgabensystem gibt es nichts einzuplanen. */
    it('erscheint nicht, wenn das Produkt keine Suche mitgibt', () => {
        render(<EventComposer kinds={EIGENE} value={null} onSaveHere={() => {}} />);

        expect(screen.queryByText('Aufgabe einplanen')).toBeNull();
    });

    it('bietet beide Wege, sobald eine Suche da ist', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: () => {} }}
            />,
        );

        expect(screen.getByRole('button', { name: 'Termin' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Aufgabe einplanen' })).toBeTruthy();
    });

    /**
     * Beim Bearbeiten steht schon fest, was der Eintrag ist — dieselbe
     * Begründung wie bei der Zielwahl darüber.
     */
    it('fehlt beim Bearbeiten', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={{ ...emptyDraft(), kind: 'termin', title: 'Steht schon' }}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: () => {} }}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Aufgabe einplanen' })).toBeNull();
    });

    it('gibt den Zeitraum mit, nicht nur die Aufgabe', async () => {
        const eingeplant = vi.fn();

        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '14:00', end: '15:30' }}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: eingeplant }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(await screen.findByText('Abgabe vorbereiten'));

        expect(eingeplant).toHaveBeenCalledWith(7, { date: '2026-03-12', start: '14:00', end: '15:30' });
    });

    /** Eine neue Aufgabe anzulegen gehört dem Aufgabensystem — mit dem Zeitraum. */
    it('nimmt den Zeitraum in den Weg hinaus mit', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '14:00', end: '15:30' }}
                onSaveHere={() => {}}
                plannable={{
                    search: () => Promise.resolve(offen),
                    onSchedule: () => {},
                    newHref: '/tasks/create',
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));

        const hinaus = screen.getByText('Neue Aufgabe anlegen') as HTMLAnchorElement;
        expect(hinaus.getAttribute('href')).toBe('/tasks/create?date=2026-03-12&start=14:00&end=15:30');
    });

    /** Im Termin-Weg bleibt die Maske, wie sie war. */
    it('zeigt die Felder weiter, solange „Termin" gewaehlt ist', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: () => {} }}
            />,
        );

        expect(screen.getByLabelText(/Titel/)).toBeTruthy();
    });
});
