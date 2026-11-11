/**
 * @vitest-environment jsdom
 */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { TimeField } from '../src/TimeField';

afterEach(cleanup);

/** Was die beiden Listen gerade anzeigen — Radix schreibt es in den Ausloeser. */
const anzeige = () => screen.getAllByRole('combobox').map((e) => e.textContent?.trim());

describe('die beiden Listen', () => {
    it('zerlegt HH:MM in Stunde und Minute', () => {
        render(<TimeField value="09:30" />);

        expect(anzeige()).toEqual(['09', '30']);
    });

    it('nimmt auch HH:MM:SS an — die Sekunden fallen weg', () => {
        render(<TimeField value="14:05:00" />);

        expect(anzeige()).toEqual(['14', '05']);
    });

    it('fuellt einstellige Angaben auf', () => {
        render(<TimeField value="9:5" />);

        expect(anzeige()).toEqual(['09', '05']);
    });

    /**
     * Ein leerer Wert ist der Normalfall bei einem neuen Termin: Dann steht in
     * beiden Listen der Platzhalter und keine geratene Uhrzeit.
     */
    it('zeigt bei leerem Wert den Platzhalter', () => {
        render(<TimeField value="" />);

        expect(anzeige()).toEqual(['--', '--']);
    });

    /**
     * Eine gespeicherte Uhrzeit liegt selten auf dem Fuenf-Minuten-Raster — eine
     * gestempelte Kommt-Zeit ist 08:37. Ohne Einsortieren faellt Radix still auf
     * den Platzhalter zurueck: Der Wert ist da, nur unsichtbar, und beim
     * naechsten Speichern weg.
     */
    it('zeigt eine krumme Minute, die nicht auf dem Raster liegt', () => {
        render(<TimeField value="08:37" />);

        expect(anzeige()).toEqual(['08', '37']);
    });
});

/**
 * Ein Name fuer Hilfsmittel (#9808).
 *
 * In der Zeitraum-Stufe des Aufgaben-Wegs standen zwei Zeitfelder nebeneinander,
 * ueber ihnen nur „Wann". Ein Vorleser sagte „Auswahl, Auswahl, Auswahl,
 * Auswahl" — welches Paar der Beginn und welches das Ende war, stand nirgends.
 *
 * Sichtbar aendert das hier nichts: Ein Name fuer Hilfsmittel ist nicht
 * dasselbe wie eine sichtbare Beschriftung.
 */
describe('der Name der Uhrzeit', () => {
    /**
     * Die Uhrzeit ist EIN Feld aus zwei Listen. Darum traegt die Huelle den
     * Namen und wird zur Gruppe, und die Listen tragen ihre Rolle darin — so
     * nennt ein Vorleser beim Betreten „Beginn" und danach, in welcher der
     * beiden man steht.
     */
    it('benennt die Gruppe und darin die beiden Listen', () => {
        render(<TimeField label="Beginn" value="09:30" />);

        const gruppe = screen.getByLabelText('Beginn');
        expect(gruppe.getAttribute('role')).toBe('group');
        expect(gruppe.getAttribute('data-slot')).toBe('time-field');

        // Und genau EIN Treffer auf den Namen: Ein Feld unter zwei Namen waere
        // schlechter als eines unter einem.
        expect(screen.getAllByLabelText('Beginn')).toHaveLength(1);

        expect(screen.getByLabelText('Stunde').textContent?.trim()).toBe('09');
        expect(screen.getByLabelText('Minute').textContent?.trim()).toBe('30');
    });

    /** Zwei Felder nebeneinander sind auseinanderzuhalten — darum ging es. */
    it('haelt Beginn und Ende auseinander', () => {
        render(
            <div>
                <TimeField label="Beginn" value="14:00" />
                <TimeField label="Ende" value="15:30" />
            </div>,
        );

        expect(screen.getByLabelText('Beginn').textContent).toContain('14');
        expect(screen.getByLabelText('Ende').textContent).toContain('15');
    });

    /** Die Woerter gehoeren dem Produkt, wie alles andere im Paket. */
    it('laesst die Woerter ueberschreiben', () => {
        render(<TimeField label="Start" hourLabel="Std" minuteLabel="Min" value="08:05" />);

        expect(screen.getByLabelText('Std').textContent?.trim()).toBe('08');
        expect(screen.getByLabelText('Min').textContent?.trim()).toBe('05');
    });

    /**
     * Ohne Angabe bleibt alles, wie es war.
     *
     * Das ist die Ruecksicht auf das {@link EventForm} und auf jede Maske, die
     * ihre Zeitfelder sichtbar beschriftet: Ein unbedingtes `aria-label` wuerde
     * die sichtbare Beschriftung still verdraengen.
     */
    it('benennt ohne Angabe nichts — und verdraengt so keine sichtbare Beschriftung', () => {
        render(
            <div>
                <label htmlFor="von">Von</label>
                <TimeField id="von" value="09:30" />
            </div>,
        );

        const listen = screen.getAllByRole('combobox');
        expect(listen.map((liste) => liste.getAttribute('aria-label'))).toEqual([null, null]);
        expect(document.querySelector('[data-slot="time-field"]')?.getAttribute('role')).toBeNull();

        // Die sichtbare Beschriftung benennt weiter die Stundenliste, und sie
        // ist der einzige Treffer.
        expect(screen.getAllByLabelText('Von')).toHaveLength(1);
    });
});
