import { useCallback, useState } from 'react';

/**
 * Der Kalender eines anderen Systems — als Ziel zum Anlegen.
 *
 * Bis zum 14.10.2026 gab es diese Mechanik genau einmal: im AI Brain, eingewoben
 * in eine Kalenderseite von 1481 Zeilen. Manager, Verwaltung und CRM sind als
 * Quelle laengst beschreibbar — sie konnten es nur selbst nicht benutzen.
 *
 * ## Was hier liegt und was nicht
 *
 * Hier: die Abfolge. Ziel waehlen, die Arten des Zielsystems nachladen, anlegen,
 * verschieben, loeschen — samt der Zonenregel, an der das schon einmal
 * gescheitert ist.
 *
 * Nicht hier: wie die Auswahl aussieht, und wer die Quellen liefert. Beides
 * gehoert dem Produkt; die Quellen kommen als Angabe herein.
 */

export interface RemoteSource {
    key: string;
    label: string;
    colour?: string | null;
    /** Ob dieses System Termine von aussen annimmt. Sagt das Zielsystem, nicht wir. */
    writable?: boolean;
}

/** Eine Terminart des ZIELSYSTEMS. Was sie bedeutet, weiss nur das Zielsystem. */
export interface RemoteKind {
    key: string;
    label: string;
    fields?: string[];
}

export interface RemoteDraft {
    /** YYYY-MM-DD */
    date: string;
    /** HH:MM — bei einem Ganztagstermin ohne Bedeutung. */
    start?: string;
    end?: string;
    allDay?: boolean;
    title: string;
    location?: string | null;
    description?: string | null;
}

export interface UseRemoteTargetOptions {
    sources: RemoteSource[];
    /** Wo die Routen des Pakets liegen. Das Produkt entscheidet das Praefix. */
    basePath?: string;
    /** CSRF und was das Produkt sonst mitschickt. */
    headers?: () => Record<string, string>;
    /** Wird mit einer fertigen Meldung gerufen, wenn etwas schiefgeht. */
    onError?: (meldung: string) => void;
    /** Nach jedem erfolgreichen Vorgang — zum Neuladen des Rasters. */
    onDone?: () => void;
}

/**
 * Eine Uhrzeit dieses Tages als Zeitpunkt MIT Zone.
 *
 * ## Die Falle, wegen der das hier steht
 *
 * Bei einem Termin mit Uhrzeit MUSS die Zone mit. Stand hier die nackte
 * Wanduhrzeit (`2026-10-13T09:00:00`), las das andere System sie in SEINER
 * Anwendungszone — bei der Verwaltung ist das UTC —, und aus 09:00 Berliner
 * Zeit wurde 09:00 UTC. Nicht nur in der Anzeige: So stand es drueben wirklich
 * in der Spalte (Bug #995).
 *
 * Das Bittere daran: Die Gegenseite kannte die Falle und hatte sie behoben. Weil
 * die sendende Seite trotzdem Ortszeit ohne Zone schickte, lief die Reparatur
 * ins Leere — der Fehler sass auf der anderen Seite, als man ihn suchte.
 *
 * ## Warum ein Ganztagstermin anders behandelt wird
 *
 * Dort bleibt das nackte Datum stehen: Die Zone ist bedeutungslos, und eine
 * Umrechnung schoebe den Termin ueber Mitternacht auf den Vor- oder Folgetag.
 */
export function remoteMoment(date: string, time: string, allDay: boolean): string {
    if (allDay) {
        return `${date}T${time}:00`;
    }

    // Ohne Zone gelesen heisst: in der Zone DES BROWSERS. `toISOString()`
    // rechnet sie nach UTC um — das ist die Angabe, die drueben erwartet wird.
    return new Date(`${date}T${time}:00`).toISOString();
}

export function useRemoteTarget({
    sources,
    basePath = '/api/calendar',
    headers,
    onError,
    onDone,
}: UseRemoteTargetOptions) {
    /** Leer heisst: der eigene Kalender. */
    const [target, setTarget] = useState('');
    const [targetKinds, setTargetKinds] = useState<RemoteKind[]>([]);
    const [targetKind, setTargetKind] = useState('');
    const [busy, setBusy] = useState(false);

    const kopf = useCallback(
        () => ({ 'Content-Type': 'application/json', Accept: 'application/json', ...(headers?.() ?? {}) }),
        [headers],
    );

    const melden = useCallback(
        (meldung: string) => {
            onError?.(meldung);
        },
        [onError],
    );

    /**
     * Ein Ziel waehlen und seine Terminarten holen.
     *
     * Die Arten kommen erst beim Auswaehlen, nicht beim Laden der Seite: Der
     * Aufruf geht in ein anderes System und hat im Kalenderladen nichts
     * verloren — er wuerde jede Seite langsamer machen, auch die von jemandem,
     * der nie woanders anlegt.
     */
    const chooseTarget = useCallback(
        async (key: string): Promise<void> => {
            setTarget(key);
            setTargetKind('');
            setTargetKinds([]);

            if (key === '') {
                return;
            }

            try {
                const antwort = await fetch(`${basePath}/sources/${key}/kinds`, {
                    headers: { Accept: 'application/json', ...(headers?.() ?? {}) },
                });

                if (!antwort.ok) {
                    melden('Die Terminarten des anderen Systems sind gerade nicht abrufbar.');

                    return;
                }

                const daten = (await antwort.json()) as { kinds?: RemoteKind[] };
                const arten = daten.kinds ?? [];

                setTargetKinds(arten);
                // Die erste Art vorauswaehlen: Eine Liste ohne Auswahl fuehrt
                // zu einer Maske, die beim Abschicken nach etwas fragt, das
                // man schon sieht.
                setTargetKind(arten[0]?.key ?? '');
            } catch {
                melden('Das andere System hat nicht geantwortet.');
            }
        },
        [basePath, headers, melden],
    );

    /** Ob dieses System Termine von aussen annimmt. */
    const isWritable = useCallback(
        (key: string): boolean => sources.find((quelle) => quelle.key === key)?.writable === true,
        [sources],
    );

    /** Einen Termin im Zielsystem anlegen. */
    const createThere = useCallback(
        async (draft: RemoteDraft): Promise<boolean> => {
            if (target === '') {
                return false;
            }

            if (targetKind === '') {
                melden('Bitte eine Terminart des anderen Systems wählen.');

                return false;
            }

            const ganztags = draft.allDay === true || !draft.start;

            setBusy(true);

            try {
                const antwort = await fetch(`${basePath}/sources/${target}/events`, {
                    method: 'POST',
                    headers: kopf(),
                    body: JSON.stringify({
                        kind: targetKind,
                        title: draft.title,
                        starts_at: remoteMoment(draft.date, draft.start || '00:00', ganztags),
                        ends_at: remoteMoment(draft.date, draft.end || draft.start || '23:59', ganztags),
                        all_day: ganztags,
                        location: draft.location || null,
                        description: draft.description || null,
                    }),
                });

                if (!antwort.ok) {
                    // Die Meldung des Zielsystems hat Vorrang: Sie kennt den
                    // Grund, wir kennen nur die Zahl.
                    const problem = await antwort.json().catch(() => null);

                    melden(
                        problem?.message ??
                            `Der Termin konnte im anderen System nicht angelegt werden (Fehler ${antwort.status}).`,
                    );

                    return false;
                }

                onDone?.();

                return true;
            } catch {
                melden('Das andere System hat nicht geantwortet.');

                return false;
            } finally {
                setBusy(false);
            }
        },
        [basePath, kopf, melden, onDone, target, targetKind],
    );

    /**
     * Einen FREMDEN Termin verschieben.
     *
     * Die Kennung traegt den Quellenschluessel voran (`manager:42`) — der
     * Server will nur den hinteren Teil. Der Rest wird wieder
     * zusammengesetzt, weil eine Kennung selbst Doppelpunkte tragen darf:
     * CalDAV-Kennungen tun das.
     */
    const moveThere = useCallback(
        async (externalId: string, startsAt: Date, endsAt: Date, allDay = false): Promise<boolean> => {
            const [quelle, ...rest] = externalId.split(':');

            return vorgang(
                `${basePath}/sources/${quelle}/events/${rest.join(':')}`,
                {
                    method: 'PATCH',
                    body: JSON.stringify({
                        starts_at: startsAt.toISOString(),
                        ends_at: endsAt.toISOString(),
                        ...(allDay ? { all_day: true } : {}),
                    }),
                },
                'Der Termin konnte im anderen System nicht verschoben werden.',
            );
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [basePath, kopf, melden, onDone],
    );

    /** Einen FREMDEN Termin loeschen. */
    const deleteThere = useCallback(
        async (externalId: string): Promise<boolean> => {
            const [quelle, ...rest] = externalId.split(':');

            return vorgang(
                `${basePath}/sources/${quelle}/events/${rest.join(':')}`,
                { method: 'DELETE' },
                'Der Termin konnte im anderen System nicht gelöscht werden.',
            );
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [basePath, kopf, melden, onDone],
    );

    async function vorgang(adresse: string, optionen: RequestInit, fehlermeldung: string): Promise<boolean> {
        setBusy(true);

        try {
            const antwort = await fetch(adresse, { ...optionen, headers: kopf() });

            if (!antwort.ok) {
                const problem = await antwort.json().catch(() => null);

                melden(problem?.message ?? fehlermeldung);

                return false;
            }

            onDone?.();

            return true;
        } catch {
            melden(fehlermeldung);

            return false;
        } finally {
            setBusy(false);
        }
    }

    return {
        /** Leer = der eigene Kalender, sonst der Schluessel des Zielsystems. */
        target,
        chooseTarget,
        targetKinds,
        targetKind,
        setTargetKind,
        isWritable,
        createThere,
        moveThere,
        deleteThere,
        /** Laeuft gerade ein Vorgang gegen ein anderes System? */
        busy,
        /** Die Ziele, die Termine annehmen. */
        writableSources: sources.filter((quelle) => quelle.writable === true),
    };
}
