/** Eine Zeile des Berichts, wie der `CategoryReportAssembler` sie liefert. */
export type CategoryReportRow = {
    label: string | null;
    count: number;
    minutes: number;
    hours: number;
};

export type CategoryReportData = {
    categories: CategoryReportRow[];
    /**
     * Die Aufschlüsselung dessen, was ohne Kategorie bleibt — ABGELEITET aus
     * dem Subjekt (Projekt, Ticketbereich), nicht gewählt.
     *
     * Eigene Liste und nicht zwischen den Kategorien: Gewählte und abgeleitete
     * Zahlen, die in einer Tabelle gleich aussehen, sind der Anfang eines
     * lügenden Berichts.
     */
    groups?: CategoryReportRow[];
    /**
     * Nach Terminart, mit den Kategorien je Art darunter.
     *
     * Eigener Abschnitt und nicht neben den Kategorien, weil hier ANDERS
     * gezaehlt wird: Ein Termin hat genau eine Art, aber null bis viele
     * Kategorien. Ueber die Arten summiert kommt die Gesamtzeit heraus,
     * ueber die Kategorien summiert nicht.
     */
    kinds?: CategoryReportKindRow[];
    uncategorized: CategoryReportRow;
    total: CategoryReportRow;
};

/** Eine Terminart mit ihren Kategorien darunter. */
export type CategoryReportKindRow = CategoryReportRow & {
    categories: CategoryReportRow[];
};

export type CategoryReportLabels = {
    empty: string;
    uncategorized: string;
    groupsTitle: string;
    groupsHint: string;
    kindsTitle: string;
    kindsHint: string;
    totalTitle: string;
    multipleHint: string;
    /** 135 → „2:15 h". */
    duration: (minuten: number) => string;
};

/** 135 → „2:15 h". Stunden und Minuten, wie man sie ausspricht. */
export function formatDuration(minuten: number): string {
    const stunden = Math.floor(minuten / 60);
    const rest = minuten % 60;

    if (stunden === 0) return `${rest} min`;
    if (rest === 0) return `${stunden} h`;

    return `${stunden}:${String(rest).padStart(2, '0')} h`;
}

export const defaultCategoryReportLabels: CategoryReportLabels = {
    empty: 'In diesem Zeitraum trägt kein Termin eine Kategorie.',
    uncategorized: 'Ohne Kategorie',
    groupsTitle: 'Davon ohne Kategorie, nach Herkunft',
    groupsHint: 'Abgeleitet aus dem Vorgang, an dem der Termin hängt — nicht von Hand gewählt.',
    kindsTitle: 'Nach Terminart',
    kindsHint: 'Jeder Termin zählt bei genau einer Art — die Summe hier ist die Gesamtzeit.',
    totalTitle: 'Gesamtzeit',
    multipleHint:
        'Ein Termin mit mehreren Kategorien zählt bei jeder voll — die Summe der Kategorien ist deshalb größer als die Gesamtzeit.',
    duration: formatDuration,
};

export type CategoryReportProps = {
    report: CategoryReportData;
    labels?: Partial<CategoryReportLabels>;
};

/**
 * Wofür ist die Zeit draufgegangen.
 *
 * ## Warum die Darstellung im Paket liegt
 *
 * Die Rechnung lag hier schon (`CategoryReportAssembler`, v0.59.0), die
 * Darstellung nicht — sie entstand am 18.10.2026 im AI Brain. Beim Nachziehen
 * in CRM und Verwaltung hätte sie ein zweites und drittes Mal gebaut werden
 * müssen, und der Manager führt bereits eine vierte, eigene Fassung von vorher.
 *
 * Vier Darstellungen derselben Zahlen sind vier Gelegenheiten, dieselbe Frage
 * verschieden zu beantworten — und bei dieser Darstellung steckt die Antwort
 * nicht in den Zahlen, sondern im Maßstab (siehe unten). Deshalb einmal hier.
 *
 * Der Rahmen bleibt beim Produkt: Überschrift, Zeitraumwahl und Layout gibt
 * jede Anwendung selbst, weil sie dort zu ihren anderen Seiten passen müssen.
 *
 * ## Warum der Balken die längste Kategorie als Maßstab nimmt
 *
 * Nicht die Gesamtzeit: Ein Termin mit zwei Kategorien zählt bei beiden voll,
 * die Summe der Kategorien kann also größer sein als die Gesamtzeit. Ein
 * Balken gegen die Gesamtzeit wäre dann länger als die Spur — und die Frage
 * ist ohnehin „was war am meisten", nicht „welcher Anteil".
 *
 * Der Hinweis darunter gehört dazu, sonst liest man die Summe der Kategorien
 * als Gesamtzeit und sucht einen Rechenfehler, wo keiner ist.
 */
export function CategoryReport({ report, labels }: CategoryReportProps) {
    const t = { ...defaultCategoryReportLabels, ...labels };

    const groesste = Math.max(1, ...report.categories.map((zeile) => zeile.minutes));
    const summe = report.categories.reduce((gesamt, zeile) => gesamt + zeile.minutes, 0);

    return (
        <div className="space-y-4" data-testid="category-report">
            {/*
                Die Gesamtzeit zuerst, und gross.

                Sie stand bisher in drei von vier Produkten klein in der
                Unterzeile der Ueberschrift und im vierten (Manager) gross in
                einer eigenen Karte. Hier ist sie EINMAL, damit die Seite
                ueberall dieselbe Antwort an derselben Stelle gibt — und weil
                es die Zahl ist, wegen der jemand die Seite aufmacht.
            */}
            <div data-testid="category-report-total">
                <p className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{t.totalTitle}</p>
                <p className="text-3xl font-bold tabular-nums">{t.duration(report.total.minutes)}</p>
                <p className="text-muted-foreground text-sm">
                    {report.total.count} {report.total.count === 1 ? 'Termin' : 'Termine'}
                </p>
            </div>

            {report.categories.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t.empty}</p>
            ) : (
                report.categories.map((zeile) => (
                    <div key={zeile.label ?? 'ohne'} className="space-y-1">
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="font-medium">{zeile.label}</span>
                            <span className="text-muted-foreground tabular-nums">
                                {t.duration(zeile.minutes)} · {zeile.count}×
                            </span>
                        </div>
                        <div className="bg-muted h-2 overflow-hidden rounded">
                            <div
                                className="bg-primary h-full"
                                style={{ width: `${Math.round((zeile.minutes / groesste) * 100)}%` }}
                            />
                        </div>
                    </div>
                ))
            )}

            {/*
                Die Aufschlüsselung der Sammelzeile. Sie steht UNTER „ohne
                Kategorie" und mit eigener Überschrift, weil sie etwas anderes
                ist: abgeleitet aus dem Vorgang, nicht gewählt. Ein Zeitblock
                etwa trägt bewusst keine Kategorie — er zeigt auf eine
                Aufgabe, und die trägt den Zweck präziser. Seine Zeit ist
                trotzdem nicht nichts: im Manager 40 Prozent des Kalenders.

                Gedämpft gesetzt, damit sie nicht wie eine Kategorie gelesen
                wird.
            */}
            {report.groups !== undefined && report.groups.length > 0 && (
                <div className="border-t pt-3">
                    <p className="text-xs font-medium text-muted-foreground">{t.groupsTitle}</p>
                    <p className="text-muted-foreground mb-2 text-xs">{t.groupsHint}</p>

                    <div className="space-y-1">
                        {report.groups.map((zeile) => (
                            <div
                                key={zeile.label ?? 'ohne'}
                                className="text-muted-foreground flex items-baseline justify-between gap-3 text-sm"
                            >
                                <span>{zeile.label}</span>
                                <span className="tabular-nums">
                                    {t.duration(zeile.minutes)} · {zeile.count}×
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {report.uncategorized.count > 0 && (
                <div className="border-t pt-3 text-sm">
                    <div className="flex items-baseline justify-between gap-3">
                        <span className="text-muted-foreground">{t.uncategorized}</span>
                        <span className="text-muted-foreground tabular-nums">
                            {t.duration(report.uncategorized.minutes)} · {report.uncategorized.count}×
                        </span>
                    </div>
                </div>
            )}

            {/*
                Nach Terminart. Steht UNTER den Kategorien, weil es die
                groebere Frage ist: erst „wofuer", dann „was fuer Eintraege".

                Die Kategorien je Art stehen eingerueckt darunter und nicht als
                eigene Tabelle — sonst liest man sie als zweite, widersprechende
                Kategorienliste.
            */}
            {report.kinds !== undefined && report.kinds.length > 0 && (
                <div className="border-t pt-3" data-testid="category-report-kinds">
                    <p className="text-xs font-medium text-muted-foreground">{t.kindsTitle}</p>
                    <p className="text-muted-foreground mb-2 text-xs">{t.kindsHint}</p>

                    <div className="space-y-2">
                        {report.kinds.map((art) => (
                            <div key={art.label ?? 'ohne'}>
                                <div className="flex items-baseline justify-between gap-3 text-sm">
                                    <span className="font-medium">{art.label}</span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {t.duration(art.minutes)} · {art.count}×
                                    </span>
                                </div>

                                {art.categories.length > 0 && (
                                    <div className="mt-0.5 space-y-0.5 border-l pl-3">
                                        {art.categories.map((zeile) => (
                                            <div
                                                key={zeile.label ?? 'ohne'}
                                                className="text-muted-foreground flex items-baseline justify-between gap-3 text-xs"
                                            >
                                                <span>{zeile.label}</span>
                                                <span className="tabular-nums">
                                                    {t.duration(zeile.minutes)} · {zeile.count}×
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {summe > report.total.minutes && (
                <p className="text-muted-foreground text-xs">{t.multipleHint}</p>
            )}
        </div>
    );
}
