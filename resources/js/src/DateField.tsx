import { useState } from 'react';

import { Input } from './ui/input';

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
 */

export interface DateFieldProps {
    id?: string;
    /** `YYYY-MM-DD`, oder leer. */
    value: string;
    onChange: (iso: string) => void;
    className?: string;
    disabled?: boolean;
    required?: boolean;
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

export function DateField({ id, value, onChange, className, disabled, required }: DateFieldProps) {
    const [text, setText] = useState(() => alsDeutsch(value));
    const [bekannt, setBekannt] = useState(value);

    // Von aussen gesetzte Werte uebernehmen — beim Zeichnen, nicht in einem
    // Effekt: Ein Effekt liefe erst nach dem Zeichnen und zoege eine zweite
    // Runde nach sich, in der das Feld kurz den alten Wert zeigt.
    if (value !== bekannt) {
        setBekannt(value);
        setText(alsDeutsch(value));
    }

    return (
        <Input
            id={id}
            className={className}
            disabled={disabled}
            required={required}
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
            // Beim Verlassen auf die gepflegte Schreibweise zurueck: Wer „8.9.26"
            // getippt hat, sieht danach „08.09.2026" und weiss, dass es
            // angekommen ist.
            onBlur={() => setText(alsDeutsch(value))}
        />
    );
}

export default DateField;
