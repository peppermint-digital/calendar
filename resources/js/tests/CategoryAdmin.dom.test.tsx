/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CategoryAdmin, type AdminCategory } from '../src/CategoryAdmin';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const kategorie = (over: Partial<AdminCategory> = {}): AdminCategory => ({
    id: 1,
    slug: 'kundentermin',
    label: 'Kundentermin',
    colour: '#f59e0b',
    sort_order: 10,
    is_active: true,
    kind: null,
    in_use: 0,
    ...over,
});

/** Antworten des Servers vortäuschen und jeden Aufruf mitschreiben. */
function fetchFaken(liste: AdminCategory[], kinds: string[] = ['business']) {
    const aufrufe: Array<{ url: string; method: string; body: unknown }> = [];

    const fake = vi.fn(async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';
        aufrufe.push({ url, method, body: init?.body ? JSON.parse(String(init.body)) : undefined });

        if (method === 'GET') {
            return { ok: true, status: 200, json: async () => ({ categories: liste, kinds, mode: 'closed' }) } as Response;
        }

        return { ok: true, status: 200, json: async () => ({}) } as Response;
    });

    vi.stubGlobal('fetch', fake);

    return aufrufe;
}

describe('CategoryAdmin', () => {
    it('zeigt auch deaktivierte Kategorien', async () => {
        fetchFaken([
            kategorie({ id: 1, slug: 'an', label: 'An', is_active: true }),
            kategorie({ id: 2, slug: 'aus', label: 'Aus', is_active: false }),
        ]);

        render(<CategoryAdmin />);

        // Eine deaktivierte Kategorie, die in ihrer eigenen Verwaltung
        // unsichtbar ist, kann niemand wieder einschalten.
        await waitFor(() => expect(screen.getByDisplayValue('An')).toBeTruthy());
        expect(screen.getByDisplayValue('Aus')).toBeTruthy();
    });

    /**
     * Der Slug steht sichtbar neben dem Namen, damit erkennbar ist, dass er
     * beim Umbenennen nicht mitwandert — er ist der Schlüssel, auf den sich
     * die vier Systeme geeinigt haben.
     */
    it('zeigt den Slug neben dem Namen', async () => {
        fetchFaken([kategorie({ slug: 'wartung_betrieb', label: 'Wartung & Betrieb' })]);

        render(<CategoryAdmin />);

        await waitFor(() => expect(screen.getByText('wartung_betrieb')).toBeTruthy());
    });

    it('schickt nur den Namen und lässt den Slug den Server bilden', async () => {
        const aufrufe = fetchFaken([]);

        render(<CategoryAdmin />);
        await waitFor(() => expect(aufrufe.length).toBe(1));

        fireEvent.change(screen.getByLabelText('Kategorie hinzufügen'), { target: { value: '  Vertrieb  ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kategorie hinzufügen' }));

        await waitFor(() => expect(aufrufe.some((a) => a.method === 'POST')).toBe(true));
        const post = aufrufe.find((a) => a.method === 'POST')!;
        expect(post.body).toEqual({ label: 'Vertrieb' });
    });

    it('legt nichts an, wenn das Feld leer ist', async () => {
        const aufrufe = fetchFaken([]);

        render(<CategoryAdmin />);
        await waitFor(() => expect(aufrufe.length).toBe(1));

        fireEvent.change(screen.getByLabelText('Kategorie hinzufügen'), { target: { value: '   ' } });
        fireEvent.keyDown(screen.getByLabelText('Kategorie hinzufügen'), { key: 'Enter' });

        expect(aufrufe.some((a) => a.method === 'POST')).toBe(false);
    });

    it('schaltet eine Kategorie aus', async () => {
        const aufrufe = fetchFaken([kategorie()]);

        render(<CategoryAdmin />);
        await waitFor(() => expect(screen.getByLabelText('aktiv: Kundentermin')).toBeTruthy());

        fireEvent.click(screen.getByLabelText('aktiv: Kundentermin'));

        await waitFor(() => expect(aufrufe.some((a) => a.method === 'PATCH')).toBe(true));
        expect(aufrufe.find((a) => a.method === 'PATCH')!.body).toEqual({ is_active: false });
    });

    it('schickt eine Umbenennung ohne den Slug', async () => {
        const aufrufe = fetchFaken([kategorie()]);

        render(<CategoryAdmin />);
        await waitFor(() => expect(screen.getByDisplayValue('Kundentermin')).toBeTruthy());

        const feld = screen.getByLabelText('Name: Kundentermin');
        fireEvent.blur(feld, { target: { value: 'Kundengespräch' } });

        await waitFor(() => expect(aufrufe.some((a) => a.method === 'PATCH')).toBe(true));
        expect(aufrufe.find((a) => a.method === 'PATCH')!.body).toEqual({ label: 'Kundengespräch' });
    });

    /**
     * Der Griff soll gar nicht da sein, wenn Termine daran hängen. Der Server
     * verweigert es ohnehin mit 409 — aber eine Maske, die zum Löschen
     * einlädt und dann scheitert, lädt zum zweiten Versuch ein.
     */
    it('bietet kein Löschen an, solange Termine daran hängen', async () => {
        fetchFaken([kategorie({ in_use: 199 })]);

        render(<CategoryAdmin />);

        await waitFor(() => expect(screen.getByText('199 Termine')).toBeTruthy());
        expect(screen.queryByRole('button', { name: 'Löschen' })).toBeNull();
    });

    it('bietet Löschen an, wenn nichts daran hängt', async () => {
        const aufrufe = fetchFaken([kategorie({ in_use: 0 })]);

        render(<CategoryAdmin />);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Löschen' })).toBeTruthy());

        fireEvent.click(screen.getByRole('button', { name: 'Löschen' }));

        await waitFor(() => expect(aufrufe.some((a) => a.method === 'DELETE')).toBe(true));
    });

    it('meldet die Fehlermeldung des Servers weiter', async () => {
        const gemeldet: string[] = [];
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 409,
                json: async () => ({ message: 'Diese Kategorie haengt an 199 Terminen.' }),
            }) as Response),
        );

        render(<CategoryAdmin onError={(m) => gemeldet.push(m)} />);

        await waitFor(() => expect(gemeldet).toContain('Diese Kategorie haengt an 199 Terminen.'));
    });

    /** Ohne das Zeichen antwortet jede schreibende Anfrage mit 419. */
    it('schickt das CSRF-Zeichen mit, wenn die Seite eines hat', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="abc123">';
        const kopfzeilen: Array<Record<string, string>> = [];

        vi.stubGlobal(
            'fetch',
            vi.fn(async (_url: string, init?: RequestInit) => {
                if ((init?.method ?? 'GET') !== 'GET') {
                    kopfzeilen.push(init?.headers as Record<string, string>);
                }

                return {
                    ok: true,
                    status: 200,
                    json: async () => ({ categories: [], kinds: [] }),
                } as Response;
            }),
        );

        render(<CategoryAdmin />);
        await waitFor(() => expect(screen.getByLabelText('Kategorie hinzufügen')).toBeTruthy());

        fireEvent.change(screen.getByLabelText('Kategorie hinzufügen'), { target: { value: 'Test' } });
        fireEvent.click(screen.getByRole('button', { name: 'Kategorie hinzufügen' }));

        await waitFor(() => expect(kopfzeilen.length).toBe(1));
        expect(kopfzeilen[0]['X-CSRF-TOKEN']).toBe('abc123');

        document.head.innerHTML = '';
    });
});
