import { Fragment, useEffect, useState, type ReactNode } from 'react';
import type { CalendarViewName } from './CalendarGrid';

export type CalendarToolbarButton = {
    /** Hervorgehoben darstellen — der Zustand ist an. */
    active?: boolean;
    onClick: () => void;
    title?: string;
    children: ReactNode;
};

export type CalendarToolbarLabels = {
    today: string;
    previous: string;
    next: string;
    fullDay: string;
    fullDayHint: string;
    weekends: string;
    weekendsHint: string;
    views: Partial<Record<CalendarViewName, string>>;
};

export const defaultToolbarLabels: CalendarToolbarLabels = {
    today: 'Heute',
    previous: '←',
    next: '→',
    fullDay: '24 h',
    fullDayHint: 'Von 0 bis 24 Uhr statt 7 bis 23',
    weekends: 'Wochenende',
    weekendsHint: 'Samstag und Sonntag ein- oder ausblenden',
    views: {
        dayGridMonth: 'Monat',
        timeGridWeek: 'Woche',
        timeGridDay: 'Tag',
        listWeek: 'Liste',
    },
};

export type CalendarToolbarProps = {
    /**
     * Zeichnet einen Knopf.
     *
     * Das Paket bringt keine Bedienelemente mit: Jede Anwendung hat ihre
     * eigenen, und ein zweiter Knopf-Stil mitten in einer fertigen Oberflaeche
     * faellt sofort auf. Der Schlitz kostet drei Zeilen je Anwendung und
     * erspart, dass die Leiste dreimal gebaut wird.
     */
    renderButton: (button: CalendarToolbarButton) => ReactNode;

    /** Welche Ansichten zur Wahl stehen. Leer heisst: keine Umschaltung. */
    views?: CalendarViewName[];
    view?: CalendarViewName;
    onViewChange?: (view: CalendarViewName) => void;

    onPrevious?: () => void;
    onToday?: () => void;
    onNext?: () => void;

    /** Beschriftung des gezeigten Zeitraums, etwa „14. – 20. Sept. 2026". */
    range?: ReactNode;

    fullDay?: boolean;
    onFullDayChange?: (next: boolean) => void;

    weekends?: boolean;
    onWeekendsChange?: (next: boolean) => void;

    /** Was diese Seite zusaetzlich braucht — steht rechts neben den Schaltern. */
    children?: ReactNode;

    labels?: Partial<CalendarToolbarLabels>;
    className?: string;
};

/**
 * Die Leiste ueber dem Kalender: blaettern, Ansicht waehlen, 24 Stunden,
 * Wochenende.
 *
 * Sie stand bisher in jeder Seite einzeln — und deshalb hatte der
 * Aufgaben-Kalender andere Schalter als der Kalender selbst, obwohl beide
 * dasselbe Raster zeigten. Wer eine Leiste dreimal baut, hat drei
 * Bedienoberflaechen, auch wenn darunter eine Komponente liegt.
 *
 * Jeder Teil ist abwaehlbar: Eine Seite, die nur Wochen zeigt, laesst `views`
 * weg und bekommt keine Umschaltung — statt eine eigene Leiste zu bauen.
 */
/**
 * Dieselbe Grenze wie Tailwinds `sm`. Gemessen ueber `innerWidth` und nicht
 * ueber `matchMedia`, weil `CalendarGrid` es genauso macht — und weil jsdom
 * `matchMedia` nicht mitbringt, ein Test also erst einen Ersatz bauen muesste.
 */
const BREIT_AB = 640;

export function CalendarToolbar({
    renderButton,
    views = [],
    view,
    onViewChange,
    onPrevious,
    onToday,
    onNext,
    range,
    fullDay,
    onFullDayChange,
    weekends,
    onWeekendsChange,
    children,
    labels,
    className = '',
}: CalendarToolbarProps) {
    const text = { ...defaultToolbarLabels, ...labels, views: { ...defaultToolbarLabels.views, ...labels?.views } };

    /*
     * Die Schalter werden EINMAL gerendert — entweder in der Leiste oder im
     * Ueberlauf, nie beides.
     *
     * Der naheliegende Weg waere, beide Fassungen zu zeichnen und eine per
     * `sm:hidden` auszublenden. Das waere falsch: Zwei bedienbare Knoepfe mit
     * demselben Namen stehen dann wirklich im Baum — ein Screenreader liest
     * sie doppelt vor, und jede Suche nach „der 24-h-Knopf" findet zwei. Das
     * braeche auch die Tests der vier Produkte, die dieses Paket einbinden.
     */
    const [breit, setBreit] = useState(() =>
        typeof window === 'undefined' ? true : window.innerWidth >= BREIT_AB,
    );

    useEffect(() => {
        const messen = () => setBreit(window.innerWidth >= BREIT_AB);

        window.addEventListener('resize', messen);
        messen();

        return () => window.removeEventListener('resize', messen);
    }, []);

    const schmal = ! breit;

    const schalter = [
        onFullDayChange && {
            schluessel: 'fullDay',
            active: fullDay === true,
            onClick: () => onFullDayChange(fullDay !== true),
            title: text.fullDayHint,
            children: text.fullDay,
        },
        onWeekendsChange && {
            schluessel: 'weekends',
            active: weekends === true,
            onClick: () => onWeekendsChange(weekends !== true),
            title: text.weekendsHint,
            children: text.weekends,
        },
    ]
        .filter((eintrag): eintrag is Exclude<typeof eintrag, undefined> => eintrag !== undefined)
        .map(({ schluessel, ...knopf }) => <Fragment key={schluessel}>{renderButton(knopf)}</Fragment>);

    return (
        <div className={`flex flex-wrap items-center gap-2 ${className}`.trim()}>
            {onPrevious && renderButton({ onClick: onPrevious, children: text.previous, title: 'Zurück' })}
            {onToday && renderButton({ onClick: onToday, children: text.today })}
            {onNext && renderButton({ onClick: onNext, children: text.next, title: 'Weiter' })}

            {range !== undefined && <span className="text-muted-foreground text-sm">{range}</span>}

            {views.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {/*
                        Der Schluessel gehoert hierher, nicht in `renderButton`:
                        Die Liste baut diese Leiste, also muss auch sie sagen,
                        welcher Knopf welcher ist. Vorher kam er — wenn
                        ueberhaupt — aus der Beschriftung, die das Produkt
                        setzt. Wo das Produkt ihn vergass, meldete React
                        „unique key prop" und durfte beim Wechsel der
                        Ansichtsliste Knoepfe verwechseln.
                    */}
                    {views.map((entry) => (
                        <Fragment key={entry}>
                            {renderButton({
                                active: entry === view,
                                onClick: () => onViewChange?.(entry),
                                children: text.views[entry] ?? entry,
                            })}
                        </Fragment>
                    ))}
                </div>
            )}

            {/*
                Die beiden Ansichts-Schalter: ab `sm` in der Leiste, darunter
                im Ueberlauf (#8599, 22.10.2026).

                Gemessen bei 390 px Breite: Die Leiste ist dort 358 px breit,
                und ihr Inhalt summiert sich auf 688 px — Navigation 259,
                Ansichten 258, Schalter 171. Das sind drei Reihen und 112 px,
                bevor der erste Termin zu sehen ist. Zwei davon zusammenlegen
                geht nicht: 258 + 171 passen auch nicht nebeneinander.

                Es muss also etwas aus dem Fluss, und weggeraeumt wird das
                SELTENERE. „Monat / Woche / Tag" waehlt man staendig, „24 h"
                und „Wochenende" stellt man einmal ein. Umgekehrt haette man
                das Haeufige hinter einen Klick gelegt, um das Seltene zu
                zeigen.

                `<details>` und kein eigenes Menue: Es oeffnet und schliesst
                von selbst, ist mit der Tastatur bedienbar und kostet keine
                Abhaengigkeit. Dieses Paket haelt sein Frontend bewusst
                abhaengigkeitsarm.
            */}
            <div className="ml-auto flex flex-wrap gap-1">
                {! schmal && schalter}

                {schmal && schalter.length > 0 && (
                    <details className="relative">
                        <summary
                            aria-label="Weitere Ansichtsoptionen"
                            className={[
                                'flex h-8 w-8 cursor-pointer list-none items-center justify-center rounded-md border',
                                'text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                                '[&::-webkit-details-marker]:hidden',
                            ].join(' ')}
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className="h-4 w-4" aria-hidden="true">
                                <circle cx="5" cy="12" r="1.6" /><circle cx="12" cy="12" r="1.6" /><circle cx="19" cy="12" r="1.6" />
                            </svg>
                        </summary>

                        <div className="bg-popover absolute right-0 z-20 mt-1 flex flex-col gap-1 rounded-md border p-1 shadow-md">
                            {schalter}
                        </div>
                    </details>
                )}

                {children}
            </div>
        </div>
    );
}
