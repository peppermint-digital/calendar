/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CalendarUserSwitcher, type SwitchableUser } from '../src/CalendarUserSwitcher';

const leute: SwitchableUser[] = [
    { id: 1, name: 'Ich (Mein Kalender)' },
    { id: 2, name: 'Mika Lehr' },
];

afterEach(() => {
    cleanup();
});

describe('der Umschalter', () => {
    it('meldet die gewaehlte Kennung als ZAHL', () => {
        // Radix liefert Zeichenketten. Ein Produkt, das daraus eine Adresse
        // baut, bekommt sonst `/calendar?user=2` mit einer Zeichenkette — was
        // hier zufaellig gleich aussieht und beim Vergleich mit der eigenen
        // Kennung (`===`) still falsch ist.
        const gewechselt = vi.fn();

        render(
            <CalendarUserSwitcher users={leute} viewingUserId={1} currentUserId={1} onSwitch={gewechselt} />,
        );

        fireEvent.click(screen.getByRole('combobox'));
        fireEvent.click(screen.getByRole('option', { name: 'Mika Lehr' }));

        expect(gewechselt).toHaveBeenCalledWith(2);
    });

    it('navigiert NICHT selbst — das Produkt entscheidet, wohin', () => {
        // Die Begruendung steht im Kopf des Bauteils: Dieselbe Auswahl fuehrt
        // im Kalender und in der Agenda an verschiedene Adressen. Ein festes
        // Ziel hier hat im Manager genau die Kopie erzeugt, die das Teilen
        // vermeiden soll.
        render(
            <CalendarUserSwitcher users={leute} viewingUserId={1} currentUserId={1} onSwitch={vi.fn()} />,
        );

        expect(document.querySelectorAll('a[href]')).toHaveLength(0);
    });

    it('weist darauf hin, wenn man einen FREMDEN Kalender ansieht', () => {
        render(
            <CalendarUserSwitcher users={leute} viewingUserId={2} currentUserId={1} onSwitch={vi.fn()} />,
        );

        expect(screen.getByText('Anzeige: Mika Lehr')).toBeTruthy();
    });

    it('weist beim eigenen Kalender NICHT darauf hin', () => {
        // Ein Hinweis, der immer da ist, wird nicht gelesen.
        render(
            <CalendarUserSwitcher users={leute} viewingUserId={1} currentUserId={1} onSwitch={vi.fn()} />,
        );

        expect(screen.queryByText(/^Anzeige:/)).toBeNull();
    });

    it('laesst das Produkt die Beschriftungen setzen', () => {
        render(
            <CalendarUserSwitcher
                users={leute}
                viewingUserId={2}
                currentUserId={1}
                onSwitch={vi.fn()}
                viewingLabel="Du siehst:"
                placeholder="Agenda wechseln"
            />,
        );

        expect(screen.getByText('Du siehst: Mika Lehr')).toBeTruthy();
    });

    it('zeigt den Platzhalter, wenn die betrachtete Person nicht in der Liste steht', () => {
        /*
         * Die Eigenheit, über die man stolpert: Hier muss der LEERE Wert
         * gesetzt werden. Ein `undefined` machte die Auswahl unkontrolliert und
         * verschluckte den Platzhalter — man sähe einen leeren Wähler ohne
         * jeden Hinweis, was er tut.
         */
        render(
            <CalendarUserSwitcher users={leute} viewingUserId={99} currentUserId={1} onSwitch={vi.fn()} />,
        );

        expect(screen.getByText('Kalender wechseln')).toBeTruthy();
    });
});
