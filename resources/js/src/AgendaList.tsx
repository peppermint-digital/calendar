import type { ReactNode } from 'react';

export type AgendaRow = {
    key: string;
    /** Kalendertag, `YYYY-MM-DD`. */
    date: string;
    /** `HH:MM`, oder null bei ganztaegig. */
    start: string | null;
    end: string | null;
    title: string;
    location?: string | null;
    /** Farbe der Terminart oder der Quelle — der senkrechte Streifen links. */
    colour?: string | null;
    /** Kurzes Etikett rechts: Terminart oder das System, aus dem der Termin kommt. */
    badge?: string | null;
    recurring?: boolean;
};

export type AgendaListProps = {
    rows: AgendaRow[];
    /** Beschriftung eines Tages — das Produkt kennt seine Sprache und Zeitzone. */
    dayLabel: (date: string) => string;
    onSelect?: (row: AgendaRow) => void;
    /** Was statt der Liste steht, wenn nichts ansteht. */
    empty?: ReactNode;
    allDayLabel?: string;
};

/**
 * Die Agenda: was als Naechstes kommt, nach Tagen gruppiert.
 *
 * Uebernommen aus AI Brain — beide Produkte hatten eine, diese sah besser aus.
 * Bewusst ohne Inertia, ohne Layout und ohne Komponentensammlung: Was
 * hereinkommt, sind Zeilen; was herausgeht, ist eine Liste. Die Seite drumherum
 * — Kopfzeile, Zeitraumwahl, ueberfaellige Aufgaben — bleibt beim Produkt, denn
 * die kennt jedes anders.
 */
export function AgendaList({
    rows,
    dayLabel,
    onSelect,
    empty = null,
    allDayLabel = 'ganztägig',
}: AgendaListProps) {
    if (rows.length === 0) {
        return <>{empty}</>;
    }

    // Nach Tag und Uhrzeit, bevor gruppiert wird: Eine Agenda, deren Reihenfolge
    // von der Laune der Abfrage abhaengt, liest sich wie ein Fehler.
    const sorted = [...rows].sort((a, b) =>
        `${a.date} ${a.start ?? '00:00'}`.localeCompare(`${b.date} ${b.start ?? '00:00'}`),
    );

    const byDay = sorted.reduce<Record<string, AgendaRow[]>>((carry, row) => {
        (carry[row.date] ??= []).push(row);

        return carry;
    }, {});

    return (
        <div className="flex flex-col gap-4">
            {Object.entries(byDay).map(([date, entries]) => (
                <div key={date} className="rounded-lg border">
                    <div className="bg-muted/40 border-b px-3 py-2 text-sm font-medium">{dayLabel(date)}</div>
                    <div className="flex flex-col divide-y">
                        {entries.map((row) => (
                            <div
                                key={row.key}
                                className={
                                    'flex flex-wrap items-center gap-3 px-3 py-2 text-sm' +
                                    (onSelect ? ' hover:bg-muted/50 cursor-pointer' : '')
                                }
                                onClick={onSelect ? () => onSelect(row) : undefined}
                            >
                                <span
                                    className="h-8 w-1 shrink-0 rounded"
                                    style={{ backgroundColor: row.colour ?? '#64748b' }}
                                    aria-hidden
                                />
                                <span className="text-muted-foreground w-24 shrink-0 tabular-nums">
                                    {row.start === null ? allDayLabel : `${row.start}–${row.end ?? ''}`}
                                </span>
                                <span className="min-w-0 flex-1 truncate">
                                    {row.title}
                                    {row.recurring && <span className="text-muted-foreground ml-1">↻</span>}
                                </span>
                                {row.location && (
                                    <span className="text-muted-foreground hidden truncate sm:inline">
                                        📍 {row.location}
                                    </span>
                                )}
                                {row.badge && (
                                    <span className="text-muted-foreground rounded border px-1.5 py-0.5 text-xs">
                                        {row.badge}
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}
