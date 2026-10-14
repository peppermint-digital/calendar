/**
 * @vitest-environment jsdom
 */
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { remoteMoment, useRemoteTarget, type RemoteSource } from '../src/useRemoteTarget';

const QUELLEN: RemoteSource[] = [
    { key: 'manager', label: 'Peppermint Manager', writable: true },
    { key: 'verwaltung', label: 'Verwaltung', writable: true },
    { key: 'kalender-abo', label: 'Feiertage', writable: false },
];

let anfragen: Array<{ url: string; init?: RequestInit }>;

function antwortet(daten: unknown, ok = true, status = 200) {
    return Promise.resolve({
        ok,
        status,
        json: () => Promise.resolve(daten),
    } as Response);
}

beforeEach(() => {
    anfragen = [];
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('die Zonenregel', () => {
    /**
     * Der Grund, warum dieser Haken überhaupt im Paket liegt: Die Regel ist
     * einmal falsch angewendet worden (Bug #995), und zwar auf der sendenden
     * Seite — während die empfangende sie bereits kannte und behoben hatte.
     */
    it('schickt eine Uhrzeit MIT Zone', () => {
        const ergebnis = remoteMoment('2026-10-13', '09:00', false);

        // Eine Angabe ohne Zone würde drüben in DEREN Anwendungszone gelesen.
        expect(ergebnis).toMatch(/Z$|[+-]\d{2}:\d{2}$/);
        expect(ergebnis).not.toBe('2026-10-13T09:00:00');
    });

    it('lässt bei einem Ganztagstermin das nackte Datum stehen', () => {
        // Hier ist die Zone bedeutungslos — eine Umrechnung schöbe den Termin
        // über Mitternacht auf den Vor- oder Folgetag.
        expect(remoteMoment('2026-10-13', '00:00', true)).toBe('2026-10-13T00:00:00');
    });

    it('verschiebt den Tag eines Ganztagstermins auch nahe Mitternacht nicht', () => {
        expect(remoteMoment('2026-10-13', '23:59', true)).toBe('2026-10-13T23:59:00');
    });
});

describe('das Ziel wählen', () => {
    it('holt die Terminarten erst beim Auswählen', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return antwortet({ kinds: [{ key: 'business', label: 'Geschäftlich' }] });
        });

        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN }));

        // Beim Laden passiert nichts — der Aufruf geht in ein anderes System
        // und hat im Kalenderladen nichts verloren.
        expect(anfragen).toHaveLength(0);

        await act(async () => {
            await result.current.chooseTarget('manager');
        });

        expect(anfragen).toHaveLength(1);
        expect(anfragen[0].url).toBe('/api/calendar/sources/manager/kinds');
        expect(result.current.target).toBe('manager');
    });

    it('wählt die erste Art vor', async () => {
        vi.stubGlobal('fetch', () =>
            antwortet({
                kinds: [
                    { key: 'business', label: 'Geschäftlich' },
                    { key: 'leave', label: 'Urlaub' },
                ],
            }),
        );

        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN }));

        await act(async () => {
            await result.current.chooseTarget('manager');
        });

        // Eine Liste ohne Auswahl führt zu einer Maske, die beim Abschicken
        // nach etwas fragt, das man schon sieht.
        await waitFor(() => expect(result.current.targetKind).toBe('business'));
        expect(result.current.targetKinds).toHaveLength(2);
    });

    it('räumt die Arten weg, wenn zurück auf den eigenen Kalender gewechselt wird', async () => {
        vi.stubGlobal('fetch', () => antwortet({ kinds: [{ key: 'business', label: 'Geschäftlich' }] }));

        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN }));

        await act(async () => {
            await result.current.chooseTarget('manager');
        });
        await act(async () => {
            await result.current.chooseTarget('');
        });

        // Sonst stünde die Art eines fremden Systems an einem eigenen Termin.
        expect(result.current.targetKinds).toEqual([]);
        expect(result.current.targetKind).toBe('');
    });

    it('meldet, wenn das andere System die Arten nicht herausgibt', async () => {
        vi.stubGlobal('fetch', () => antwortet(null, false, 503));

        const gemeldet: string[] = [];
        const { result } = renderHook(() =>
            useRemoteTarget({ sources: QUELLEN, onError: (m) => gemeldet.push(m) }),
        );

        await act(async () => {
            await result.current.chooseTarget('manager');
        });

        expect(gemeldet).toHaveLength(1);
        expect(gemeldet[0]).toContain('nicht abrufbar');
    });
});

describe('welche Ziele überhaupt annehmen', () => {
    it('nennt nur die beschreibbaren Quellen', () => {
        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN }));

        // Ob ein System Termine annimmt, sagt das Zielsystem — nicht wir.
        expect(result.current.writableSources.map((q) => q.key)).toEqual(['manager', 'verwaltung']);
        expect(result.current.isWritable('kalender-abo')).toBe(false);
        expect(result.current.isWritable('manager')).toBe(true);
    });
});

describe('drüben anlegen', () => {
    it('schickt Art, Zeitraum und Zone', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return url.endsWith('/kinds')
                ? antwortet({ kinds: [{ key: 'business', label: 'Geschäftlich' }] })
                : antwortet({ id: 'manager:42' });
        });

        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN }));

        await act(async () => {
            await result.current.chooseTarget('manager');
        });

        let erfolg = false;
        await act(async () => {
            erfolg = await result.current.createThere({
                date: '2026-10-20',
                start: '09:00',
                end: '10:00',
                title: 'Jahresgespräch',
            });
        });

        expect(erfolg).toBe(true);

        const anlegen = anfragen[1];
        expect(anlegen.url).toBe('/api/calendar/sources/manager/events');
        expect(anlegen.init?.method).toBe('POST');

        const nutzlast = JSON.parse(String(anlegen.init?.body));
        expect(nutzlast.kind).toBe('business');
        expect(nutzlast.title).toBe('Jahresgespräch');
        expect(nutzlast.all_day).toBe(false);
        expect(nutzlast.starts_at).toMatch(/Z$|[+-]\d{2}:\d{2}$/);
    });

    it('legt ohne gewählte Art nichts an', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return antwortet({ kinds: [] });
        });

        const gemeldet: string[] = [];
        const { result } = renderHook(() =>
            useRemoteTarget({ sources: QUELLEN, onError: (m) => gemeldet.push(m) }),
        );

        await act(async () => {
            await result.current.chooseTarget('manager');
        });

        let erfolg = true;
        await act(async () => {
            erfolg = await result.current.createThere({ date: '2026-10-20', title: 'Ohne Art' });
        });

        expect(erfolg).toBe(false);
        // Nur die Abfrage der Arten, kein Anlegeversuch.
        expect(anfragen).toHaveLength(1);
        expect(gemeldet[0]).toContain('Terminart');
    });

    it('gibt die Meldung des Zielsystems weiter, nicht nur die Zahl', async () => {
        vi.stubGlobal('fetch', (url: string) =>
            url.endsWith('/kinds')
                ? antwortet({ kinds: [{ key: 'business', label: 'Geschäftlich' }] })
                : antwortet({ message: 'Für diese Art fehlt dir die Berechtigung.' }, false, 403),
        );

        const gemeldet: string[] = [];
        const { result } = renderHook(() =>
            useRemoteTarget({ sources: QUELLEN, onError: (m) => gemeldet.push(m) }),
        );

        await act(async () => {
            await result.current.chooseTarget('manager');
        });
        await act(async () => {
            await result.current.createThere({ date: '2026-10-20', start: '09:00', title: 'Test' });
        });

        // Das Zielsystem kennt den Grund, wir kennen nur die Zahl.
        expect(gemeldet[0]).toBe('Für diese Art fehlt dir die Berechtigung.');
    });
});

describe('fremde Termine verschieben und löschen', () => {
    it('trennt den Quellenschlüssel von der Kennung', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return antwortet({});
        });

        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN }));

        await act(async () => {
            await result.current.moveThere('manager:42', new Date('2026-10-20T09:00:00Z'), new Date('2026-10-20T10:00:00Z'));
        });

        expect(anfragen[0].url).toBe('/api/calendar/sources/manager/events/42');
        expect(anfragen[0].init?.method).toBe('PATCH');
    });

    it('lässt Doppelpunkte INNERHALB der Kennung stehen', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return antwortet({});
        });

        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN }));

        // CalDAV-Kennungen tragen selbst Doppelpunkte. Ein `split(':')[1]`
        // hätte hier die halbe Kennung verschluckt.
        await act(async () => {
            await result.current.deleteThere('manager:urn:uuid:7f3a.ics');
        });

        expect(anfragen[0].url).toBe('/api/calendar/sources/manager/events/urn:uuid:7f3a.ics');
        expect(anfragen[0].init?.method).toBe('DELETE');
    });

    it('schickt beim Verschieben eines Ganztagstermins die Angabe mit', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return antwortet({});
        });

        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN }));

        await act(async () => {
            await result.current.moveThere(
                'manager:42',
                new Date('2026-10-20T00:00:00Z'),
                new Date('2026-10-21T00:00:00Z'),
                true,
            );
        });

        // Ohne sie würde drüben aus einem Ganztagstermin ein Zeittermin.
        expect(JSON.parse(String(anfragen[0].init?.body)).all_day).toBe(true);
    });
});

describe('nach jedem Vorgang', () => {
    it('meldet sich zum Neuladen', async () => {
        vi.stubGlobal('fetch', () => antwortet({}));

        const fertig = vi.fn();
        const { result } = renderHook(() => useRemoteTarget({ sources: QUELLEN, onDone: fertig }));

        await act(async () => {
            await result.current.deleteThere('manager:42');
        });

        // Ohne das Nachladen bliebe der Kalender auf dem Stand von vorher —
        // und der Termin stünde noch da, obwohl er drüben weg ist.
        expect(fertig).toHaveBeenCalledTimes(1);
    });
});
