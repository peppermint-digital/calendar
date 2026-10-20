/**
 * Die Filterschluessel des Kalenders — Vorzeichen, Pruefung, Umschalten.
 *
 * ## Warum das ein eigenes Modul ist
 *
 * Bis zum 20.10.2026 standen diese vier Helfer in `CalendarFilters.tsx`, neben
 * einer Leisten-Komponente gleichen Namens. Die Komponente war toter Code:
 * `CalendarFilterPanel` hat sie ersetzt und wird von allen vier Systemen
 * benutzt, waehrend `CalendarFilters` nur noch von seinem eigenen Test
 * gerendert wurde.
 *
 * Die HELFER waren es nicht — das AI Brain benutzt `SOURCE_KEY_PREFIX`,
 * `isHidden` und `sourceKey`. „Die Datei ist tot" war deshalb falsch; tot war
 * die Komponente, und das Modul lebt. Gemessen, nicht geschaetzt: Ein Blick
 * auf den Dateinamen haette hier Code mitgerissen, der in Benutzung ist.
 */
/**
 * Das Vorzeichen, mit dem eine Fremdquelle im Filterzustand steht.
 *
 * Arten und Quellen teilen eine Liste — `hidden` ist eine Liste von
 * Schlüsseln, nicht zwei. Ohne Vorzeichen verdeckte eine Quelle namens
 * `manager` eine Terminart namens `manager`, und zwar erst bei dem Kunden, der
 * beides hat. Das Brain führte das Vorzeichen zuerst ein; hier steht es als
 * Konstante, damit die vier Systeme nicht drei Schreibweisen davon haben.
 */
export const SOURCE_KEY_PREFIX = 'source:';

/** Der Filterschlüssel einer Fremdquelle. */
export function sourceKey(key: string): string {
    return `${SOURCE_KEY_PREFIX}${key}`;
}

/**
 * Ist dieser Eintrag gerade versteckt?
 *
 * Die Regel steht hier und nicht in den Seiten, weil sie zweimal gebraucht
 * wird — beim Zeichnen der Leiste und beim Aussieben der Einträge — und weil
 * sie beim Aussieben leicht anders gerät als beim Zeichnen. Genau das war im
 * Brain der Fall: Die Leiste kannte `source:`, der Filter für eigene Termine
 * nicht.
 */
export function isHidden(
    hidden: readonly string[],
    // Freigestellt, weil ein FREMDER Termin keine eigene Art hat: Er haengt an
    // seiner Quelle, und die Art drueben ist ein Begriff des anderen Systems.
    // `isHidden(hidden, null, 'manager')` liest sich wie es gemeint ist.
    kind?: string | null,
    source?: string | null,
): boolean {
    if (source !== undefined && source !== null && source !== '') {
        return hidden.includes(sourceKey(source));
    }

    if (kind === undefined || kind === null || kind === '') {
        return false;
    }

    return hidden.includes(kind);
}

/** Einen Schlüssel umschalten und die neue Liste zurückgeben. */
export function toggleHidden(hidden: readonly string[], key: string): string[] {
    return hidden.includes(key) ? hidden.filter((eintrag) => eintrag !== key) : [...hidden, key];
}
