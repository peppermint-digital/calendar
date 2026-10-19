/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { AttendeePicker, type PickableAttendee } from '../src/AttendeePicker';

afterEach(cleanup);

const leute = (anzahl: number, gewaehlt: number[] = []): PickableAttendee[] =>
    Array.from({ length: anzahl }, (_, i) => ({
        id: i + 1,
        name: `Person ${i + 1}`,
        selected: gewaehlt.includes(i + 1),
    }));

describe('AttendeePicker', () => {
    it('meldet die EINE umgeschaltete Person, nicht die neue Liste', () => {
        const onToggle = vi.fn();
        render(<AttendeePicker attendees={leute(3)} onToggle={onToggle} />);

        fireEvent.click(screen.getByRole('button', { name: 'Person 2' }));

        expect(onToggle).toHaveBeenCalledWith(2);
    });

    it('zeigt den Zustand als aria-pressed, nicht nur als Farbe', () => {
        render(<AttendeePicker attendees={leute(3, [2])} onToggle={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'Person 2' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('button', { name: 'Person 1' }).getAttribute('aria-pressed')).toBe('false');
    });

    describe('Suchfeld', () => {
        it('bleibt weg, solange die Liste ueberschaubar ist', () => {
            render(<AttendeePicker attendees={leute(5)} onToggle={vi.fn()} />);

            expect(screen.queryByRole('searchbox')).toBeNull();
        });

        it('erscheint, sobald es viele werden', () => {
            render(<AttendeePicker attendees={leute(12)} onToggle={vi.fn()} />);

            expect(screen.getByRole('searchbox')).toBeTruthy();
        });

        it('filtert die Ungewaehlten', () => {
            render(<AttendeePicker attendees={leute(12)} onToggle={vi.fn()} />);

            fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Person 1' } });

            // „Person 1", „Person 10", „Person 11", „Person 12"
            expect(screen.getByRole('button', { name: 'Person 12' })).toBeTruthy();
            expect(screen.queryByRole('button', { name: 'Person 2' })).toBeNull();
        });

        it('laesst die Gewaehlten stehen, auch wenn sie nicht zur Suche passen', () => {
            render(<AttendeePicker attendees={leute(12, [2])} onToggle={vi.fn()} />);

            fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'Person 7' } });

            // Sonst schickt jemand eine Einladung an eine Runde, die er gerade
            // nicht sieht.
            expect(screen.getByRole('button', { name: 'Person 2' })).toBeTruthy();
            expect(screen.getByRole('button', { name: 'Person 7' })).toBeTruthy();
        });

        it('sucht auch im Zusatz, nicht nur im Namen', () => {
            const mitAbteilung = leute(12).map((person, i) => ({
                ...person,
                hint: i === 4 ? 'Buchhaltung' : 'Technik',
            }));

            render(<AttendeePicker attendees={mitAbteilung} onToggle={vi.fn()} />);
            fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'buchhalt' } });

            expect(screen.getByRole('button', { name: 'Person 5' })).toBeTruthy();
            expect(screen.queryByRole('button', { name: 'Person 1' })).toBeNull();
        });

        it('sagt es, wenn die Suche niemanden trifft', () => {
            render(<AttendeePicker attendees={leute(12)} onToggle={vi.fn()} />);

            fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'gibtsnicht' } });

            expect(screen.getByText('Niemand gefunden.')).toBeTruthy();
        });

        it('zaehlt die Gewaehlten, wenn die Liste lang ist', () => {
            render(<AttendeePicker attendees={leute(12, [1, 2, 3])} onToggle={vi.fn()} />);

            expect(screen.getByText('3 ausgewählt')).toBeTruthy();
        });
    });
});
