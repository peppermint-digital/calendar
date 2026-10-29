import { useCallback, useEffect, useMemo, useState } from 'react';

import { cn } from './ui/utils';

/**
 * „Wie lange vor einem Termin will ich es wissen?" — die Maske dazu.
 *
 * ## Warum sie hier liegt und nicht im Produkt
 *
 * Weil sie sonst viermal hier liegt. Gepflegt wird der Vorlauf an EINER
 * Stelle, im AI Brain neben der Benachrichtigungs-Matrix (#520, für den
 * Vorlauf bestätigt am 29.10.2026: „Das ist ja unser Hauptkalender-Paket bzw.
 * Erinnerungspaket und verwaltet ja auch alle notifications"). Dieselbe Maske
 * stellt dort den eigenen Vorlauf und den jedes angebundenen Produkts — der
 * einzige Unterschied sind die Adressen.
 *
 * ## Die drei Zustände, und warum es wirklich drei sind
 *
 * | Auswahl | heißt |
 * |---|---|
 * | eine Zahl | so viele Minuten vorher |
 * | „Keine" | keine Erinnerung — eine ENTSCHEIDUNG |
 * | „Vorgabe" | ich entscheide nicht; was das System vorgibt, gilt weiter |
 *
 * „Vorgabe" ist nicht dasselbe wie „auf den heutigen Vorgabewert stellen":
 * Wer zurückgesetzt hat, folgt einer späteren Änderung weiter. Deshalb ist es
 * ein eigener Knopf und nicht einfach die Zahl, die gerade gilt — und deshalb
 * zeigt die Maske bei einem Wert ohne eigene Entscheidung „Vorgabe" an, statt
 * ihn wie eine Wahl aussehen zu lassen.
 *
 * Und „Keine" ist nicht „Vorgabe": Wer Erinnerungen abgestellt hat, soll von
 * einer späteren Anhebung der Vorgabe nicht wieder eingeschaltet werden.
 *
 * ## Was sie NICHT entscheidet
 *
 * Den Weg. Ob die Erinnerung als Glocke, Push oder Mail ankommt, steht in den
 * Benachrichtigungs-Einstellungen — je Person und je Art. Eine zweite Stelle
 * dafür wäre genau der Fehler, der am 22.10.2026 beim Push-Schalter
 * nachgewiesen wurde.
 */

export type ReminderLeadState = {
    /** Was gilt. `null` heißt „keine Erinnerung". */
    lead_minutes: number | null;
    /** Selbst entschieden? Sonst gilt die Vorgabe — und folgt ihr weiter. */
    eigen: boolean;
    vorgabe: number | null;
    /** Die Stufen, die DIESES System anbietet. Geraten wird hier nichts. */
    choices: number[];
    /**
     * Schickt dieses System überhaupt Termin-Erinnerungen?
     *
     * `calendar.reminders.enabled` ist in der Vorgabe aus. Eine Maske, die
     * einen Vorlauf für ein System anbietet, das nichts schickt, verspricht
     * etwas, das nicht passiert.
     */
    enabled?: boolean;
    /** Konnte überhaupt eine Person bestimmt werden? */
    person?: boolean;
};

export type ReminderLeadSettingLabels = {
    heading: string;
    description: string;
    none: string;
    followsDefault: string;
    reset: string;
    disabledHint: string;
    loadFailed: string;
    saveFailed: string;
    /** 30 → „30 Min." */
    formatMinutes: (minutes: number) => string;
};

export interface ReminderLeadSettingProps {
    /** Vorbefüllung aus Server-Props — spart das erste Laden. */
    initial?: ReminderLeadState;
    /** Vorgabe: `/calendar/reminder-lead` für beides. */
    routes?: { show?: string; update?: string };
    /** Der Name des Systems, wenn die Maske mehrere zeigt. */
    systemName?: string;
    labels?: Partial<ReminderLeadSettingLabels>;
    className?: string;
}

const VORGABE_ROUTEN = {
    show: '/calendar/reminder-lead',
    update: '/calendar/reminder-lead',
};

const VORGABE_WORTE: ReminderLeadSettingLabels = {
    heading: 'Termin-Erinnerung',
    description: 'Wie lange vor einem Termin du erinnert wirst. Der Weg — Glocke, Push oder E-Mail — steht bei den Benachrichtigungsarten.',
    none: 'Keine',
    followsDefault: 'Vorgabe',
    reset: 'Vorgabe verwenden',
    disabledHint: 'Dieses System schickt derzeit keine Termin-Erinnerungen.',
    loadFailed: 'Die Einstellung konnte nicht geladen werden.',
    saveFailed: 'Die Einstellung konnte nicht gespeichert werden.',
    formatMinutes: (minuten) => `${minuten} Min.`,
};

/**
 * Das CSRF-Token — aus dem Kopf oder aus dem Cookie.
 *
 * Inertia-Anwendungen haben oft kein `<meta name="csrf-token">`, und ein
 * leerer Kopf täuscht einen Mechanismus vor, den es nicht gibt. Dieselbe
 * Begründung wie im Meldungs-Paket.
 */
function csrfHeaders(): Record<string, string> {
    if (typeof document === 'undefined') {
        return {};
    }

    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (meta) {
        return { 'X-CSRF-TOKEN': meta };
    }

    const cookie = document.cookie
        .split('; ')
        .find((teil) => teil.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) } : {};
}

export function ReminderLeadSetting({ initial, routes, systemName, labels, className = '' }: ReminderLeadSettingProps) {
    const adressen = useMemo(() => ({ ...VORGABE_ROUTEN, ...routes }), [routes]);
    const wort = { ...VORGABE_WORTE, ...labels };

    const [stand, setStand] = useState<ReminderLeadState | null>(initial ?? null);
    const [laedt, setLaedt] = useState(!initial);
    const [speichert, setSpeichert] = useState(false);
    const [fehler, setFehler] = useState<string | null>(null);

    const laden = useCallback(async (): Promise<void> => {
        try {
            const antwort = await fetch(adressen.show, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!antwort.ok) {
                setFehler(wort.loadFailed);

                return;
            }

            setStand(await antwort.json());
            setFehler(null);
        } catch {
            setFehler(wort.loadFailed);
        } finally {
            setLaedt(false);
        }
        // `wort` entsteht bei jedem Durchlauf neu und wäre ein Dauer-Auslöser;
        // die Beschriftungen ändern sich zur Laufzeit nicht.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [adressen.show]);

    useEffect(() => {
        if (!initial) {
            void laden();
        }
        // `initial` ist die Vorbefüllung des ersten Durchlaufs, kein Auslöser.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [laden]);

    /**
     * Speichern — und den Stand aus der ANTWORT übernehmen, nicht aus dem
     * Klick.
     *
     * Bei „Vorgabe verwenden" ist das der ganze Punkt: Was danach gilt, weiß
     * nur das System drüben. Wer den Klick optimistisch anzeigt, zeigt nach
     * einem Zurücksetzen einen Wert, den er sich ausgedacht hat.
     */
    const speichern = useCallback(
        async (koerper: Record<string, unknown>): Promise<void> => {
            setSpeichert(true);

            try {
                const antwort = await fetch(adressen.update, {
                    method: 'PUT',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...csrfHeaders(),
                    },
                    body: JSON.stringify(koerper),
                });

                if (!antwort.ok) {
                    setFehler(wort.saveFailed);

                    return;
                }

                setStand(await antwort.json());
                setFehler(null);
            } catch {
                setFehler(wort.saveFailed);
            } finally {
                setSpeichert(false);
            }
            // Siehe oben: Beschriftungen sind kein Auslöser.
            // eslint-disable-next-line react-hooks/exhaustive-deps
        },
        [adressen.update],
    );

    if (laedt) {
        return <div className={cn('text-sm text-muted-foreground', className)}>…</div>;
    }

    if (stand === null) {
        return <div className={cn('text-sm text-destructive', className)}>{fehler ?? wort.loadFailed}</div>;
    }

    const gewaehlt = (minuten: number | null): boolean => stand.eigen && stand.lead_minutes === minuten;

    const knopf = 'rounded-md border px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-50';
    const aktiv = 'border-primary bg-primary text-primary-foreground';
    const ruhend = 'hover:bg-accent';

    return (
        <div className={cn('space-y-2', className)}>
            <div>
                <div className="text-sm font-medium">
                    {wort.heading}
                    {systemName !== undefined && systemName !== '' && (
                        <span className="ml-1 font-normal text-muted-foreground">({systemName})</span>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">{wort.description}</p>
            </div>

            {/* Der Hinweis gehört VOR die Knöpfe und blendet sie nicht aus:
                Wer hier etwas einstellt, soll es tun können, bevor das System
                eingeschaltet wird — sonst muss er daran denken, später
                wiederzukommen. */}
            {stand.enabled === false && <p className="text-xs text-amber-600 dark:text-amber-500">{wort.disabledHint}</p>}

            <div className="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    disabled={speichert}
                    onClick={() => void speichern({ lead_minutes: null })}
                    className={cn(knopf, gewaehlt(null) ? aktiv : ruhend)}
                >
                    {wort.none}
                </button>

                {stand.choices.map((minuten) => (
                    <button
                        key={minuten}
                        type="button"
                        disabled={speichert}
                        onClick={() => void speichern({ lead_minutes: minuten })}
                        className={cn(knopf, gewaehlt(minuten) ? aktiv : ruhend)}
                    >
                        {wort.formatMinutes(minuten)}
                    </button>
                ))}

                {/* „Folgt der Vorgabe" ist ein ZUSTAND und keine Auswahl —
                    deshalb ein Abzeichen und kein Knopf. Umgekehrt braucht
                    eine eigene Entscheidung einen Weg zurück, und den gibt es
                    nur, solange sie besteht. */}
                {stand.eigen ? (
                    <button
                        type="button"
                        disabled={speichert}
                        onClick={() => void speichern({ reset: true })}
                        className={cn(knopf, ruhend, 'text-muted-foreground')}
                    >
                        {wort.reset}
                    </button>
                ) : (
                    <span className="ml-1 text-xs text-muted-foreground">
                        {wort.followsDefault}
                        {': '}
                        {stand.vorgabe === null ? wort.none : wort.formatMinutes(stand.vorgabe)}
                    </span>
                )}
            </div>

            {fehler !== null && <p className="text-xs text-destructive">{fehler}</p>}
        </div>
    );
}

export default ReminderLeadSetting;
