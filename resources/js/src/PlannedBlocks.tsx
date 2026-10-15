import { useMemo, type ReactNode } from 'react';

export type PlannedBlock = {
    id: number | string;
    /** YYYY-MM-DD */
    date: string;
    /** HH:MM */
    start: string;
    end: string;
    /** Was das Produkt sonst noch an diesem Block führt. */
    note?: string | null;
};

export type PlannedBlocksLabels = {
    empty: string;
    total: string;
    add: string;
    remove: string;
};

const DEFAULTS: PlannedBlocksLabels = {
    empty: 'Noch keine Zeit eingeplant.',
    total: 'geplant',
    add: 'Zeit einplanen',
    remove: 'Entfernen',
};

export type PlannedBlocksProps = {
    blocks: PlannedBlock[];
    /** Ohne diesen Haken erscheint kein Entfernen-Knopf. */
    onRemove?: (block: PlannedBlock) => void;
    /** Ohne diesen Haken erscheint kein Anlegen-Knopf. */
    onAdd?: () => void;
    /** Produkteigenes je Block — wer ihn geplant hat, ob er verbucht ist. */
    renderExtra?: (block: PlannedBlock) => ReactNode;
    labels?: Partial<PlannedBlocksLabels>;
    locale?: string;
    className?: string;
};

/** Minuten seit Mitternacht — `0` für alles, was keine Uhrzeit ist. */
function minutesOfDay(zeit: string): number {
    const [stunde, minute] = zeit.split(':');

    return (Number(stunde) || 0) * 60 + (Number(minute) || 0);
}

/** Minuten zwischen zwei Uhrzeiten, über Mitternacht hinweg. */
function minutesBetween(start: string, end: string): number {
    const minutes = minutesOfDay(end) - minutesOfDay(start);

    // 22:00–01:00 ist kein negativer Block, sondern einer über Nacht.
    return minutes < 0 ? minutes + 24 * 60 : minutes;
}

function asHours(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return m === 0 ? `${h} h` : `${h}:${String(m).padStart(2, '0')} h`;
}

/**
 * Die geplante Zeit an einem Gegenstand — Liste, Summe, Anlegen, Entfernen.
 *
 * ## Warum das hier liegt und nicht im Aufgabenpaket
 *
 * Es geht nicht um Aufgaben. Ein geplanter Block hängt an *etwas* — einer
 * Aufgabe, einem Ticket, einem Deal — und der Kalender kennt genau diesen
 * Begriff schon: `PlannedBlockKind` beschreibt „etwas Geplantes", der Kern
 * trägt `subject_type`/`subject_id`.
 *
 * Läge die Komponente im Aufgabenpaket, bräuchte dieses eine JS-Seite (es ist
 * reines PHP) — und eine Ticketverwaltung müsste das Aufgabenpaket einbinden,
 * um ihre Tickets zu planen.
 *
 * ## Was das Produkt liefert
 *
 * Seine Blöcke und seine Wege: `onAdd` und `onRemove` kennen die Routen, die
 * nur das Produkt kennt. Ohne den jeweiligen Haken erscheint der Knopf nicht —
 * eine Ansicht ohne Schreibrecht zeigt dann die Planung, ohne etwas zu
 * versprechen.
 *
 * `renderExtra` ist der Platz für Produkteigenes: wer den Block geplant hat, ob
 * er schon als Zeiteintrag verbucht ist. Der Manager führt beides; das Paket
 * muss davon nichts wissen.
 *
 * ## Die Lücke, die das schliesst
 *
 * Gemessen am 15.10.2026: Der Manager zeigt die geplante Zeit an der Aufgabe,
 * das CRM nicht — dort muss man dafür in den Kalender wechseln und die Aufgabe
 * dort suchen.
 */
export function PlannedBlocks({
    blocks,
    onRemove,
    onAdd,
    renderExtra,
    labels,
    locale = 'de-DE',
    className,
}: PlannedBlocksProps) {
    const beschriftung = { ...DEFAULTS, ...labels };

    const sortiert = useMemo(
        () =>
            [...blocks].sort((a, b) => {
                const nachDatum = a.date.localeCompare(b.date);

                return nachDatum !== 0 ? nachDatum : a.start.localeCompare(b.start);
            }),
        [blocks],
    );

    const summe = useMemo(
        () => sortiert.reduce((minuten, block) => minuten + minutesBetween(block.start, block.end), 0),
        [sortiert],
    );

    const tag = (datum: string) => {
        // Als Ortszeit lesen, nicht als UTC: `new Date('2026-11-03')` ist
        // Mitternacht UTC und wird westlich davon zum 2. November.
        const [jahr, monat, tag] = datum.split('-').map(Number);

        return new Date(jahr ?? 0, (monat ?? 1) - 1, tag ?? 1).toLocaleDateString(locale, {
            weekday: 'short',
            day: '2-digit',
            month: '2-digit',
        });
    };

    return (
        <div className={className}>
            {sortiert.length === 0 ? (
                <p className="text-sm text-muted-foreground">{beschriftung.empty}</p>
            ) : (
                <>
                    <ul className="space-y-2">
                        {sortiert.map((block) => (
                            <li
                                key={block.id}
                                className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-3 py-2 text-sm"
                            >
                                <span className="font-medium whitespace-nowrap">{tag(block.date)}</span>
                                <span className="whitespace-nowrap tabular-nums">
                                    {block.start}–{block.end}
                                </span>
                                <span className="text-xs text-muted-foreground whitespace-nowrap">
                                    {asHours(minutesBetween(block.start, block.end))}
                                </span>

                                {block.note && <span className="text-xs text-muted-foreground">{block.note}</span>}

                                {renderExtra?.(block)}

                                {onRemove && (
                                    <button
                                        type="button"
                                        onClick={() => onRemove(block)}
                                        className="ml-auto text-xs underline underline-offset-2 opacity-70 hover:opacity-100"
                                    >
                                        {beschriftung.remove}
                                    </button>
                                )}
                            </li>
                        ))}
                    </ul>

                    <p className="mt-2 text-sm font-medium tabular-nums">
                        {asHours(summe)} {beschriftung.total}
                    </p>
                </>
            )}

            {onAdd && (
                <button
                    type="button"
                    onClick={onAdd}
                    className="mt-3 rounded-md border px-3 py-2 text-sm hover:bg-muted/50"
                >
                    {beschriftung.add}
                </button>
            )}
        </div>
    );
}
