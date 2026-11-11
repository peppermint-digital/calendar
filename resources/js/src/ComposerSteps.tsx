import type { ReactNode } from 'react';

/**
 * Ein Schritt im Anlege-Assistenten.
 *
 * `chosen` ist der Grund, warum die Kette mehr ist als eine Fortschrittsanzeige:
 * Sobald eine Wahl getroffen ist, steht sie im Kopf — „Geschaeftstermin
 * (Manager)" — und bleibt dort sichtbar, waehrend man die naechste trifft. Ohne
 * das muesste man zurueckspringen, um nachzusehen, was man gewaehlt hat.
 */
export type ComposerStep = {
    /** Stabile Kennung. Sie steht im Datenattribut und ist der Griff fuer Tests. */
    key: string;
    /** Was im Kopf steht — „Was", „Terminart", „Felder". */
    label: string;
    /** Die getroffene Wahl, kurz. Fehlt sie, steht nur die Beschriftung da. */
    chosen?: string;
};

export type ComposerStepsLabels = {
    /** Fuer Hilfsmittel und schmale Breiten. `{current}` und `{total}` werden ersetzt. */
    position: string;
    /** Der Sprung zurueck, als Beschriftung fuer Hilfsmittel. `{label}` wird ersetzt. */
    back: string;
};

export const defaultComposerStepsLabels: ComposerStepsLabels = {
    position: 'Schritt {current} von {total}',
    back: 'Zurück zu: {label}',
};

export type ComposerStepsProps = {
    steps: ComposerStep[];
    /** Der Schluessel des offenen Schritts. */
    current: string;
    /**
     * Sprung auf einen bereits erledigten Schritt.
     *
     * Ohne diese Angabe ist die Kette reine Anzeige. MIT ihr ist sie der
     * Rueckweg — und zwar der einzige, den der Assistent braucht: Von Schritt
     * drei direkt auf Schritt eins, ohne sich durch Schritt zwei
     * zurueckzuklicken. Ein „Zurueck"-Knopf kann das nicht, er kennt nur den
     * Vorgaenger.
     */
    onGoTo?: (key: string) => void;
    labels?: Partial<ComposerStepsLabels>;
    className?: string;
};

/**
 * Der Kopf des Anlege-Assistenten: die Kette der Schritte.
 *
 * ## Warum ueberhaupt Schritte
 *
 * Die Maske fragt bis zu drei Dinge nacheinander ab, und erst die Antwort
 * entscheidet, was danach gebraucht wird: Ein Termin braucht eine Terminart und
 * deren Felder, eine einzuplanende Aufgabe braucht ein Aufgabensystem und einen
 * Zeitraum. Alles gleichzeitig zu zeigen heisst, die Haelfte umsonst zu fragen —
 * auf einem Telefon ist es ausserdem die Haelfte, die niemand sieht.
 *
 * ## Warum die Kette und kein Zurueck-Knopf
 *
 * Weil ein Assistent nur dann besser ist als eine lange Seite, wenn der Rueckweg
 * billiger ist als das Neuanfangen. Jede erledigte Stufe ist ein Knopf; spaetere
 * sind blass und nicht erreichbar, weil die Wahl davor sie erst bestimmt.
 *
 * ## Was hier NICHT liegt
 *
 * Die Entscheidung, welche Schritte es gibt. Sie haengt daran, was das Produkt
 * mitbringt (ein Aufgabensystem? fremde Ziele?), und sie gehoert deshalb dorthin,
 * wo diese Angaben stehen — in den {@link EventComposer}. Diese Komponente
 * zeichnet eine Liste, die sie bekommt.
 */
export function ComposerSteps({ steps, current, onGoTo, labels, className = '' }: ComposerStepsProps) {
    const beschriftung = { ...defaultComposerStepsLabels, ...labels };
    const laufend = steps.findIndex((schritt) => schritt.key === current);

    // Eine Kette mit einem Glied ist keine Kette. Das ist dieselbe Regel wie
    // beim Ueberspringen einer Stufe mit nur einer Antwort, eine Ebene hoeher:
    // Ein Kopf, der „Schritt 1 von 1" sagt, kostet Platz und sagt nichts.
    if (steps.length < 2) {
        return null;
    }

    return (
        <ol
            data-slot="composer-steps"
            aria-label={beschriftung.position
                .replace('{current}', String(Math.max(laufend, 0) + 1))
                .replace('{total}', String(steps.length))}
            className={`flex flex-wrap items-center gap-x-2 gap-y-1 text-sm ${className}`.trim()}
        >
            {steps.map((schritt, stelle) => {
                const offen = schritt.key === current;
                const erledigt = laufend >= 0 && stelle < laufend;
                const erreichbar = erledigt && Boolean(onGoTo);

                return (
                    <li key={schritt.key} className="flex items-center gap-x-2">
                        {stelle > 0 && (
                            // Rein zierend, deshalb vor Hilfsmitteln verborgen:
                            // vorgelesen wuerde aus der Kette eine Reihe
                            // bedeutungsloser Zeichen zwischen den Schritten.
                            <span aria-hidden="true" className="text-muted-foreground">
                                ›
                            </span>
                        )}

                        <Glied
                            schritt={schritt}
                            offen={offen}
                            erledigt={erledigt}
                            erreichbar={erreichbar}
                            zurueck={beschriftung.back.replace('{label}', schritt.label)}
                            onGoTo={onGoTo}
                        />
                    </li>
                );
            })}
        </ol>
    );
}

function Glied({
    schritt,
    offen,
    erledigt,
    erreichbar,
    zurueck,
    onGoTo,
}: {
    schritt: ComposerStep;
    offen: boolean;
    erledigt: boolean;
    erreichbar: boolean;
    zurueck: string;
    onGoTo?: (key: string) => void;
}): ReactNode {
    const inhalt = (
        <>
            <span className={offen ? 'font-medium' : ''}>{schritt.label}</span>
            {schritt.chosen && (
                <span className="text-muted-foreground"> · {schritt.chosen}</span>
            )}
        </>
    );

    if (! erreichbar) {
        return (
            <span
                data-step={schritt.key}
                // `aria-current` und nicht nur eine Farbe: Wo man gerade steht,
                // ist die eine Angabe, die ein Vorleser braucht.
                aria-current={offen ? 'step' : undefined}
                className={
                    offen
                        ? 'text-foreground'
                        : erledigt
                          ? 'text-muted-foreground'
                          : // Spaetere Schritte sind blass UND nicht
                            // anklickbar: Was die Wahl davor erst bestimmt,
                            // darf nicht aussehen wie eine Abkuerzung.
                            'text-muted-foreground/60'
                }
            >
                {inhalt}
            </span>
        );
    }

    return (
        <button
            type="button"
            data-step={schritt.key}
            onClick={() => onGoTo?.(schritt.key)}
            aria-label={zurueck}
            className="rounded text-muted-foreground underline underline-offset-4 hover:text-foreground"
        >
            {inhalt}
        </button>
    );
}
