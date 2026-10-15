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
