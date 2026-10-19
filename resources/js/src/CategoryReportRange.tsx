import { type ReactNode, useState } from 'react';

import type { CalendarToolbarButton } from './CalendarToolbar';
import { DateField } from './DateField';

export type CategoryReportRangeLabels = {
    title: string;
    from: string;
    to: string;
    apply: string;
    currentMonth: string;
    previousMonth: string;
    nextMonth: string;
};

export const defaultCategoryReportRangeLabels: CategoryReportRangeLabels = {
    title: 'Zeitraum',
    from: 'Von',
    to: 'Bis',
    apply: 'Anwenden',
    currentMonth: 'Aktueller Monat',
    previousMonth: 'Vorheriger Monat',
    nextMonth: 'Nächster Monat',
};

export type CategoryReportRangeProps = {
    /** `YYYY-MM-DD`. */
    start: string;
    /** `YYYY-MM-DD`. */
    end: string;
    /**
     * Wird nur beim Anwenden gerufen, nicht bei jedem Tastendruck.
     *
     * Das Produkt schickt daraufhin eine Adresse mit dem Zeitraum darin — der
     * Zeitraum gehört in die ADRESSE und nicht in den Zustand, sonst lässt
     * sich eine Auswertung nicht verlinken und übersteht kein Neuladen.
     */
    onApply: (start: string, end: string) => void;
    /**
     * Die Knoepfe stellt das PRODUKT, nicht das Paket.
     *
     * Dasselbe Muster wie in {@link CalendarToolbar}: Ein Knopf traegt das
     * Aussehen der Anwendung, in der er steht, und ein Paket, das seine
     * eigenen mitbrächte, würde in vier Produkten vier Mal danebenliegen.
     */
    renderButton: (button: CalendarToolbarButton) => ReactNode;
    labels?: Partial<CategoryReportRangeLabels>;
    className?: string;
};

/** `2026-10-19` → `Date` in der lokalen Zeitzone, ohne UTC-Verschiebung. */
function alsDatum(iso: string): Date {
    const [jahr, monat, tag] = iso.split('-').map(Number);

    return new Date(jahr ?? 1970, (monat ?? 1) - 1, tag ?? 1);
}

function alsIso(datum: Date): string {
    const zweistellig = (zahl: number) => String(zahl).padStart(2, '0');

    return `${datum.getFullYear()}-${zweistellig(datum.getMonth() + 1)}-${zweistellig(datum.getDate())}`;
}

/**
 * Den Zeitraum einer Auswertung wählen.
 *
 * ## Warum dieser Baustein im Paket liegt
 *
 * Bis zum 19.10.2026 wählte ihn jedes Produkt anders: Der Manager hatte einen
 * Monatsschritt mit freien Von/Bis-Feldern, CRM, Verwaltung und AI Brain drei
 * Knöpfe für 30, 90 und 365 Tage. Dieselbe Seite, dieselbe Frage, drei
 * verschiedene Antworten — der Nutzer hat genau das von aussen gesehen
 * (#8307).
 *
 * Gewonnen hat die freie Wahl, weil sie die andere enthält: „Die letzten 90
 * Tage" lässt sich eintippen, „der September" aber nicht aus 30/90/365
 * zusammensetzen. Für den häufigsten Fall — einen Monat ansehen und
 * durchblättern — stehen die Pfeile daneben.
 *
 * ## Warum nicht `<input type="date">`
 *
 * Weil das native Feld der Sprache des BROWSERS folgt. Im Manager stand
 * deshalb bis heute `10/01/2026` in einer deutschen Oberfläche — und der
 * 01.10. und der 10.01. sehen so gleich aus. Dieselbe Begründung wie beim
 * {@link DateField}, das hier benutzt wird.
 *
 * ## Warum der Entwurf hier liegt und nicht beim Produkt
 *
 * Zwischen „Datum getippt" und „Anwenden gedrückt" steht ein halbfertiger
 * Zeitraum. Der gehört nicht nach draussen: Ein Produkt, das jeden
 * Tastendruck weitergibt, lädt die Auswertung für ein Datum neu, das niemand
 * gemeint hat.
 */
export function CategoryReportRange({ start, end, onApply, renderButton, labels, className = '' }: CategoryReportRangeProps) {
    const t = { ...defaultCategoryReportRangeLabels, ...labels };

    const [von, setVon] = useState(start);
    const [bis, setBis] = useState(end);

    function monatSetzen(versatz: number): void {
        const anker = alsDatum(von);
        const ersterTag = new Date(anker.getFullYear(), anker.getMonth() + versatz, 1);
        // Tag 0 des Folgemonats ist der letzte des gewählten — das erspart die
        // Fallunterscheidung über 28, 29, 30 und 31 Tage.
        const letzterTag = new Date(ersterTag.getFullYear(), ersterTag.getMonth() + 1, 0);

        setVon(alsIso(ersterTag));
        setBis(alsIso(letzterTag));
        onApply(alsIso(ersterTag), alsIso(letzterTag));
    }

    function aktuellerMonat(): void {
        const jetzt = new Date();
        const ersterTag = new Date(jetzt.getFullYear(), jetzt.getMonth(), 1);
        const letzterTag = new Date(jetzt.getFullYear(), jetzt.getMonth() + 1, 0);

        setVon(alsIso(ersterTag));
        setBis(alsIso(letzterTag));
        onApply(alsIso(ersterTag), alsIso(letzterTag));
    }

    const monatsname = alsDatum(von).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });

    return (
        <div className={`flex flex-wrap items-end gap-3 ${className}`} data-testid="category-report-range">
            <div className="flex items-center gap-1">
                {renderButton({ onClick: () => monatSetzen(-1), title: t.previousMonth, children: '‹' })}
                <span className="min-w-36 text-center text-sm font-medium">{monatsname}</span>
                {renderButton({ onClick: () => monatSetzen(1), title: t.nextMonth, children: '›' })}
            </div>

            {renderButton({ onClick: aktuellerMonat, children: t.currentMonth })}

            <div className="flex items-end gap-2">
                <label className="space-y-1">
                    <span className="text-muted-foreground block text-xs">{t.from}</span>
                    <DateField value={von} onChange={setVon} className="w-32" />
                </label>
                <label className="space-y-1">
                    <span className="text-muted-foreground block text-xs">{t.to}</span>
                    <DateField value={bis} onChange={setBis} className="w-32" />
                </label>
                {renderButton({ onClick: () => onApply(von, bis), active: true, children: t.apply })}
            </div>
        </div>
    );
}
