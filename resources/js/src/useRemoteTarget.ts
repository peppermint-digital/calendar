import { useCallback, useEffect, useRef, useState } from 'react';
import type { EventKind } from './types';

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
    /**
     * Das System hat auf die Frage nicht geantwortet.
     *
     * Getrennt von `writable: false` gefuehrt, und das ist der ganze Zweck:
     * „Niemand ist zustaendig" und „ich konnte nicht nachsehen" sind zwei
     * Aussagen (Wiki #595). Ein ausgefallenes Ziel bleibt `writable: false` —
     * es ist nicht beschreibbar, und ein Knopf, der drueben scheitert, ist
     * schlechter als kein Knopf.
     *
     * Gesetzt vom Paket, nicht vom Produkt: `EventSourceRegistry::ziele()`.
     */
    unreachable?: boolean;
    /**
     * Das System hat GEANTWORTET und den Zugriff abgelehnt.
     *
     * Getrennt von `unreachable` gefuehrt, und das ist der ganze Zweck
     * (Aufgabe #9882): Am Komm-Layer sind `not_allowed` und `unreachable`
     * zwei der vier Fehlerarten, mit zwei verschiedenen naechsten Schritten.
     * Ein Ausfall geht vorueber, eine Absage behebt ein Mensch.
     *
     * Bastian las am 12.11.2026 „Nicht erreichbar: AI Brain (privat)" ueber
     * ein System, das erreichbar war und mit HTTP 401 abgelehnt hatte. Wer
     * die Absage in `unreachable` mitmeinte, haette dieselbe Luege nur
     * umgezogen — ein Anzeiger mit zwei Bedeutungen ist schlimmer als zwei
     * Anzeiger.
     *
     * Der Name ist der des Komm-Layers und bleibt in seiner Schreibweise:
     * Das Produkt reicht die Ernte durch und uebersetzt nichts — dieselbe
     * Begruendung wie bei `may_retry` in {@link PlannableFailure}.
     *
     * Gesetzt vom Paket, nicht vom Produkt: `EventSourceRegistry::ziele()`.
     */
    not_allowed?: boolean;
}

/** Eine Terminart des ZIELSYSTEMS. Was sie bedeutet, weiss nur das Zielsystem. */
/**
 * Eine Terminart, wie ein ANDERES System sie anbietet.
 *
 * Deckungsgleich mit `EventKind` — und das ist der Punkt: Die gemeinsame
 * `EventForm` zeichnet nach `requires` und `forbids`, und wenn ein fremdes Ziel
 * gewaehlt ist, soll sie nach der ZIELART zeichnen statt nach den eigenen.
 * Dafuer muessen die beiden Typen zusammenpassen, ohne dass irgendwo eine
 * Uebersetzung dazwischensteht, die beim naechsten Feld vergessen wird.
 *
 * Vorher stand hier `fields?: string[]` — ein Name, der nichts aussagte und den
 * niemand las. Die Maske zeigte deshalb immer die eigenen Felder, auch wenn
 * drueben ganz andere gelten: Wer „Meeting digital" im Manager waehlte, bekam
 * keine Eingabe fuer den Meeting-Link und nach dem Absenden eine Ablehnung.
 */
export type RemoteKind = EventKind;

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
    /**
     * Der Meeting-Link.
     *
     * Kein Beiwerk: `meeting_digital` im Manager meldet ihn ueber `requires`,
     * und ohne ihn lehnt das Zielsystem ab. Bis zum 14.10.2026 transportierte
     * dieser Entwurf ihn nicht — die Maske fragte danach, und die Angabe blieb
     * auf dem Weg liegen.
     */
    meetingUrl?: string | null;
}

export interface UseRemoteTargetOptions {
    sources: RemoteSource[];
    /** Wo die Routen des Pakets liegen. Das Produkt entscheidet das Praefix. */
    basePath?: string;
    /** CSRF und was das Produkt sonst mitschickt. */
    headers?: () => Record<string, string>;
    /** Wird mit einer fertigen Meldung gerufen, wenn etwas schiefgeht. */
    onError?: (meldung: string) => void;
    /**
     * Nach JEDEM Vorgang — auch nach einem gescheiterten.
     *
     * Das ist kein Versehen: Beim Verschieben hat das Raster die neue Lage
     * schon gezeichnet, bevor der Server gefragt wurde. Ohne Nachladen im
     * Fehlerfall bliebe der Termin dort liegen, wo ihn niemand hingelegt hat —
     * mit Nachladen springt er zurueck.
     */
    onDone?: () => void;
    /**
     * Die Arten ALLER beschreibbaren Ziele schon beim Oeffnen holen.
     *
     * Ausdruecklich ein Schalter und kein Dauerverhalten. Wer eine Art aus
     * einer Liste aller Systeme waehlt, braucht sie vor der ersten Wahl — wer
     * im Raster nur verschiebt und loescht, braucht sie nie. Das Brain nutzt
     * diesen Haken auf seiner Kalenderseite genau dafuer; dort waere das ein
     * Netzaufruf je Quelle bei jedem Seitenaufbau, und dieselbe Schleife ist
     * hier schon einmal aufgefallen, bis das andere System zu drosseln anfing.
     */
    preloadKinds?: boolean;
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
    preloadKinds = false,
}: UseRemoteTargetOptions) {
    /** Leer heisst: der eigene Kalender. */
    const [target, setTarget] = useState('');
    const [targetKinds, setTargetKinds] = useState<RemoteKind[]>([]);
    const [targetKind, setTargetKind] = useState('');
    const [busy, setBusy] = useState(false);

    /**
     * Die Arten je Ziel, sobald sie einmal geholt wurden.
     *
     * Der Speicher, an dem die zweite Abfrage haengt: Wer die Liste aller
     * Arten gesehen hat, hat die seines Ziels schon — ein Netzweg beim
     * Anklicken waere derselbe Aufruf ein zweites Mal.
     */
    const [kindsBySource, setKindsBySource] = useState<Record<string, RemoteKind[]>>({});

    /**
     * Ziele, deren ARTENABRUF fehlgeschlagen ist.
     *
     * Getrennt von „hat keine Arten" gefuehrt, und das ist der ganze Zweck:
     * „Niemand ist zuständig" und „ich konnte nicht nachsehen" sind zwei
     * Aussagen (Wiki #595). Ein Systemausfall, der als leere Liste erscheint,
     * sieht aus wie eine Antwort.
     *
     * Das ist nur die eine HAELFTE der Ausfaelle: Hier landet, wer in der
     * Liste stand und beim Abruf wegblieb. Die andere Haelfte meldet sich
     * schon in der Liste (`unreachable` am Zieleintrag) und kommt gar nicht
     * bis zum Abruf — ein Ziel, das nicht `writable` ist, wird nie
     * vorgeladen. `unreachableSources` unten fuegt beide zusammen.
     */
    const [fehlgeschlageneZiele, setFehlgeschlageneZiele] = useState<string[]>([]);

    /**
     * Schon von der ERSTEN Zeichnung an wahr, wenn vorgeladen wird.
     *
     * Sonst gaebe es ein Fenster von einem Rendern, in dem noch keine fremden
     * Arten da sind und auch nichts laedt — eine Oberflaeche, die daran eine
     * Stufe ueberspringt, springt sie gleich danach wieder auf.
     */
    const [loadingKinds, setLoadingKinds] = useState(
        () => preloadKinds && sources.some((quelle) => quelle.writable === true),
    );

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
     * Die Arten EINES Ziels holen.
     *
     * Gibt `null` zurueck, wenn das andere System nicht geantwortet hat — und
     * das ist nicht dasselbe wie eine leere Liste. Wer beides gleich
     * behandelt, zeigt einen Ausfall als Auskunft.
     */
    const artenHolen = useCallback(
        async (key: string): Promise<{ kinds: RemoteKind[] } | { fehler: string }> => {
            try {
                const antwort = await fetch(`${basePath}/sources/${key}/kinds`, {
                    headers: { Accept: 'application/json', ...(headers?.() ?? {}) },
                });

                if (!antwort.ok) {
                    return { fehler: 'Die Terminarten des anderen Systems sind gerade nicht abrufbar.' };
                }

                const daten = (await antwort.json()) as { kinds?: RemoteKind[] };

                return { kinds: daten.kinds ?? [] };
            } catch {
                return { fehler: 'Das andere System hat nicht geantwortet.' };
            }
        },
        [basePath, headers],
    );

    /**
     * Ein Ziel waehlen und seine Terminarten holen.
     *
     * Ohne `preloadKinds` kommen die Arten erst beim Auswaehlen, nicht beim
     * Laden der Seite: Der Aufruf geht in ein anderes System und hat im
     * Kalenderladen nichts verloren — er wuerde jede Seite langsamer machen,
     * auch die von jemandem, der nie woanders anlegt.
     *
     * Sind sie dagegen schon geholt, wird der Weg NICHT zweimal gegangen. Das
     * ist kein Feinschliff: Die Artenliste des Assistenten zeigt alle Systeme
     * auf einmal, und ein Aufruf bei jedem Anklicken waere derselbe Aufruf
     * noch einmal — in einem fremden System, das irgendwann drosselt.
     *
     * `kind` ist die Art, die gewaehlt werden soll. Ohne Angabe die erste:
     * Eine Liste ohne Auswahl fuehrt zu einer Maske, die beim Abschicken nach
     * etwas fragt, das man schon sieht.
     */
    const chooseTarget = useCallback(
        async (key: string, kind?: string): Promise<void> => {
            setTarget(key);
            setTargetKind('');
            setTargetKinds([]);

            if (key === '') {
                return;
            }

            const schon = kindsBySource[key];

            if (schon !== undefined) {
                setTargetKinds(schon);
                setTargetKind(kind ?? schon[0]?.key ?? '');

                return;
            }

            const ergebnis = await artenHolen(key);

            if ('fehler' in ergebnis) {
                melden(ergebnis.fehler);
                setFehlgeschlageneZiele((bisher) => (bisher.includes(key) ? bisher : [...bisher, key]));

                return;
            }

            setKindsBySource((bisher) => ({ ...bisher, [key]: ergebnis.kinds }));
            setTargetKinds(ergebnis.kinds);
            setTargetKind(kind ?? ergebnis.kinds[0]?.key ?? '');
        },
        [artenHolen, kindsBySource, melden],
    );

    /** Die Ziele, die Termine annehmen. */
    const writableSources = sources.filter((quelle) => quelle.writable === true);

    /**
     * Der Satz Ziele, als EIN Wort — der Griff, an dem das Vorladen haengt.
     *
     * Nicht die Liste selbst: Die ist bei jedem Rendern ein neues Objekt, und
     * ein Effekt, der daran haengt, laeuft bei jedem Rendern erneut. Genau das
     * war der Fehler, bis das andere System zu drosseln anfing.
     */
    const zielsatz = writableSources.map((quelle) => quelle.key).join(',');
    const geladenFuer = useRef<string | null>(null);

    useEffect(() => {
        if (! preloadKinds || zielsatz === '' || geladenFuer.current === zielsatz) {
            return;
        }

        // Vor dem Holen vermerkt, nicht danach: Zwischen Start und Antwort
        // liegen Renderdurchlaeufe, und jeder davon wuerde den Aufruf sonst
        // noch einmal auslösen.
        geladenFuer.current = zielsatz;
        setLoadingKinds(true);

        void (async () => {
            // Parallel, nicht hintereinander: Vier Systeme nacheinander zu
            // fragen heisst, viermal auf das langsamste zu warten.
            const ergebnisse = await Promise.all(
                zielsatz.split(',').map(async (key) => [key, await artenHolen(key)] as const),
            );

            const gefunden: Record<string, RemoteKind[]> = {};
            const ausgefallen: string[] = [];

            for (const [key, ergebnis] of ergebnisse) {
                if ('fehler' in ergebnis) {
                    ausgefallen.push(key);

                    continue;
                }

                gefunden[key] = ergebnis.kinds;
            }

            setKindsBySource((bisher) => ({ ...bisher, ...gefunden }));
            // Hier wird NICHT `melden` gerufen. Ein ausgefallenes Ziel ist
            // beim Vorladen kein Fehler des Vorgangs, den jemand ausgeloest
            // hat — es ist ein Hinweis an der Liste: Diese Arten fehlen, die
            // uebrigen gelten. Eine rote Meldung ueber der Maske waere eine
            // Fehlermeldung fuer etwas, das noch niemand versucht hat.
            setFehlgeschlageneZiele(ausgefallen);
            setLoadingKinds(false);
        })();
    }, [artenHolen, preloadKinds, zielsatz]);

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
                        meeting_url: draft.meetingUrl || null,
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

                return true;
            } catch {
                melden('Das andere System hat nicht geantwortet.');

                return false;
            } finally {
                setBusy(false);
                onDone?.();
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

            return true;
        } catch {
            melden(fehlermeldung);

            return false;
        } finally {
            setBusy(false);
            onDone?.();
        }
    }

    /**
     * Alle ausgefallenen Ziele — gemeldete und beim Abruf weggebliebene.
     *
     * Zwei Wege, ein Ergebnis, und die Oberflaeche soll den Unterschied nicht
     * kennen muessen: Fuer den Menschen davor ist „das System hat auf die
     * Freigabefrage nicht geantwortet" und „das System hat auf den Artenabruf
     * nicht geantwortet" dieselbe Aussage — seine Arten fehlen in der Liste.
     *
     * Die Reihenfolge ist die der Zielliste und nicht die des Eintreffens:
     * Ein Hinweis, dessen Systeme bei jedem Rendern die Plaetze tauschen,
     * liest sich wie eine Aenderung.
     */
    const unreachableSources = [
        ...sources.filter((quelle) => quelle.unreachable === true).map((quelle) => quelle.key),
        ...fehlgeschlageneZiele.filter(
            (key) => sources.find((quelle) => quelle.key === key)?.unreachable !== true,
        ),
    ];

    /**
     * Die Ziele, die GEANTWORTET und abgelehnt haben.
     *
     * Eine eigene Liste und nicht Teil von {@link unreachableSources} — siehe
     * `not_allowed` am {@link RemoteSource}. Fuer den Menschen davor sind es
     * zwei verschiedene Saetze: „niemand hat geantwortet" laedt zum
     * Wiederholen ein, „der Zugriff wurde abgelehnt" nicht.
     *
     * Nur der GEMELDETE Weg, anders als oben: Ein abgelehntes Ziel ist nicht
     * `writable` und wird deshalb nie vorgeladen — es kann also gar nicht aus
     * einem fehlgeschlagenen Artenabruf kommen. Ein Ausfall BEIM Abruf heisst
     * „hat nicht geantwortet" und gehoert nach oben; ihn hier
     * hereinzurechnen waere eine Behauptung ueber einen Grund, den wir nicht
     * kennen.
     */
    const notAllowedSources = sources
        .filter((quelle) => quelle.not_allowed === true)
        .map((quelle) => quelle.key);

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
        writableSources,
        /** Die Arten je Ziel, soweit geholt. Nur mit `preloadKinds` vollstaendig. */
        kindsBySource,
        /** Die Schluessel ALLER Ziele, die nicht geantwortet haben — gemeldete und beim Abruf weggebliebene. */
        unreachableSources,
        /** Die Schluessel der Ziele, die geantwortet und den Zugriff ABGELEHNT haben. */
        notAllowedSources,
        /** Laeuft das Vorladen der Arten noch? */
        loadingKinds,
    };
}
