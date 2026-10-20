/**
 * Die Filterschluessel: Vorzeichen, Pruefung, Umschalten.
 *
 * Die Leisten-Tests standen bis zum 20.10.2026 in derselben Datei und sind mit
 * der Komponente `CalendarFilters` entfallen — sie war toter Code, ersetzt
 * durch `CalendarFilterPanel`, das alle vier Systeme benutzen.
 *
 * Diese Faelle bleiben, weil die Helfer leben: Das AI Brain benutzt
 * `SOURCE_KEY_PREFIX`, `isHidden` und `sourceKey`. Haette ich nach dem
 * Dateinamen geloescht, waere getesteter Code in Benutzung mitgegangen.
 */
import { describe, expect, it } from 'vitest';

import { isHidden, sourceKey, toggleHidden } from '../src/filters';

describe('die Regel zum Aussieben', () => {
    it('siebt eine eigene Art ueber ihren Schluessel', () => {
        expect(isHidden(['termin'], 'termin')).toBe(true);
        expect(isHidden(['termin'], 'aufgabe')).toBe(false);
    });

    it('siebt einen fremden Termin ueber seine Quelle, nicht ueber seine Art', () => {
        // Die Art heisst drueben vielleicht genauso wie hier — sichtbar ist
        // sie ueber die Quelle, aus der sie kommt.
        expect(isHidden(['source:manager'], 'termin', 'manager')).toBe(true);
        expect(isHidden(['termin'], 'termin', 'manager')).toBe(false);
    });

    it('behandelt eine leere Quelle wie keine', () => {
        expect(isHidden(['termin'], 'termin', '')).toBe(true);
        expect(isHidden(['termin'], 'termin', null)).toBe(true);
    });

    it('schaltet um, ohne die alte Liste anzufassen', () => {
        const vorher = ['a'];

        expect(toggleHidden(vorher, 'b')).toEqual(['a', 'b']);
        expect(toggleHidden(vorher, 'a')).toEqual([]);
        expect(vorher).toEqual(['a']);
    });

    it('schreibt das Vorzeichen an einer Stelle', () => {
        expect(sourceKey('manager')).toBe('source:manager');
    });
});

/**
 * Ein FREMDER Termin hat keine eigene Art — er hängt an seiner Quelle, und
 * die Art drüben ist ein Begriff des anderen Systems. Im Brain stand genau
 * dafür ein zweiter, handgeschriebener Filter (16.10.2026).
 */
it('siebt einen fremden Termin auch ohne Art', () => {
    expect(isHidden(['source:manager'], null, 'manager')).toBe(true);
    expect(isHidden(['source:crm'], null, 'manager')).toBe(false);
    expect(isHidden([], undefined, undefined)).toBe(false);
});
