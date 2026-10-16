import type { ReactNode } from 'react';

import type { EventKind } from './types';

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
export function isHidden(hidden: readonly string[], kind: string, source?: string | null): boolean {
    if (source !== undefined && source !== null && source !== '') {
        return hidden.includes(sourceKey(source));
    }

    return hidden.includes(kind);
}

/** Einen Schlüssel umschalten und die neue Liste zurückgeben. */
export function toggleHidden(hidden: readonly string[], key: string): string[] {
    return hidden.includes(key) ? hidden.filter((eintrag) => eintrag !== key) : [...hidden, key];
}

/** Ein Eintrag der Leiste — eine Terminart oder eine Fremdquelle. */
export type CalendarFilterEntry = {
    key: string;
    label: string;
    /** Die Farbe, in der das Raster ihn zeichnet. */
    colour?: string | null;
};

export interface CalendarFiltersProps {
    /** Die Terminarten dieses Systems. */
    kinds?: EventKind[];
    /** Fremde Kalender. Ihre Schlüssel bekommen das Vorzeichen selbst. */
    sources?: { key: string; label: string; colour?: string | null }[];
    /**
     * Die Farbe einer Art, wenn sie nicht am Eintrag steht.
     *
     * Produkteigen, weil die Farbtafel im Produkt liegt: Der Manager hält sie
     * im Frontend, die Verwaltung im Controller. Das Paket zeichnet die Marke,
     * kennt aber keine Farbe.
     */
    colourOf?: (key: string) => string | null | undefined;

    /** Welche Schlüssel NICHT angezeigt werden. Leer heisst: alles sichtbar. */
    hidden: readonly string[];
    /**
     * Ein Schlüssel wurde umgeschaltet.
     *
     * Die neue Liste kommt mit, nicht nur der Schlüssel: Wer sie selbst
     * ausrechnet, rechnet sie in vier Systemen aus — und im dritten mit einem
     * Updater, der zweimal läuft und den Nebeneffekt doppelt auslöst.
     */
    onToggle: (key: string, hidden: string[]) => void;

    /** Was links vor den Marken steht. */
    label?: string;
    /** Produkteigene Gruppen — Projekte, Ressourcen, Kategorien. */
    children?: ReactNode;
    className?: string;
}

/**
 * Die Filterleiste: welche Arten und welche fremden Kalender man sehen will.
 *
 * ## Warum sie ins Paket gehört
 *
 * Sie stand am 16.10.2026 in allen vier Systemen einzeln — und sah vier Mal
 * anders aus: Die Verwaltung zeichnete Farbpunkte und durchgestrichene
 * Schrift, das Brain graue Knöpfe mit halber Deckkraft, das CRM eine dritte
 * Fassung, der Manager eine 469-zeilige Tafel. Wer eine Leiste viermal baut,
 * hat vier Bedienoberflächen, auch wenn darunter dasselbe Raster liegt.
 *
 * Gemeinsam ist allen vier genau das, was jedes System hat: **Terminarten und
 * Fremdquellen**. Deshalb steht genau das hier.
 *
 * ## Was NICHT hierher gehört
 *
 * Projekte, Ressourcen, Ticketkategorien, „nur wo ich Projektleitung bin" —
 * das sind Begriffe eines Produkts, nicht des Kalenders. Sie kommen als
 * `children` daneben. Die Grenze ist dieselbe wie bei der Werkzeugleiste: Was
 * alle haben, liegt im Paket; was einer hat, bleibt bei ihm und steht sichtbar
 * getrennt.
 *
 * ## Die Marke zeigt die Farbe des Rasters
 *
 * Aus der Verwaltung übernommen, weil es die einzige Fassung war, die etwas
 * sagt: Der Punkt nennt die Farbe, in der dieser Eintrag im Raster steht.
 * Ohne ihn muss man raten, welcher Schalter den blauen Block ausmacht.
 */
export function CalendarFilters({
    kinds = [],
    sources = [],
    colourOf,
    hidden,
    onToggle,
    label = 'Anzeigen:',
    children,
    className = '',
}: CalendarFiltersProps) {
    const eintraege: CalendarFilterEntry[] = [
        ...kinds.map((art) => ({
            key: art.key,
            label: art.label,
            colour: art.colour ?? colourOf?.(art.key) ?? null,
        })),
        ...sources.map((quelle) => ({
            key: sourceKey(quelle.key),
            label: quelle.label,
            colour: quelle.colour ?? colourOf?.(sourceKey(quelle.key)) ?? null,
        })),
    ];

    if (eintraege.length === 0 && ! children) {
        return null;
    }

    return (
        <div
            data-slot="calendar-filters"
            className={`flex flex-wrap items-center gap-2 ${className}`.trim()}
        >
            {eintraege.length > 0 && <span className="text-xs text-muted-foreground">{label}</span>}

            {eintraege.map((eintrag) => {
                const aus = hidden.includes(eintrag.key);

                return (
                    <button
                        key={eintrag.key}
                        type="button"
                        onClick={() => onToggle(eintrag.key, toggleHidden(hidden, eintrag.key))}
                        // `aria-pressed` beschreibt den SICHTBAREN Zustand:
                        // Der Knopf ist gedrueckt, wenn der Eintrag gezeigt
                        // wird. Umgekehrt herum liest eine Sprachausgabe das
                        // Gegenteil von dem vor, was auf dem Schirm steht.
                        aria-pressed={! aus}
                        className={`inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs transition ${
                            aus ? 'bg-muted text-muted-foreground line-through opacity-60' : 'bg-background'
                        }`}
                    >
                        {eintrag.colour !== null && eintrag.colour !== undefined && (
                            <span
                                data-slot="calendar-filter-dot"
                                className="h-2.5 w-2.5 shrink-0 rounded-full"
                                style={{
                                    backgroundColor: aus ? 'transparent' : eintrag.colour,
                                    border: `1px solid ${eintrag.colour}`,
                                }}
                            />
                        )}
                        {eintrag.label}
                    </button>
                );
            })}

            {children}
        </div>
    );
}
