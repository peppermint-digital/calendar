import { Fragment, type ReactNode } from 'react';

import { AttendeePicker, type PickableAttendee } from './AttendeePicker';
import { CalendarBrowser } from './CalendarBrowser';
import { SlotFinder, type FinderDay, type FinderSlot } from './SlotFinder';
import type { CalendarToolbarButton } from './CalendarToolbar';

/**
 * Die ganze Terminfindungs-Seite: Zeitraum-Schritte, Personenwahl, Raster.
 *
 * ## Warum auch der Rahmen hierher gehoert
 *
 * Raster und Personenwahl lagen seit v0.74.0 schon im Paket — die Anordnung
 * drumherum nicht. Gemessen am 20.10.2026 waren die drei Produktseiten
 * daraufhin **byteweise identisch**: `peppermint-crm`,
 * `peppermint-verwaltung` und `peppermint-manager` fuehrten dieselbe Datei
 * mit 160 Zeilen, bis auf den Dateinamen nicht zu unterscheiden.
 *
 * Das ist Frage 3 des Paketvertrags („Schreibt jedes Produkt denselben
 * Verdrahtungscode?"), und sie war dreimal mit ja zu beantworten. Beim
 * Hinzufuegen des AI Brain als viertem Abnehmer waere daraus eine vierte Kopie
 * geworden — also genau der Zustand, den die Buendelung aufloesen sollte
 * (#8307).
 *
 * ## Was das Produkt noch liefert
 *
 * Drei Dinge, und alle drei aus demselben Grund: Das Paket bringt **keine**
 * Bedienelemente und **kein** Routing mit.
 *
 * | Was | Warum beim Produkt |
 * | --- | --- |
 * | `renderButton` | Jedes System hat seinen eigenen Knopf. Dieselbe Konvention wie {@link CalendarToolbar}. |
 * | `onNavigate` | Das Paket haengt nicht an `@inertiajs/react` — und soll es nicht. |
 * | `back` | Der Weg zurueck ist eine Adresse des Produkts, kein Kalenderwissen. |
 *
 * ## Warum die Auswahl ueber die Adresse laeuft und nicht im Browser
 *
 * Jede Aenderung laedt die Seite neu, statt im Browser zu rechnen. Das ist
 * langsamer und richtig: Die Belegung liegt auf dem Server, und eine Auswahl,
 * die nur im Browser existiert, laesst sich nicht verlinken — „schau mal, bei
 * uns dreien passt Dienstag" waere nicht weitergebbar.
 *
 * Deshalb bekommt `onNavigate` die **Aenderung**, nicht den Zustand: Das
 * Produkt setzt sie auf die aktuelle Einstellung und die gewaehlten Personen
 * oben drauf. Wer stattdessen den ganzen Zustand uebergaebe, muesste ihn an
 * jeder Aufrufstelle vollstaendig zusammentragen — und eine vergessene
 * Angabe fiele auf die Vorgabe des Servers zurueck, lautlos.
 */

export type SlotFinderScreenLabels = {
    title: string;
    /** Untertitel. `{n}` wird durch die Anzahl der gewaehlten Personen ersetzt. */
    subtitleOne: string;
    subtitleMany: string;
    today: string;
    previous: string;
    next: string;
    /** `{n}` wird durch die Minuten ersetzt. */
    duration: string;
    /** `{n}` wird durch die Anzahl der Tage ersetzt. */
    span: string;
};

export const defaultSlotFinderScreenLabels: SlotFinderScreenLabels = {
    title: 'Terminfindung',
    subtitleOne: 'Wann hast du Zeit?',
    subtitleMany: 'Wann haben alle {n} Zeit?',
    today: 'Heute',
    previous: '←',
    next: '→',
    duration: '{n} min',
    span: '{n} Tage',
};

export type SlotFinderSettings = {
    /** Erster Tag des Rasters, `YYYY-MM-DD`. */
    from: string;
    /** Wie viele Tage das Raster zeigt. */
    days: number;
    /** Laenge einer Scheibe in Minuten. */
    duration: number;
};

export type SlotFinderScreenProps = {
    days: FinderDay[];
    attendees: PickableAttendee[];
    settings: SlotFinderSettings;
    /** Der Knopf des Produkts. Dieselbe Konvention wie {@link CalendarToolbar}. */
    renderButton: (button: CalendarToolbarButton) => ReactNode;
    /**
     * Nur die **Aenderung**. Das Produkt legt die gewaehlten Personen und die
     * uebrige Einstellung selbst darunter — siehe Klassenkommentar.
     */
    onNavigate: (changes: Record<string, string | number | number[]>) => void;
    /** Ein freier Platz wurde angeklickt. */
    onPick: (slot: FinderSlot) => void;
    /** Der Weg zurueck — eine Adresse des Produkts. */
    back?: ReactNode;
    /** Welche Scheibenlaengen angeboten werden. */
    durations?: number[];
    /** Welche Zeitraumlaengen angeboten werden. */
    spans?: number[];
    labels?: Partial<SlotFinderScreenLabels>;
};

/** `{n}` ersetzen, ohne eine Vorlagen-Bibliothek dafuer zu holen. */
function einsetzen(vorlage: string, zahl: number): string {
    return vorlage.replace('{n}', String(zahl));
}

/**
 * Den Anker um ganze Zeitraeume verschieben.
 *
 * Von Hand und nicht ueber `Date.toISOString()`: Das rechnet nach UTC um, und
 * ein Anker vom 1. Maerz wuerde in Berlin zum 28. Februar. Der Zeitraum ist
 * ein Kalendertag, keine Zeitangabe.
 */
function verschobenerTag(von: string, tage: number): string {
    const anker = new Date(`${von}T00:00:00`);
    anker.setDate(anker.getDate() + tage);

    const zweistellig = (zahl: number) => String(zahl).padStart(2, '0');

    return `${anker.getFullYear()}-${zweistellig(anker.getMonth() + 1)}-${zweistellig(anker.getDate())}`;
}

export function SlotFinderScreen({
    days,
    attendees,
    settings,
    renderButton,
    onNavigate,
    onPick,
    back,
    durations = [30, 60],
    spans = [7, 14],
    labels,
}: SlotFinderScreenProps) {
    const text = { ...defaultSlotFinderScreenLabels, ...labels };
    const gewaehlt = attendees.filter((person) => person.selected).map((person) => person.id);

    return (
        <CalendarBrowser
            title={
                <div className="flex items-center gap-3">
                    {back}
                    <div>
                        <h1 className="text-xl font-bold sm:text-2xl">{text.title}</h1>
                        <p className="text-sm text-muted-foreground">
                            {gewaehlt.length === 1
                                ? text.subtitleOne
                                : einsetzen(text.subtitleMany, gewaehlt.length)}
                        </p>
                    </div>
                </div>
            }
            actions={
                <div className="flex flex-wrap gap-1">
                    {renderButton({
                        onClick: () => onNavigate({ von: verschobenerTag(settings.from, -settings.days) }),
                        title: 'Zurück',
                        children: text.previous,
                    })}
                    {renderButton({ onClick: () => onNavigate({ von: 'today' }), children: text.today })}
                    {renderButton({
                        onClick: () => onNavigate({ von: verschobenerTag(settings.from, settings.days) }),
                        title: 'Weiter',
                        children: text.next,
                    })}

                    {durations.map((dauer) => (
                        <Fragment key={`dauer-${dauer}`}>
                            {renderButton({
                                active: settings.duration === dauer,
                                onClick: () => onNavigate({ dauer }),
                                children: einsetzen(text.duration, dauer),
                            })}
                        </Fragment>
                    ))}
                    {spans.map((tage) => (
                        <Fragment key={`tage-${tage}`}>
                            {renderButton({
                                active: settings.days === tage,
                                onClick: () => onNavigate({ tage }),
                                children: einsetzen(text.span, tage),
                            })}
                        </Fragment>
                    ))}
                </div>
            }
            toolbar={
                <div className="border-t pt-3">
                    <AttendeePicker
                        attendees={attendees}
                        onToggle={(id) =>
                            onNavigate({
                                users: gewaehlt.includes(id)
                                    ? gewaehlt.filter((eintrag) => eintrag !== id)
                                    : [...gewaehlt, id],
                            })
                        }
                    />
                </div>
            }
            grid={
                /*
                    Ein freier Platz fuehrt direkt in die Anlegen-Maske, mit
                    Zeitraum. Ohne das waere die Terminfindung eine Auskunft,
                    die man danach abtippen muss.

                    Hier steht KEINE Karte mehr drumherum. Die drei
                    Produktseiten hatten eine (`<Card><CardContent>`), und
                    `CalendarBrowser` bringt mit `data-slot="calendar-card"`
                    schon eine mit — das Raster sass also in zwei Karten, mit
                    doppeltem Rand und doppelter Polsterung. Dreimal gleich
                    falsch ist immer noch falsch.
                */
                <SlotFinder days={days} onPick={onPick} />
            }
        />
    );
}

export default SlotFinderScreen;
