import { useEffect, useRef, useState, type ReactNode } from 'react';

import { cn } from './ui/utils';

/**
 * Ein Wert, der angehakt werden kann.
 *
 * `value` ist Zahl ODER Zeichenkette, weil die vier Systeme beides führen:
 * Terminarten heissen `verwaltung_termin`, Projekte und Personen sind Zahlen.
 * Eine Vereinheitlichung auf Zeichenketten wäre bequemer und falsch — beim
 * Zurückgeben an das Produkt käme `"7"` statt `7` heraus, und ein
 * `includes(7)` schlägt dann still fehl.
 */
export type FilterOption = {
    value: string | number;
    label: string;
    /** Farbmarke links, wenn das Produkt eine kennt. */
    colour?: string | null;
};

/**
 * Eine Gruppe von Haken in der Tafel.
 *
 * `mode` ist der Teil, den man nicht raten darf:
 *
 * - `hide` — angehakt heisst **ausgeblendet**. So führt der Manager seine
 *   Terminarten und die fremden Kalender. Gespeichert wird, was jemand NICHT
 *   sehen will; eine neu angemeldete Quelle ist damit für alle sichtbar,
 *   statt unsichtbar zu sein, bis jeder sie einzeln einschaltet.
 * - `only` — angehakt heisst **nur diese**. So führt er Projekte, Personen
 *   und Ressourcen. Leer heisst „alle", nicht „keine".
 *
 * Die beiden sehen in der Tafel gleich aus und bedeuten das Gegenteil. Darum
 * steht es als Angabe an der Gruppe und nicht in einer Namenskonvention.
 */
export type FilterGroup = {
    key: string;
    label: string;
    mode: 'hide' | 'only';
    options: FilterOption[];
    selected: (string | number)[];
    onChange: (next: (string | number)[]) => void;
    /** Kleines Zeichen vor der Beschriftung — das Produkt bringt es mit. */
    icon?: ReactNode;
    /** Marke der aktiven Auswahl einfärben, etwa `bg-info-subtle text-info`. */
    badgeClass?: string;
};

/** Ein einzelner Schalter, der zu keiner Gruppe gehört. */
export type FilterToggle = {
    key: string;
    label: string;
    value: boolean;
    onChange: (next: boolean) => void;
    /** Überschrift, unter der mehrere Schalter zusammenstehen. */
    section?: string;
    /** Erklärung unter dem Abschnitt. */
    hint?: string;
};

export type CalendarFilterPanelLabels = {
    trigger: string;
    heading: string;
    reset: string;
    clearAll: string;
    removeOne: string;
};

const PANEL_DEFAULTS: CalendarFilterPanelLabels = {
    trigger: 'Filter',
    heading: 'Filter',
    reset: 'Alle zurücksetzen',
    clearAll: 'Alle löschen',
    removeOne: 'Filter entfernen',
};

export interface CalendarFilterPanelProps {
    groups?: FilterGroup[];
    toggles?: FilterToggle[];
    /** Alles zurücksetzen. Fehlt es, erscheint der Knopf nicht. */
    onReset?: () => void;
    /** Noch etwas ganz Eigenes, unten in der Tafel. */
    children?: ReactNode;
    labels?: Partial<CalendarFilterPanelLabels>;
    className?: string;
}

/**
 * Die Filtertafel: ein Knopf, dahinter alle Filter dieses Kalenders.
 *
 * ## Warum sie ins Paket gehört
 *
 * Sie stand am 16.10.2026 nur im Manager — 427 Zeilen, und die ausgereifteste
 * Filterung der vier Systeme: Marken für das Aktive, Haken je Gruppe, ein
 * Zähler am Knopf, „alles zurücksetzen". CRM, Verwaltung und Brain hatten
 * statt dessen eine Reihe Marken für die Terminarten und sonst nichts.
 *
 * Ich hatte das zuerst anders gelesen und die Markenreihe für das Gemeinsame
 * gehalten, die Tafel für Manager-Eigenes. Das war verkehrt: Die Tafel ist die
 * Form, die alle brauchen — „richtig filtern können" ist der Kern eines
 * geteilten Kalenders, nicht ein Zusatz des grössten Systems.
 *
 * ## Wie das Gemeinsame und das Eigene getrennt sind
 *
 * Nicht nach Gruppen, sondern nach ART: Das Paket bringt die Tafel mit —
 * Aussehen, Aufklappen, Marken, Zähler, Zurücksetzen. Was darin steht, gibt
 * das Produkt als Daten herein (`groups`, `toggles`). Terminarten und fremde
 * Kalender hat jedes System, Projekte nur drei, Ressourcen nur eines — und
 * alle sehen gleich aus, weil sie durch dieselbe Tafel gehen.
 *
 * Die Alternative wäre gewesen, Terminarten und Quellen fest einzubauen und
 * den Rest als Schlitz anzuhängen. Dann hätte das Eigene anders ausgesehen als
 * das Gemeinsame — genau der Unterschied, der heute im Manager zu sehen war.
 */
export function CalendarFilterPanel({
    groups = [],
    toggles = [],
    onReset,
    children,
    labels,
    className = '',
}: CalendarFilterPanelProps) {
    const beschriftung = { ...PANEL_DEFAULTS, ...labels };

    const [offen, setOffen] = useState(false);
    const tafelRef = useRef<HTMLDivElement | null>(null);
    const knopfRef = useRef<HTMLButtonElement | null>(null);

    /**
     * Klick daneben schliesst die Tafel.
     *
     * Der Knopf ist ausgenommen: Ohne das würde ein Klick darauf erst schliessen
     * (dieser Zuhörer) und dann wieder öffnen (der Knopf selbst) — die Tafel
     * bliebe offen und der Knopf wirkte kaputt.
     */
    useEffect(() => {
        if (! offen) return;

        const daneben = (ereignis: MouseEvent) => {
            const ziel = ereignis.target as Node;

            if (tafelRef.current?.contains(ziel) || knopfRef.current?.contains(ziel)) return;

            setOffen(false);
        };

        document.addEventListener('mousedown', daneben);

        return () => document.removeEventListener('mousedown', daneben);
    }, [offen]);

    /** Escape schliesst ebenfalls — eine Tafel ohne Tastaturweg ist eine Falle. */
    useEffect(() => {
        if (! offen) return;

        const taste = (ereignis: KeyboardEvent) => {
            if (ereignis.key === 'Escape') setOffen(false);
        };

        document.addEventListener('keydown', taste);

        return () => document.removeEventListener('keydown', taste);
    }, [offen]);

    /**
     * Wie viele Filter gerade greifen.
     *
     * Nur Gruppen und eingeschaltete Schalter — die Zahl soll beantworten
     * „warum sehe ich weniger als sonst". Ein ausgeschalteter Schalter zeigt
     * nichts weg.
     */
    const anzahl =
        groups.reduce((summe, gruppe) => summe + gruppe.selected.length, 0) +
        toggles.filter((schalter) => schalter.value).length;

    const umschalten = (gruppe: FilterGroup, wert: string | number) => {
        gruppe.onChange(
            gruppe.selected.includes(wert)
                ? gruppe.selected.filter((eintrag) => eintrag !== wert)
                : [...gruppe.selected, wert],
        );
    };

    /** Die Schalter nach ihrem Abschnitt gebündelt, Reihenfolge wie hereingegeben. */
    const abschnitte: { name: string | undefined; hint?: string; schalter: FilterToggle[] }[] = [];

    for (const schalter of toggles) {
        const letzter = abschnitte[abschnitte.length - 1];

        if (letzter !== undefined && letzter.name === schalter.section) {
            letzter.schalter.push(schalter);
            letzter.hint = letzter.hint ?? schalter.hint;

            continue;
        }

        abschnitte.push({ name: schalter.section, hint: schalter.hint, schalter: [schalter] });
    }

    /**
     * Eine Gruppe ohne Auswahlmoeglichkeiten bleibt WEG.
     *
     * Sonst steht eine Ueberschrift mit einem Trennstrich darunter und sonst
     * nichts — ein Versprechen, das die Tafel nicht einloest. Am 19.10.2026 im
     * Browser genau so gesehen: „Projekt" als leere Zeile, weil die angemeldete
     * Person an keinem Projekt haengt (#8307).
     *
     * Die Entscheidung gehoert hierher und nicht ins Produkt: Jedes Produkt
     * muesste sie sonst selbst treffen, und drei von vier wuerden es
     * vergessen.
     */
    const sichtbareGruppen = groups.filter((gruppe) => gruppe.options.length > 0);

    if (sichtbareGruppen.length === 0 && toggles.length === 0 && ! children) {
        return null;
    }

    return (
        <div data-slot="calendar-filter-panel" className={cn('flex items-center gap-2', className)}>
            {/*
                Die Marken des Aktiven stehen NEBEN dem Knopf, nicht nur in der
                Tafel: Sonst muss man sie aufklappen, um zu sehen, warum der
                Kalender leerer ist als erwartet. Auf dem Telefon ausgeblendet,
                dort führt der Weg über die Tafel.
            */}
            {anzahl > 0 && (
                <div className="hidden items-center gap-1 md:flex">
                    {groups.flatMap((gruppe) =>
                        gruppe.selected.map((wert) => {
                            const eintrag = gruppe.options.find((option) => option.value === wert);

                            if (eintrag === undefined) return null;

                            return (
                                <span
                                    key={`${gruppe.key}-${wert}`}
                                    data-slot="calendar-filter-badge"
                                    className={cn(
                                        'inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs',
                                        gruppe.badgeClass,
                                    )}
                                >
                                    {gruppe.icon}
                                    {eintrag.label}
                                    <button
                                        type="button"
                                        aria-label={beschriftung.removeOne}
                                        className="opacity-60 hover:opacity-100"
                                        onClick={() => umschalten(gruppe, wert)}
                                    >
                                        ×
                                    </button>
                                </span>
                            );
                        }),
                    )}

                    {onReset !== undefined && (
                        <button
                            type="button"
                            className="px-1 text-xs text-muted-foreground hover:text-destructive"
                            onClick={onReset}
                        >
                            {beschriftung.clearAll}
                        </button>
                    )}
                </div>
            )}

            <div className="relative">
                <button
                    ref={knopfRef}
                    type="button"
                    aria-expanded={offen}
                    onClick={() => setOffen((vorher) => ! vorher)}
                    className="inline-flex h-9 items-center gap-2 rounded-md border bg-background px-3 text-sm hover:bg-muted"
                >
                    {beschriftung.trigger}
                    {anzahl > 0 && (
                        <span className="rounded-md bg-primary px-1.5 py-0.5 text-xs text-primary-foreground">
                            {anzahl}
                        </span>
                    )}
                </button>

                {offen && (
                    <div
                        ref={tafelRef}
                        data-slot="calendar-filter-panel-body"
                        /*
                            Immer LINKSBUENDIG zum Knopf, nicht rechtsbuendig
                            ab `md`.

                            Die rechtsbuendige Fassung stammte aus dem Manager,
                            wo die Tafel oben RECHTS in der Kopfzeile sass —
                            dort war es richtig. In der Filterleiste steht der
                            Knopf aber links, und `right-0` schiebt die Tafel
                            von dort nach links aus dem Bild: Im Browser
                            gesehen, die Beschriftungen waren abgeschnitten
                            (16.10.2026).

                            `max-w-[calc(100vw-2rem)]` dazu, damit sie auf
                            einem schmalen Telefon nicht rechts hinausragt.
                        */
                        className="absolute top-full left-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-lg border bg-background shadow-lg"
                    >
                        <div className="max-h-[70vh] space-y-4 overflow-y-auto p-4">
                            <div className="flex items-center justify-between">
                                <h4 className="font-medium leading-none">{beschriftung.heading}</h4>
                                {onReset !== undefined && (
                                    <button
                                        type="button"
                                        className="text-xs text-muted-foreground hover:text-foreground"
                                        onClick={onReset}
                                    >
                                        {beschriftung.reset}
                                    </button>
                                )}
                            </div>

                            {sichtbareGruppen.map((gruppe) => (
                                <div key={gruppe.key} className="space-y-3 border-t pt-3">
                                    <div className="flex items-center gap-2">
                                        {gruppe.icon}
                                        <span className="text-sm font-medium">{gruppe.label}</span>
                                    </div>

                                    {/*
                                        Hoehe begrenzt und rollbar: Eine Liste mit
                                        achtzig Projekten schiebt sonst alles
                                        darunter aus der Tafel heraus.
                                    */}
                                    <div className="grid max-h-32 grid-cols-1 gap-2 overflow-y-auto">
                                        {gruppe.options.map((option) => (
                                            <label
                                                key={`${gruppe.key}-${option.value}`}
                                                className="flex cursor-pointer items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="h-4 w-4 rounded border"
                                                    checked={gruppe.selected.includes(option.value)}
                                                    onChange={() => umschalten(gruppe, option.value)}
                                                />
                                                {option.colour !== null && option.colour !== undefined && (
                                                    <span
                                                        className="h-2.5 w-2.5 shrink-0 rounded-full"
                                                        style={{ backgroundColor: option.colour }}
                                                    />
                                                )}
                                                <span className="flex-1">{option.label}</span>
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            ))}

                            {abschnitte.map((abschnitt, stelle) => (
                                <div key={abschnitt.name ?? `abschnitt-${stelle}`} className="space-y-3 border-t pt-3">
                                    {abschnitt.name !== undefined && (
                                        <span className="text-sm font-medium">{abschnitt.name}</span>
                                    )}

                                    <div className="grid grid-cols-1 gap-2">
                                        {abschnitt.schalter.map((schalter) => (
                                            <label
                                                key={schalter.key}
                                                className="flex cursor-pointer items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="h-4 w-4 rounded border"
                                                    checked={schalter.value}
                                                    onChange={(ereignis) => schalter.onChange(ereignis.target.checked)}
                                                />
                                                <span className="flex-1">{schalter.label}</span>
                                            </label>
                                        ))}
                                    </div>

                                    {abschnitt.hint !== undefined && (
                                        <p className="text-xs text-muted-foreground">{abschnitt.hint}</p>
                                    )}
                                </div>
                            ))}

                            {children}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
