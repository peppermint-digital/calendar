/** Eine Zeitscheibe, wie `FreeSlotFinder::grid()` sie liefert. */
export type FinderSlot = {
    /** `09:00` — zum Anzeigen. */
    start: string;
    /** `09:30` — zum Anzeigen. */
    end: string;
    /** ISO-8601 — zum Weiterreichen an die Anlegen-Maske. */
    startsAt: string;
    endsAt: string;
    free: boolean;
    busyCount: number;
    busyUsers?: number[];
    total: number;
};

export type FinderDay = {
    /** `2026-10-19`. */
    date: string;
    weekend: boolean;
    today: boolean;
    slots: FinderSlot[];
};

export type SlotFinderLabels = {
    empty: string;
    free: string;
    /** 2 von 5 → „2 von 5 haben etwas vor". */
    busy: (busy: number, total: number) => string;
};

export const defaultSlotFinderLabels: SlotFinderLabels = {
    empty: 'Für diesen Zeitraum gibt es nichts zu zeigen.',
    free: 'Frei — hier anlegen',
    busy: (busy, total) => `${busy} von ${total} haben etwas vor`,
};

export type SlotFinderProps = {
    days: FinderDay[];
    /**
     * Ein freier Platz wurde gewählt.
     *
     * Ohne diesen Weg wäre die Terminfindung eine Auskunft, die man danach
     * abtippen muss — deshalb bekommt das Produkt die ISO-Zeiten und kann
     * damit direkt in seine Anlegen-Maske springen.
     */
    onPick: (slot: FinderSlot) => void;
    labels?: Partial<SlotFinderLabels>;
    className?: string;
};

/** Ein Tag, wie ihn jemand in Deutschland liest: „Mo., 19.10." */
function tagBeschriften(datum: string): string {
    return new Date(`${datum}T00:00:00`).toLocaleDateString('de-DE', {
        weekday: 'short',
        day: '2-digit',
        month: '2-digit',
    });
}

/**
 * Wann sind alle frei?
 *
 * ## Warum dieser Baustein im Paket liegt
 *
 * Weil es ihn dreimal gab. CRM und Verwaltung führten am 19.10.2026 eine
 * Zeile für Zeile IDENTISCHE Seite von 196 Zeilen — zweimal dieselbe Datei,
 * zweimal zu pflegen. Der Manager hatte eine dritte Fassung mit 506 Zeilen,
 * die dieselbe Frage als FullCalendar-Ansicht beantwortete.
 *
 * Der Nutzer hat von aussen gesehen, was dabei herauskommt: „jede Unterseite
 * von jedem Tool sieht doch immer noch verschieden aus" (#8307).
 *
 * ## Warum das Raster gewonnen hat und nicht die Kalenderansicht
 *
 * Beide zeigen dasselbe, aber sie beantworten verschiedene Fragen gut. Ein
 * Kalender beantwortet „was steht an"; hier lautet die Frage „wo ist eine
 * Lücke, in der niemand etwas vorhat". Dafür ist eine Matrix aus Tagen und
 * Scheiben die direktere Antwort: Man sucht die grünen Felder, statt
 * Zwischenräume zwischen Terminen abzuschätzen.
 *
 * ## Warum ein belegter Platz ein gesperrter Knopf ist
 *
 * Und kein ausgegrauter Text: Er trägt das Fähnchen „2 von 5 haben etwas vor".
 * Wer wissen will, warum eine Zeit nicht geht, erfährt es beim Überfahren,
 * ohne irgendwohin zu klicken.
 *
 * ## Wochenende
 *
 * Am Wochenende ist niemand „frei" — er hat nur nichts vor. Diesen
 * Unterschied trifft `FreeSlotFinder` schon in der Rechnung; hier wird er nur
 * gezeigt. Ohne ihn schlüge ein Planer den Sonntagmorgen als beste Zeit vor.
 */
export function SlotFinder({ days, onPick, labels, className = '' }: SlotFinderProps) {
    const t = { ...defaultSlotFinderLabels, ...labels };

    if (days.length === 0) {
        return <p className="text-muted-foreground text-sm">{t.empty}</p>;
    }

    return (
        <div className={`overflow-x-auto ${className}`} data-testid="slot-finder">
            {/*
                Ein Tag je Spalte, eine Scheibe je Zeile. Waagerecht rollbar,
                weil zwei Wochen nebeneinander auf einem Telefon nicht passen —
                und ein Raster, das die Seite breiter macht, verschiebt alles
                andere mit.
            */}
            <div className="flex gap-2" style={{ minWidth: `${days.length * 7}rem` }}>
                {days.map((tag) => (
                    <div key={tag.date} className="flex-1 space-y-1">
                        <div
                            className={`pb-1 text-center text-xs font-medium ${
                                tag.today ? 'text-primary' : tag.weekend ? 'text-muted-foreground' : ''
                            }`}
                        >
                            {tagBeschriften(tag.date)}
                        </div>

                        {tag.slots.map((scheibe) => (
                            <button
                                key={scheibe.startsAt}
                                type="button"
                                disabled={!scheibe.free}
                                title={scheibe.free ? t.free : t.busy(scheibe.busyCount, scheibe.total)}
                                onClick={() => onPick(scheibe)}
                                className={`w-full rounded px-1 py-1 text-[11px] transition ${
                                    scheibe.free
                                        ? 'bg-success-subtle text-success-subtle-foreground hover:bg-success hover:text-success-foreground'
                                        : 'cursor-not-allowed bg-muted text-muted-foreground'
                                }`}
                            >
                                {scheibe.start}
                                {!scheibe.free && scheibe.total > 1 && (
                                    <span className="ml-1 opacity-70">
                                        {scheibe.total - scheibe.busyCount}/{scheibe.total}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                ))}
            </div>
        </div>
    );
}
