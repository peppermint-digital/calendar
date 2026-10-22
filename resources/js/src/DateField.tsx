import { useRef, useState } from 'react';

import { Input } from './ui/input';
import { cn } from './ui/utils';

/**
 * Ein Datum in der Schreibweise TT.MM.JJJJ.
 *
 * ## Warum nicht `<input type="date">`
 *
 * Das native Feld folgt der Spracheinstellung des BROWSERS, nicht der der
 * Anwendung. Auf einem englisch eingestellten Chrome steht in einer durchweg
 * deutschen Oberfläche dann `mm/dd/yyyy` — und das ist beim Datum nicht nur
 * ungewohnt, sondern mehrdeutig: Der 09.08. und der 08.09. sehen gleich aus.
 *
 * Am 15.10.2026 genau so gesehen, in drei von vier Anwendungen gleichzeitig
 * (#7846). Dieselbe Begründung wie beim {@link TimeField}, das deshalb schon
 * seit v0.46.0 hier liegt.
 *
 * ## Nach aussen ISO, getippt deutsch
 *
 * Der Wert bleibt `YYYY-MM-DD` — daran hängen Server und Raster. Getippt wird
 * deutsch, und zwar nachsichtig: „8.9.26" wird angenommen.
 *
 * Unvollständige Eingaben werden NICHT nach oben gemeldet. Wer „8." getippt
 * hat, ist noch nicht fertig; ein Wert, der nach jedem Tastendruck nach aussen
 * geht, lässt den Entwurf zwischendurch auf Daten stehen, die niemand meinte.
 *
 * ## Und trotzdem eine Auswahl — seit dem 22.10.2026 (#1070)
 *
 * Am Telefon war Tippen der einzige Weg, und `inputMode="numeric"` holt dort
 * die Zahlentastatur hoch. Ein Datum als Ziffernfolge einzugeben ist so
 * umständlich, dass Termine mobil praktisch nicht angelegt wurden.
 *
 * Der Knopf daneben oeffnet deshalb die **native** Datumsauswahl ueber ein
 * verstecktes `<input type="date">` und `showPicker()`. Das ist kein
 * Widerspruch zu dem, was oben steht: Mehrdeutig ist die ANZEIGE des nativen
 * Feldes (`mm/dd/yyyy` auf englisch eingestelltem Browser) — und die bleibt
 * unsichtbar. Der Auswaehler selbst zeigt ein Monatsraster, in dem der 9.
 * August nicht mit dem 8. September zu verwechseln ist. Sichtbar bleibt
 * ueberall unser deutsches Feld.
 *
 * Kein `showPicker()` vorhanden oder abgelehnt: dann bekommt das Textfeld den
 * Fokus. Ein Knopf, der nichts tut, ist schlimmer als keiner.
 */

export interface DateFieldProps {
    id?: string;
    /** `YYYY-MM-DD`, oder leer. */
    value: string;
    onChange: (iso: string) => void;
    className?: string;
    disabled?: boolean;
    required?: boolean;
    /**
     * Faehnchen beim Ueberfahren.
     *
     * Gebraucht vor allem an einem gesperrten Feld: „Das Datum einer Serie
     * ergibt sich aus ihrer Regel" sagt, WARUM es nicht geht. Ein graues Feld
     * ohne Erklaerung sieht aus wie ein Fehler.
     */
    title?: string;
}

/** „2026-09-08" → „08.09.2026". */
function alsDeutsch(iso: string): string {
    const [jahr, monat, tag] = iso.split('-');

    return jahr && monat && tag ? `${tag}.${monat}.${jahr}` : '';
}

/**
 * „8.9.2026", „08.09.26" oder „2026-09-08" → „2026-09-08". Leer, wenn unlesbar.
 *
 * ISO wird mit angenommen, obwohl das Feld deutsch anzeigt: Wer ein Datum aus
 * einer Mail, einem Ticket oder einer anderen Maske hineinkopiert, hat es
 * meistens in dieser Form. Es abzulehnen hiesse, still nichts zu tun — und
 * stilles Nichtstun ist die schlechteste Antwort auf eine Eingabe.
 */
function alsIso(eingabe: string): string {
    const text = eingabe.trim();

    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
        return text;
    }

    const treffer = text.match(/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/);

    if (! treffer) {
        return '';
    }

    const [, tag = '', monat = '', jahr = ''] = treffer;
    const vollesJahr = jahr.length === 2 ? `20${jahr}` : jahr;

    return `${vollesJahr}-${monat.padStart(2, '0')}-${tag.padStart(2, '0')}`;
}

export function DateField({ id, value, onChange, className, disabled, required, title }: DateFieldProps) {
    const [text, setText] = useState(() => alsDeutsch(value));
    const [bekannt, setBekannt] = useState(value);
    const feld = useRef<HTMLInputElement>(null);
    const auswaehler = useRef<HTMLInputElement>(null);

    /** Den nativen Auswaehler oeffnen — und wenn das nicht geht, nichts verschlucken. */
    function auswahlOeffnen(): void {
        const nativ = auswaehler.current;

        try {
            // `showPicker` gibt es erst ab Chrome 99 / Safari 16 / Firefox 101.
            if (nativ && typeof nativ.showPicker === 'function') {
                nativ.showPicker();

                return;
            }
        } catch {
            // Manche Browser lehnen den Aufruf ab, wenn er nicht direkt aus
            // einer Nutzergeste kommt. Dann gilt derselbe Rueckweg wie unten.
        }

        feld.current?.focus();
    }

    // Von aussen gesetzte Werte uebernehmen — beim Zeichnen, nicht in einem
    // Effekt: Ein Effekt liefe erst nach dem Zeichnen und zoege eine zweite
    // Runde nach sich, in der das Feld kurz den alten Wert zeigt.
    if (value !== bekannt) {
        setBekannt(value);
        setText(alsDeutsch(value));
    }

    return (
        <div className="relative">
            <Input
                ref={feld}
                id={id}
                className={cn('pr-10', className)}
            disabled={disabled}
            required={required}
            title={title}
                placeholder="TT.MM.JJJJ"
                inputMode="numeric"
                data-slot="date-field"
                value={text}
                onChange={(ereignis) => {
                    setText(ereignis.target.value);

                    const iso = alsIso(ereignis.target.value);

                    if (iso !== '') {
                        onChange(iso);
                    }
                }}
                // Beim Verlassen auf die gepflegte Schreibweise zurueck: Wer
                // „8.9.26" getippt hat, sieht danach „08.09.2026" und weiss,
                // dass es angekommen ist.
                onBlur={() => setText(alsDeutsch(value))}
            />

            {/* Das native Feld ist NUR der Auswaehler, nie die Anzeige. Es
                bleibt im Aufbau (nicht `display: none`), weil `showPicker()`
                sonst in Chrome mit einem Fehler abbricht — unsichtbar und
                nicht anklickbar genuegt. */}
            <input
                ref={auswaehler}
                type="date"
                tabIndex={-1}
                aria-hidden="true"
                className="pointer-events-none absolute right-2 bottom-0 h-px w-px opacity-0"
                value={value}
                onChange={(ereignis) => {
                    const iso = ereignis.target.value;

                    if (iso !== '') {
                        setText(alsDeutsch(iso));
                        onChange(iso);
                    }
                }}
            />

            <button
                type="button"
                disabled={disabled}
                onClick={auswahlOeffnen}
                aria-label="Datum auswählen"
                data-slot="date-field-auswahl"
                className={cn(
                    'absolute top-1/2 right-1 -translate-y-1/2 rounded-md p-1.5 text-muted-foreground',
                    'transition-colors hover:bg-accent hover:text-accent-foreground',
                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    'disabled:pointer-events-none disabled:opacity-50',
                )}
            >
                {/* Inline statt `lucide-react`: Das Paket haelt sein Frontend
                    abhaengigkeitsarm, und ein Symbol ist kein Grund dafuer. */}
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-4 w-4" aria-hidden="true">
                    <path d="M8 2v4M16 2v4M3 10h18" />
                    <rect width="18" height="18" x="3" y="4" rx="2" />
                </svg>
            </button>
        </div>
    );
}

export default DateField;
