import { Calendar, User } from 'lucide-react';

import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import { cn } from './ui/utils';

/**
 * Umschalter zwischen dem eigenen Kalender und dem einer anderen Person.
 *
 * ## Was dieses Bauteil NICHT tut: Rechte prüfen
 *
 * Die Liste kommt fertig vom Server, der sie bereits auf das eingeschränkt hat,
 * was jemand sehen darf. Hier wird nichts hergeleitet — ein Bauteil, das Rechte
 * nachrechnet, ist die zweite Stelle, an der sie falsch sein können.
 *
 * ## Was es auch nicht tut: navigieren
 *
 * Das Paket hängt nicht an `@inertiajs/react` und soll es nicht, aus demselben
 * Grund wie {@link SlotFinderScreen}. Wohin ein Wechsel führt, weiß nur das
 * Produkt — und bei mehr als einer Ansicht hängt es sogar davon ab, auf welcher
 * man gerade steht: Im Peppermint Manager führt dieselbe Auswahl in der
 * Kalender-Ansicht nach `/calendar?user=…` und in der Agenda nach
 * `/calendar/agenda?user=…`.
 *
 * Genau daran ist der Umzug dieses Bauteils ins Paket zuerst gescheitert: Die
 * Manager-Fassung hatte `/calendar` fest eingebaut und war deshalb in der
 * Agenda nicht verwendbar — die hat sich daraufhin einen eigenen Wähler gebaut.
 * Ein fester Pfad in einem geteilten Bauteil erzeugt genau die Kopie, die das
 * Teilen vermeiden soll.
 *
 * ## Die Eigenheit, über die man stolpert
 *
 * Steht die betrachtete Kennung nicht in der Liste, wird bewusst der LEERE Wert
 * gesetzt: Radix zeigt dann den Platzhalter. Ein `undefined` machte die Auswahl
 * unkontrolliert und verschluckte den Platzhalter.
 */
export type SwitchableUser = {
    id: number;
    name: string;
    email?: string;
    department?: string | null;
};

export type CalendarUserSwitcherProps = {
    users: SwitchableUser[];
    /** Wessen Kalender gerade angesehen wird. */
    viewingUserId: number;
    /** Wer ansieht — nur, um „eigener Kalender" zu erkennen. */
    currentUserId: number;
    /** Der Wechsel. Das Produkt entscheidet, wohin. */
    onSwitch: (userId: number) => void;
    /** Hinweis, wenn man NICHT den eigenen Kalender ansieht. */
    viewingLabel?: string;
    placeholder?: string;
    className?: string;
};

export function CalendarUserSwitcher({
    users,
    viewingUserId,
    currentUserId,
    onSwitch,
    viewingLabel = 'Anzeige:',
    placeholder = 'Kalender wechseln',
    className,
}: CalendarUserSwitcherProps) {
    const gewaehlt = users.find((person) => person.id === viewingUserId);
    const eigener = viewingUserId === currentUserId;

    return (
        <div className={cn('flex items-center gap-2', className)}>
            {!eigener && gewaehlt ? (
                <div className="border-warning-border bg-warning-subtle hidden items-center gap-2 rounded-lg border px-3 py-1.5 md:flex">
                    <User className="text-warning h-4 w-4" />
                    <span className="text-warning text-sm font-medium">
                        {viewingLabel} {gewaehlt.name}
                    </span>
                </div>
            ) : null}

            <Select
                value={gewaehlt ? String(gewaehlt.id) : ''}
                onValueChange={(wert) => onSwitch(Number.parseInt(wert, 10))}
            >
                <SelectTrigger className="w-full md:w-[320px]">
                    <Calendar className="h-4 w-4 shrink-0" />
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {users.map((person) => (
                        <SelectItem key={person.id} value={String(person.id)}>
                            {person.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

export default CalendarUserSwitcher;
