import type { ReactNode } from 'react';
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

    return (
        <div className={`flex flex-wrap items-center gap-2 ${className}`.trim()}>
            {onPrevious && renderButton({ onClick: onPrevious, children: text.previous, title: 'Zurück' })}
            {onToday && renderButton({ onClick: onToday, children: text.today })}
            {onNext && renderButton({ onClick: onNext, children: text.next, title: 'Weiter' })}

            {range !== undefined && <span className="text-muted-foreground text-sm">{range}</span>}

            {views.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {views.map((entry) =>
                        renderButton({
                            active: entry === view,
                            onClick: () => onViewChange?.(entry),
                            children: text.views[entry] ?? entry,
                        }),
                    )}
                </div>
            )}

            <div className="ml-auto flex flex-wrap gap-1">
                {onFullDayChange &&
                    renderButton({
                        active: fullDay === true,
                        onClick: () => onFullDayChange(fullDay !== true),
                        title: text.fullDayHint,
                        children: text.fullDay,
                    })}
                {onWeekendsChange &&
                    renderButton({
                        active: weekends === true,
                        onClick: () => onWeekendsChange(weekends !== true),
                        title: text.weekendsHint,
                        children: text.weekends,
                    })}
                {children}
            </div>
        </div>
    );
}
