import { useEffect, useState } from 'react';

import { Input } from './ui/input';

/**
 * Etwas Planbares — eine Aufgabe, ein Ticket, ein Vorgang.
 *
 * Bewusst knapp: Der Kalender braucht nur, was bei der WAHL hilft. Alles
 * Weitere steht im System, aus dem es kommt.
 */
export type Plannable = {
    id: number | string;
    title: string;
    /** Woher es kommt — Projekt, Kunde, Vorgang. */
    context?: string | null;
    /** Dringlichkeit, so wie das Quellsystem sie nennt. */
    urgency?: string | null;
    /** Wann es fällig ist, schon lesbar formatiert. */
    due?: string | null;
};

/**
 * Suchen und auswählen, was eingeplant werden soll.
 *
 * ## Warum das in den Kalender gehört
 *
 * „Eine bestehende Aufgabe einplanen" ist aus Sicht des Kalenders kein
 * Aufgaben-Vorgang, sondern ein {@link PlannedBlockKind}: ein Eintrag, der ein
 * `subject_type`/`subject_id` trägt. Der Kalender modelliert das ohnehin; was
 * ihm fehlte, war der Weg, das Subjekt zu finden.
 *
 * Eine NEUE Aufgabe anzulegen gehört dagegen nicht hierher — Titel, Priorität
 * und Projekt sind Begriffe des Aufgabensystems. Dafür verweist die Maske
 * hinaus, mit dem Zeitraum in der Adresse.
 *
 * ## Woher die Treffer kommen, sagt das Produkt
 *
 * `search` ist eine Funktion, kein Pfad. Jedes System fragt seine eigene
 * Stelle — das AI Brain `/api/calendar/plannable-tasks`, die Verwaltung ihre
 * offenen Tickets. Ein fester Pfad im Paket hätte alle gezwungen, denselben
 * Endpunkt zu bauen, und das Paket hätte etwas über die Datenbank des
 * Produkts gewusst.
 *
 * Gibt ein Produkt gar keine Suche mit, erscheint der ganze Weg nicht. Das ist
 * die Modularität: Ohne angestecktes Aufgabensystem gibt es nichts
 * einzuplanen, und die Maske fragt nicht danach.
 *
 * ## Herkunft
 *
 * Stand bis zum 16.10.2026 im AI Brain, dort zweimal und auseinandergelaufen.
 * Der Weg hierher ist derselbe wie beim Zeitfeld und beim Datumsfeld: Wer es
 * zuerst brauchte, hat es gebaut; sobald es ein zweiter braucht, zieht es um.
 */

export interface PlannableSearchProps {
    /** Liefert die Treffer zum Suchbegriff. Leerer Begriff = die ersten Vorschläge. */
    search: (begriff: string) => Promise<Plannable[]>;
    /** Was mit dem gewählten Eintrag geschehen soll. */
    onPick: (id: number | string) => void;
    placeholder?: string;
    autoFocus?: boolean;
    /** Text, wenn nichts gefunden wurde. */
    emptyText?: string;
}

export function PlannableSearch({
    search,
    onPick,
    placeholder = 'Titel eingeben',
    autoFocus = false,
    emptyText = 'Nichts Offenes gefunden.',
}: PlannableSearchProps) {
    const [begriff, setBegriff] = useState('');
    const [treffer, setTreffer] = useState<Plannable[]>([]);

    useEffect(() => {
        let aktiv = true;

        search(begriff)
            .then((gefunden) => {
                if (aktiv) {
                    setTreffer(gefunden);
                }
            })
            .catch(() => {
                // Eine leere Liste ist hier die ehrlichere Antwort als eine
                // Fehlermeldung: Wer tippt, bekommt gleich die naechste
                // Abfrage. Und ein Wurf hier riss frueher den ganzen Dialog
                // mit, in dem die Suche steht.
                if (aktiv) {
                    setTreffer([]);
                }
            });

        return () => {
            aktiv = false;
        };
    }, [begriff, search]);

    return (
        <div className="flex flex-col gap-2" data-slot="plannable-search">
            <Input
                autoFocus={autoFocus}
                placeholder={placeholder}
                value={begriff}
                onChange={(ereignis) => setBegriff(ereignis.target.value)}
            />

            <div className="flex max-h-64 flex-col gap-1 overflow-y-auto">
                {treffer.map((eintrag) => (
                    <button
                        key={eintrag.id}
                        type="button"
                        className="hover:border-primary/50 rounded-md border p-2 text-left text-sm"
                        onClick={() => onPick(eintrag.id)}
                    >
                        <div className="font-medium">{eintrag.title}</div>

                        {/* Die Zeile darunter nur, wenn es etwas zu sagen gibt —
                            sonst stuende an jedem Treffer ein leeres Kaestchen. */}
                        {(eintrag.context || eintrag.urgency || eintrag.due) && (
                            <div className="text-muted-foreground mt-0.5 flex flex-wrap gap-2 text-xs">
                                {eintrag.context && <span>{eintrag.context}</span>}
                                {eintrag.urgency && <span>{eintrag.urgency}</span>}
                                {eintrag.due && <span>fällig {eintrag.due}</span>}
                            </div>
                        )}
                    </button>
                ))}

                {treffer.length === 0 && <p className="text-muted-foreground p-2 text-sm">{emptyText}</p>}
            </div>
        </div>
    );
}

export default PlannableSearch;
