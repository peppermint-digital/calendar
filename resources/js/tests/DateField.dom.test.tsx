/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { DateField } from '../src/DateField';

afterEach(cleanup);

const feld = () => screen.getByPlaceholderText('TT.MM.JJJJ') as HTMLInputElement;

describe('die Anzeige', () => {
    it('zeigt ein ISO-Datum deutsch', () => {
        render(<DateField value="2026-09-08" onChange={() => {}} />);

        expect(feld().value).toBe('08.09.2026');
    });

    it('bleibt leer, wenn es nichts anzuzeigen gibt', () => {
        render(<DateField value="" onChange={() => {}} />);

        expect(feld().value).toBe('');
    });

    /**
     * Ohne das zeigt das Feld nach einem Sprung im Kalender noch das alte
     * Datum — der Wert kommt von aussen, die Anzeige haengt hinterher.
     */
    it('uebernimmt einen von aussen gesetzten Wert', () => {
        const { rerender } = render(<DateField value="2026-09-08" onChange={() => {}} />);
        rerender(<DateField value="2026-12-24" onChange={() => {}} />);

        expect(feld().value).toBe('24.12.2026');
    });
});

describe('die Eingabe', () => {
    it('nimmt die volle Schreibweise an', () => {
        const geaendert = vi.fn();
        render(<DateField value="" onChange={geaendert} />);

        fireEvent.change(feld(), { target: { value: '08.09.2026' } });

        expect(geaendert).toHaveBeenCalledWith('2026-09-08');
    });

    /** Nachsichtig: „8.9.26" ist gemeint und wird verstanden. */
    it('nimmt die kurze Schreibweise an', () => {
        const geaendert = vi.fn();
        render(<DateField value="" onChange={geaendert} />);

        fireEvent.change(feld(), { target: { value: '8.9.26' } });

        expect(geaendert).toHaveBeenCalledWith('2026-09-08');
    });

    /**
     * Wer ein Datum aus einer Mail oder einer anderen Maske hineinkopiert, hat
     * es meistens in ISO. Es abzulehnen hiesse, still nichts zu tun.
     */
    it('nimmt auch ein ISO-Datum an', () => {
        const geaendert = vi.fn();
        render(<DateField value="" onChange={geaendert} />);

        fireEvent.change(feld(), { target: { value: '2026-09-08' } });

        expect(geaendert).toHaveBeenCalledWith('2026-09-08');
    });

    /**
     * Wer „8." getippt hat, ist noch nicht fertig. Ein Wert, der nach jedem
     * Tastendruck nach aussen geht, laesst den Entwurf zwischendurch auf Daten
     * stehen, die niemand meinte.
     */
    it('meldet Unvollstaendiges nicht nach oben', () => {
        const geaendert = vi.fn();
        render(<DateField value="" onChange={geaendert} />);

        fireEvent.change(feld(), { target: { value: '8.' } });
        fireEvent.change(feld(), { target: { value: '8.9' } });

        expect(geaendert).not.toHaveBeenCalled();
    });

    it('laesst die halbfertige Eingabe trotzdem stehen', () => {
        render(<DateField value="" onChange={() => {}} />);

        fireEvent.change(feld(), { target: { value: '8.9' } });

        expect(feld().value).toBe('8.9');
    });

    /** Beim Verlassen auf die gepflegte Schreibweise — dann sieht man, dass es ankam. */
    it('raeumt die Schreibweise beim Verlassen auf', () => {
        const { rerender } = render(<DateField value="" onChange={() => {}} />);

        fireEvent.change(feld(), { target: { value: '8.9.26' } });
        rerender(<DateField value="2026-09-08" onChange={() => {}} />);
        fireEvent.blur(feld());

        expect(feld().value).toBe('08.09.2026');
    });
});

/**
 * Die Auswahl am Telefon (#1070, 22.10.2026).
 *
 * Vorher war Tippen der einzige Weg, und `inputMode="numeric"` holt am Telefon
 * die Zahlentastatur hoch. Ein Datum als Ziffernfolge einzugeben ist so
 * umstaendlich, dass Termine mobil praktisch nicht angelegt wurden.
 */
describe('die Auswahl ueber den Knopf', () => {
    const knopf = () => screen.getByRole('button', { name: 'Datum auswählen' });
    const nativ = (behaelter: HTMLElement) =>
        behaelter.querySelector('input[type="date"]') as HTMLInputElement;

    it('oeffnet die native Auswahl', () => {
        const { container } = render(<DateField value="2026-09-08" onChange={() => {}} />);
        const zeigen = vi.fn();
        nativ(container).showPicker = zeigen;

        fireEvent.click(knopf());

        expect(zeigen).toHaveBeenCalledOnce();
    });

    /**
     * `showPicker()` gibt es erst ab Chrome 99 / Safari 16 / Firefox 101, und
     * manche Browser lehnen den Aufruf ab. Ein Knopf, der dann nichts tut, ist
     * schlimmer als keiner — deshalb wenigstens der Fokus ins Textfeld.
     */
    it('setzt den Fokus ins Textfeld, wenn es keine native Auswahl gibt', () => {
        const { container } = render(<DateField value="2026-09-08" onChange={() => {}} />);
        // @ts-expect-error — den Browser ohne `showPicker` nachstellen.
        nativ(container).showPicker = undefined;

        fireEvent.click(knopf());

        expect(document.activeElement).toBe(feld());
    });

    it('traegt ein ausgewaehltes Datum ein und zeigt es deutsch', () => {
        const gemeldet = vi.fn();
        const { container } = render(<DateField value="" onChange={gemeldet} />);

        fireEvent.change(nativ(container), { target: { value: '2026-12-24' } });

        expect(gemeldet).toHaveBeenCalledWith('2026-12-24');
        expect(feld().value).toBe('24.12.2026');
    });

    /**
     * Der eigentliche Waechter. Das SICHTBARE Feld darf nie ein natives
     * Datumsfeld werden: Das folgt der Sprache des Browsers, nicht der der
     * Anwendung, und zeigt auf englisch eingestelltem Chrome `mm/dd/yyyy` —
     * dort ist der 9. August vom 8. September nicht zu unterscheiden. Genau
     * das wurde am 15.10.2026 in drei von vier Anwendungen gesehen (#7846).
     *
     * Das native Feld dahinter ist deshalb ausdruecklich `aria-hidden` und
     * nicht erreichbar: Es ist der Auswaehler, nie die Anzeige.
     */
    it('zeigt weiterhin das eigene deutsche Feld, nicht das native', () => {
        const { container } = render(<DateField value="2026-09-08" onChange={() => {}} />);

        expect(feld().type).toBe('text');
        expect(feld().inputMode).toBe('numeric');

        expect(nativ(container).getAttribute('aria-hidden')).toBe('true');
        expect(nativ(container).tabIndex).toBe(-1);
    });
});
