import { useMemo } from 'react';

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import { cn } from './ui/utils';

/**
 * Eine Uhrzeit als zwei Auswahllisten (Stunde : Minute) statt als
 * `<input type="time">`.
 *
 * ## Warum nicht das native Feld
 *
 * Das native Zeitfeld richtet sich nach der Spracheinstellung des Browsers.
 * Steht die auf Englisch, erscheint ein Zwoelfstundenfeld mit AM/PM — in einem
 * deutschen Kalender, in dem alles andere 24 Stunden zaehlt. Niemand merkt das,
 * bis jemand mit einem englischen Browser einen Termin auf „07:00 PM" setzt.
 *
 * Zwei Listen zeigen ueberall dasselbe. Und sie sind schneller: Die Uhrzeiten,
 * die man im Arbeitsalltag braucht, liegen fast alle auf dem Fuenf-Minuten-
 * Raster, und zwei Klicks schlagen das Tippen von vier Ziffern.
 *
 * ## Das Raster und die krumme Minute
 *
 * `minuteStep` rastert die Minutenliste (Vorgabe 5). Eine bereits gespeicherte
 * Uhrzeit liegt aber selten auf dem Raster — eine gestempelte Kommt-Zeit ist
 * 08:37, nicht 08:35. Darum wird die aktuelle Minute immer einsortiert, auch
 * wenn sie nicht auf den Schritt passt.
 *
 * Ohne das faellt Radix still auf den Platzhalter zurueck: Es verlangt fuer
 * jeden Eintrag einen nichtleeren `value` und zeigt bei fehlendem Treffer
 * einfach nichts. Man oeffnet dann einen Termin um 08:37 und sieht eine leere
 * Minutenauswahl — der Wert ist da, nur unsichtbar, und beim naechsten
 * Speichern weg.
 *
 * ## Herkunft
 *
 * Stand bis zum 15.10.2026 nur im Peppermint Manager und wurde dort ueber
 * `renderTime` in die Maske gereicht. Die uebrigen drei Kalender zeigten das
 * native Feld — dieselbe Maske, zwei verschiedene Eingaben. Jetzt ist es die
 * Vorgabe des Pakets, und `renderTime` bleibt fuer den Fall, dass ein Produkt
 * wirklich etwas anderes braucht (#7846).
 */

export interface TimeFieldProps {
    id?: string;
    /** „HH:MM" oder „HH:MM:SS"; leer zeigt in beiden Listen den Platzhalter. */
    value?: string;
    onChange?: (value: string) => void;
    className?: string;
    disabled?: boolean;
    /** Schrittweite der Minutenliste. */
    minuteStep?: number;
    /**
     * Faehnchen beim Ueberfahren.
     *
     * Gebraucht vor allem an einem gesperrten Feld: „Das Datum einer Serie
     * ergibt sich aus ihrer Regel" sagt, WARUM es nicht geht. Ein graues Feld
     * ohne Erklaerung sieht aus wie ein Fehler.
     */
    title?: string;
}

/** Zerlegt „HH:MM" bzw. „HH:MM:SS" und fuellt einstellige Angaben auf. */
function zerlege(value: string): { stunde: string; minute: string } {
    if (! value) {
        return { stunde: '', minute: '' };
    }

    const [rohStunde = '', rohMinute = ''] = value.split(':');

    return {
        stunde: rohStunde ? rohStunde.padStart(2, '0') : '',
        minute: rohMinute ? rohMinute.padStart(2, '0') : '',
    };
}

const stunden = Array.from({ length: 24 }, (_, i) => i.toString().padStart(2, '0'));

export function TimeField({ id, value = '', onChange, className, disabled, minuteStep = 5, title }: TimeFieldProps) {
    const { stunde, minute } = useMemo(() => zerlege(value), [value]);

    const minuten = useMemo(() => {
        const liste: string[] = [];

        for (let i = 0; i < 60; i += minuteStep) {
            liste.push(i.toString().padStart(2, '0'));
        }

        // Die krumme Minute einsortieren, sonst zeigt Radix den Platzhalter.
        if (minute && ! liste.includes(minute)) {
            liste.push(minute);
            liste.sort();
        }

        return liste;
    }, [minuteStep, minute]);

    return (
        <div className={cn('flex items-center gap-1', className)} data-slot="time-field" title={title}>
            <Select value={stunde} onValueChange={(neu) => onChange?.(`${neu}:${minute || '00'}`)} disabled={disabled}>
                <SelectTrigger className="w-[70px]" id={id}>
                    <SelectValue placeholder="--" />
                </SelectTrigger>
                <SelectContent>
                    {stunden.map((eintrag) => (
                        <SelectItem key={eintrag} value={eintrag}>
                            {eintrag}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <span className="font-medium text-muted-foreground">:</span>

            <Select value={minute} onValueChange={(neu) => onChange?.(`${stunde || '00'}:${neu}`)} disabled={disabled}>
                <SelectTrigger className="w-[70px]">
                    <SelectValue placeholder="--" />
                </SelectTrigger>
                <SelectContent>
                    {minuten.map((eintrag) => (
                        <SelectItem key={eintrag} value={eintrag}>
                            {eintrag}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

export default TimeField;
