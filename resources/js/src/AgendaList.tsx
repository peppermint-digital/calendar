import { Fragment, type ReactNode } from 'react';

export type AgendaRow<T = unknown> = {
    key: string;
    /** Was das Produkt an dieser Zeile haengen hat — fuer eigene Bedienelemente. */
    data?: T;
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
    /** Abgeblendet — vorbei, abgesagt, erledigt. Was es heisst, weiss das Produkt. */
    dimmed?: boolean;
};

export type AgendaListProps<T = unknown> = {
    rows: AgendaRow<T>[];
    /** Beschriftung eines Tages — das Produkt kennt seine Sprache und Zeitzone. */
    dayLabel: (date: string) => string;
    onSelect?: (row: AgendaRow<T>) => void;
    /**
     * Bedienelemente am rechten Rand einer Zeile.
     *
     * Die kennt nur das Produkt: „zur Aufgabe springen" gibt es nur dort, wo es
     * Aufgaben gibt. Ohne diesen Weg muesste die Liste jedes Produkt kennen —
     * oder das Produkt verlaere beim Umstieg still eine Faehigkeit.
     */
    actions?: (row: AgendaRow<T>) => ReactNode;
    /**
     * Legt sich um eine ganze Zeile — fuer ein Kontextmenue oder ein Ziehziel.
     *
     * `actions` reicht dafuer nicht: Ein Rechtsklickmenue umschliesst die Zeile,
     * es steht nicht daneben. Ohne diesen Weg muesste ein Produkt beim Umstieg
     * auf die gemeinsame Liste seine Zeilenbefehle aufgeben.
     */
    rowWrapper?: (row: AgendaRow<T>, children: ReactNode) => ReactNode;
    /** Was statt der Liste steht, wenn nichts ansteht. */
    empty?: ReactNode;
    allDayLabel?: string;
};

/**
 * Nach Tag und Uhrzeit sortiert, dann nach Tag gruppiert.
 *
 * Die Sortierung passiert hier und nicht beim Produkt: Eine Agenda, deren
 * Reihenfolge von der Laune der Abfrage abhaengt, liest sich wie ein Fehler —
 * und ganztaegige Termine haben keine Uhrzeit, an der sich das entscheiden
 * liesse. Sie stehen vor den Terminen mit Uhrzeit.
 */
export function groupByDay<T>(rows: AgendaRow<T>[]): Record<string, AgendaRow<T>[]> {
    const sorted = [...rows].sort((a, b) =>
        `${a.date} ${a.start ?? '00:00'}`.localeCompare(`${b.date} ${b.start ?? '00:00'}`),
    );

    return sorted.reduce<Record<string, AgendaRow<T>[]>>((carry, row) => {
        (carry[row.date] ??= []).push(row);

        return carry;
    }, {});
}

/**
 * Die Agenda: was als Naechstes kommt, nach Tagen gruppiert.
 *
 * Uebernommen aus AI Brain — beide Produkte hatten eine, diese sah besser aus.
 * Bewusst ohne Inertia, ohne Layout und ohne Komponentensammlung: Was
 * hereinkommt, sind Zeilen; was herausgeht, ist eine Liste. Die Seite drumherum
 * — Kopfzeile, Zeitraumwahl, ueberfaellige Aufgaben — bleibt beim Produkt, denn
 * die kennt jedes anders.
 */
export function AgendaList<T = unknown>({
    rows,
    dayLabel,
    onSelect,
    actions,
    rowWrapper,
    empty = null,
    allDayLabel = 'ganztägig',
}: AgendaListProps<T>) {
    if (rows.length === 0) {
        return <>{empty}</>;
    }

    const byDay = groupByDay(rows);

    return (
        <div className="flex flex-col gap-4">
            {Object.entries(byDay).map(([date, entries]) => (
                <div key={date} className="rounded-lg border">
                    <div className="bg-muted/40 sticky top-0 z-10 border-b px-3 py-2 text-sm font-medium">
                        {dayLabel(date)}
                    </div>
                    <div className="flex flex-col divide-y">
                        {entries.map((row) => {
                            const line = (
                            <div
                                key={row.key}
                                className={
                                    'flex flex-wrap items-center gap-3 px-3 py-2 text-sm' +
                                    (onSelect ? ' hover:bg-muted/50 cursor-pointer' : '') +
                                    (row.dimmed ? ' opacity-50' : '')
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
                                {actions && <div className="ml-auto flex gap-1">{actions(row)}</div>}
                            </div>
                            );

                            return rowWrapper ? (
                                <Fragment key={row.key}>{rowWrapper(row, line)}</Fragment>
                            ) : (
                                line
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}
