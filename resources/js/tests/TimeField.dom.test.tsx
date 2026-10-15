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
