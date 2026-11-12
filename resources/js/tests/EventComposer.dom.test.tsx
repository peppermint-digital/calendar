/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { EventComposer } from '../src/EventComposer';
import { emptyDraft, type EventKind } from '../src/types';
import type { RemoteSource } from '../src/useRemoteTarget';

/**
 * Die vollstaendige Terminmaske aus dem Paket (#7765).
 *
 * Sie liegt hier, weil bis zum 15.10.2026 jedes System denselben Vorgang selbst
 * zusammensetzte — und die vier Fassungen auseinanderliefen. Diese Tests halten
 * fest, was ueberall gleich sein muss.
 */
const EIGENE: EventKind[] = [
    { key: 'crm_termin', label: 'Termin', creatable: true },
];

const FREMDE_ARTEN = [
    // `system` ist Absicht und darf NICHT gezeichnet werden: Die Herkunft setzt
    // der Fragende, nicht der Befragte (Wiki #595).
    { key: 'business', label: 'Geschäftlich', system: 'So nenne ich mich selbst' },
    { key: 'meeting_digital', label: 'Meeting digital', requires: ['meeting_url'] },
];

const ZIELE: RemoteSource[] = [
    { key: 'manager', label: 'Manager', writable: true },
    { key: 'nur-lesen', label: 'Feiertage', writable: false },
];

/** Die Beschriftungen der Artenliste, in der Reihenfolge, in der sie stehen. */
function artenliste(): string[] {
    return Array.from(document.querySelectorAll('[data-slot="kind-picker"] button')).map((knopf) =>
        (knopf.textContent ?? '').trim(),
    );
}

let anfragen: Array<{ url: string; init?: RequestInit }>;

function antwortet(daten: unknown, ok = true, status = 200) {
    return Promise.resolve({ ok, status, json: () => Promise.resolve(daten) } as Response);
}

beforeEach(() => {
    anfragen = [];
    vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
        anfragen.push({ url, init });

        return url.endsWith('/kinds') ? antwortet({ kinds: FREMDE_ARTEN }) : antwortet({ id: 'manager:7' });
    });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

/**
 * Die Stufe „Terminart" (#9796).
 *
 * Hier stand bis zum 11.11.2026 „die Zielwahl": eine Klappliste „Anlegen in"
 * ueber der Maske, und danach die Arten des gewaehlten Systems. Die Tests sind
 * umgeschrieben und nicht geloescht, weil der GEGENSTAND derselbe ist — wohin
 * der Termin geht und welche Arten dabei zur Wahl stehen.
 *
 * Was sich geaendert hat, ist die Reihenfolge der Fragen, und das ist eine
 * Entscheidung und keine Umgestaltung: Wer einen Geschaeftstermin anlegen will,
 * weiss, dass es ein Geschaeftstermin ist. In welchem der vier Systeme diese
 * Art gefuehrt wird, ist eine Eigenschaft der Art — und eine Klappliste davor
 * verlangte eine Antwort auf eine Frage, die niemand gestellt hat. Jetzt waehlt
 * man die Art, und das System steht klein daneben.
 */
describe('die Terminart-Stufe', () => {
    it('zeigt alle Arten in EINER Liste, die eigenen zuerst', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        // Keine Gruppierung nach System: Das zerlegte die Liste wieder in die
        // Produktkaestchen, die sie gerade aufloest (Wiki #595).
        await waitFor(() =>
            expect(artenliste()).toEqual(['Termin', 'Geschäftlich(Manager)', 'Meeting digital(Manager)']),
        );
    });

    /*
     * Bug #1417: Ein unerreichbares Ziel meldet sich, statt zu verschwinden.
     *
     * Der private Kalender der Nabe verschwand LAUTLOS aus dieser Maske, wenn
     * die Nabe nicht erreichbar war: `isWritable()` war `false`, und damit war
     * die Quelle aus `beschreibbareZiele()` heraus, BEVOR die Maske sie sah.
     * Sie konnte nie in `targets` und nie in `unreachableSources` landen — der
     * `unreachable`-Zweig des KindPickers war in keinem Produkt erreichbar.
     *
     * Seit dem 12.11.2026 markiert `EventSourceRegistry::ziele()` sie:
     * `writable: false` (sie ist NICHT beschreibbar, und ein Knopf, der
     * drueben scheitert, ist schlechter als kein Knopf) und zusaetzlich
     * `unreachable: true`.
     *
     * Dieser Test ist der Durchstich: ein Zieleintrag, wie das Paket ihn bei
     * unerreichbarer Nabe herausgibt — und der Hinweis muss feuern.
     */
    it('meldet ein ausgefallenes Ziel, statt es verschwinden zu lassen', async () => {
        const MIT_AUSFALL: RemoteSource[] = [
            { key: 'manager', label: 'Manager', writable: true },
            // Genau die Form, die `ziele()` bei unerreichbarer Nabe liefert.
            { key: 'brain-privat', label: 'AI Brain', writable: false, unreachable: true },
        ];

        render(<EventComposer kinds={EIGENE} targets={MIT_AUSFALL} onSaveHere={vi.fn()} />);

        const hinweis = await screen.findByRole('status');

        // MIT NAMEN, nicht mit dem Schluessel: Der Name kommt aus der lokalen
        // Beschriftung der Quelle (Wiki #595).
        expect(hinweis.textContent).toContain('AI Brain');
        expect(hinweis.textContent).not.toContain('brain-privat');

        // Und die uebrigen Arten bleiben waehlbar — ein Ausfall nimmt die
        // Maske nicht mit.
        await waitFor(() => expect(artenliste()).toContain('Geschäftlich(Manager)'));
    });

    it('macht ein ausgefallenes Ziel NICHT waehlbar', async () => {
        // Die zweite Haelfte, und die wichtigere: Waere es waehlbar, scheiterte
        // das Anlegen erst beim Speichern — schlechter als der Zustand vor der
        // Behebung, in dem es nur lautlos verschwand.
        const MIT_AUSFALL: RemoteSource[] = [
            { key: 'manager', label: 'Manager', writable: true },
            { key: 'brain-privat', label: 'AI Brain', writable: false, unreachable: true },
        ];

        render(<EventComposer kinds={EIGENE} targets={MIT_AUSFALL} onSaveHere={vi.fn()} />);

        await waitFor(() => expect(artenliste()).toHaveLength(3));

        // Keine Zeile traegt den Namen des ausgefallenen Systems.
        expect(artenliste().some((zeile) => zeile.includes('AI Brain'))).toBe(false);

        // Und es wurde auch nicht nach seinen Arten gefragt: Ein Ziel, das
        // nicht beschreibbar ist, wird nicht vorgeladen.
        expect(anfragen.some((anfrage) => anfrage.url.includes('brain-privat'))).toBe(false);
    });

    it('schreibt den Systemnamen nur an die FREMDEN Zeilen', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        await waitFor(() => expect(artenliste()).toHaveLength(3));

        // Bei einer eigenen Art beantwortet der Name „hier", und das ist die
        // Vorgabe. Das Paket kennt den Namen des eigenen Systems ausserdem gar
        // nicht — eine Beschriftung tragen nur die fremden Quellen.
        expect(artenliste()[0]).toBe('Termin');
        expect(artenliste()[1]).toContain('(Manager)');
    });

    it('nimmt den Systemnamen aus der eigenen Quelle, nicht aus der Antwort des Systems', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        await waitFor(() => expect(artenliste()).toHaveLength(3));

        // Ein System weiss nicht, unter welchem Kuerzel es anderswo gefuehrt
        // wird. Duerfte es sich selbst eines geben, stuenden in vier
        // Oberflaechen vier verschiedene Woerter fuer dasselbe System.
        expect(screen.queryByText(/So nenne ich mich selbst/)).toBeNull();
    });

    it('zeigt keine Arten von Systemen, die gar nichts annehmen', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        await waitFor(() => expect(artenliste()).toHaveLength(3));

        // „Feiertage" ist lesbar, aber nicht beschreibbar — eine Art daraus
        // waere eine Einladung zu einem Vorgang, den das Zielsystem abweist.
        // Und es wird dort auch nicht nachgefragt.
        expect(artenliste().some((zeile) => zeile.includes('Feiertage'))).toBe(false);
        expect(anfragen.filter((a) => a.url.includes('nur-lesen'))).toHaveLength(0);
    });

    it('laesst Arten weg, die von Hand nicht anlegbar sind', async () => {
        // `creatable` reiste bis zum 11.11.2026 die ganze Strecke und wurde
        // nirgends gelesen: Die Liste ging ungefiltert ins Formular, und dort
        // standen Arten, die beim Speichern abgelehnt werden.
        render(
            <EventComposer
                kinds={[
                    ...EIGENE,
                    { key: 'urlaub', label: 'Urlaub', creatable: false },
                    { key: 'block', label: 'Aufgaben-Zeitblock', creatable: false },
                ]}
                targets={ZIELE}
                onSaveHere={vi.fn()}
            />,
        );

        await waitFor(() => expect(artenliste()).toHaveLength(3));
        expect(artenliste().some((zeile) => zeile.includes('Urlaub'))).toBe(false);
        expect(artenliste().some((zeile) => zeile.includes('Zeitblock'))).toBe(false);
    });

    it('laesst auch eine nicht anlegbare FREMDE Art weg', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return url.endsWith('/kinds')
                ? antwortet({
                      kinds: [
                          { key: 'business', label: 'Geschäftlich', creatable: true },
                          { key: 'leave', label: 'Urlaub', creatable: false },
                      ],
                  })
                : antwortet({ id: 'manager:7' });
        });

        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        await waitFor(() => expect(artenliste()).toHaveLength(2));
        expect(artenliste()).toEqual(['Termin', 'Geschäftlich(Manager)']);
    });

    it('erscheint beim Bearbeiten gar nicht', () => {
        // Ein Termin, den es schon gibt, hat seine Art und sein System. Ihn
        // umzuziehen waere ein anderer Vorgang als ihn zu aendern.
        const { container } = render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                value={{ ...emptyDraft(), kind: 'crm_termin', title: 'Bestehender Termin' }}
                onSaveHere={vi.fn()}
            />,
        );

        expect(container.querySelector('[data-slot="kind-picker"]')).toBeNull();
        // Und es wird dafuer auch nichts geholt.
        expect(anfragen.filter((a) => a.url.endsWith('/kinds'))).toHaveLength(0);
    });

    it('fragt nicht, wo es nur eine Art gibt', () => {
        // Verwaltung und CRM fuehren je EINE anlegbare Art. Eine Liste mit
        // einem Eintrag ist eine Frage, die keine ist.
        const { container } = render(<EventComposer kinds={EIGENE} onSaveHere={vi.fn()} />);

        expect(container.querySelector('[data-slot="kind-picker"]')).toBeNull();
        expect(screen.getByLabelText('Titel')).toBeTruthy();
    });

    it('holt die Arten ALLER Ziele beim Oeffnen, einmal und parallel', async () => {
        const { rerender } = render(
            <EventComposer
                kinds={EIGENE}
                targets={[
                    { key: 'manager', label: 'Manager', writable: true },
                    { key: 'verwaltung', label: 'Verwaltung', writable: true },
                ]}
                onSaveHere={vi.fn()}
            />,
        );

        // Beim Oeffnen, nicht beim Auswaehlen: Die Liste zeigt alle Arten
        // sofort, also muessen sie vorher da sein.
        await waitFor(() => expect(anfragen.filter((a) => a.url.endsWith('/kinds'))).toHaveLength(2));

        rerender(
            <EventComposer
                kinds={EIGENE}
                targets={[
                    { key: 'manager', label: 'Manager', writable: true },
                    { key: 'verwaltung', label: 'Verwaltung', writable: true },
                ]}
                onSaveHere={vi.fn()}
            />,
        );
        rerender(
            <EventComposer
                kinds={EIGENE}
                targets={[
                    { key: 'manager', label: 'Manager', writable: true },
                    { key: 'verwaltung', label: 'Verwaltung', writable: true },
                ]}
                onSaveHere={vi.fn()}
            />,
        );

        // Ein Netzweg je Rendern waere eine Schleife, die niemand bemerkt, bis
        // das andere System drosselt.
        expect(anfragen.filter((a) => a.url.endsWith('/kinds'))).toHaveLength(2);
    });

    it('holt die Arten eines Ziels nicht noch einmal, wenn es gewaehlt wird', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        await waitFor(() => expect(artenliste()).toHaveLength(3));
        expect(anfragen.filter((a) => a.url.endsWith('/kinds'))).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: /Geschäftlich/ }));

        // Die Arten sind schon da. Sie beim Anklicken erneut zu holen waere
        // derselbe Aufruf ein zweites Mal — in einem fremden System.
        await waitFor(() => expect(screen.getByLabelText('Titel')).toBeTruthy());
        expect(anfragen.filter((a) => a.url.endsWith('/kinds'))).toHaveLength(1);
    });
});

/**
 * „Niemand ist zustaendig" und „ich konnte nicht nachsehen" sind zwei Aussagen
 * (Wiki #595). Ein Systemausfall darf nicht als leere Liste erscheinen.
 */
describe('ein Ziel, das nicht antwortet', () => {
    function zieleMitAusfall() {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            if (url.includes('/sources/verwaltung/kinds')) {
                return antwortet(null, false, 503);
            }

            return url.endsWith('/kinds') ? antwortet({ kinds: FREMDE_ARTEN }) : antwortet({ id: 'manager:7' });
        });

        return [
            { key: 'manager', label: 'Manager', writable: true },
            { key: 'verwaltung', label: 'Verwaltung', writable: true },
        ] as RemoteSource[];
    }

    it('steht als Hinweis mit Namen da', async () => {
        render(<EventComposer kinds={EIGENE} targets={zieleMitAusfall()} onSaveHere={vi.fn()} />);

        const hinweis = await screen.findByRole('status');
        expect(hinweis.textContent).toContain('Verwaltung');
    });

    it('laesst die uebrigen Arten waehlbar', async () => {
        render(<EventComposer kinds={EIGENE} targets={zieleMitAusfall()} onSaveHere={vi.fn()} />);

        await screen.findByRole('status');

        // Die Arten des antwortenden Systems bleiben vollstaendig — ein
        // Ausfall woanders ist kein Grund, hier weniger anzubieten.
        expect(artenliste()).toEqual(['Termin', 'Geschäftlich(Manager)', 'Meeting digital(Manager)']);

        fireEvent.click(screen.getByRole('button', { name: /Geschäftlich/ }));
        await waitFor(() => expect(screen.getByLabelText('Titel')).toBeTruthy());
    });

    it('haelt die Stufe offen, auch wenn nur eine Art uebrig ist', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return antwortet(null, false, 503);
        });

        const { container } = render(
            <EventComposer
                kinds={EIGENE}
                targets={[{ key: 'manager', label: 'Manager', writable: true }]}
                onSaveHere={vi.fn()}
            />,
        );

        // Uebersprungen waere der Ausfall verschwiegen: Der Mensch stuende im
        // Formular und wuesste nicht, dass ihm Arten fehlen.
        const hinweis = await screen.findByRole('status');
        expect(hinweis.textContent).toContain('Manager');
        expect(container.querySelector('[data-slot="kind-picker"]')).toBeTruthy();
        expect(artenliste()).toEqual(['Termin']);
    });
});

/**
 * `initialTarget` — der Weg „woanders anlegen" aus einem Auswahldialog.
 *
 * Bis zum 11.11.2026 setzte die Angabe die Klappliste „Anlegen in" vorweg. Die
 * Liste gibt es nicht mehr, der Zweck schon: Wer das System schon angeklickt
 * hat, soll es nicht wieder waehlen. Uebersetzt heisst das — die Artenliste
 * zeigt nur noch die Arten DIESES Systems. Die Art muss er weiter waehlen, denn
 * danach hat ihn niemand gefragt.
 */
describe('ein vorgewaehltes Ziel', () => {
    it('engt die Liste auf die Arten dieses Systems ein', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} initialTarget="manager" onSaveHere={vi.fn()} />);

        await waitFor(() =>
            expect(artenliste()).toEqual(['Geschäftlich(Manager)', 'Meeting digital(Manager)']),
        );

        // Die eigene Art faellt weg: Das System ist entschieden.
        expect(artenliste().some((zeile) => zeile === 'Termin')).toBe(false);
    });

    it('legt danach wirklich im vorgewaehlten System an', async () => {
        const gespeichert = vi.fn();

        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                initialTarget="manager"
                onSaveHere={gespeichert}
                onSavedThere={vi.fn()}
            />,
        );

        fireEvent.click(await screen.findByRole('button', { name: /Geschäftlich/ }));

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Aus dem Dialog' } });
        fireEvent.change(screen.getByLabelText('Datum'), { target: { value: '2026-11-03' } });
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(anfragen.some((a) => a.url.endsWith('/sources/manager/events'))).toBe(true));
        expect(gespeichert).not.toHaveBeenCalled();
    });

    it('ueberspringt die Stufe, wenn es dort nur eine Art gibt', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return url.endsWith('/kinds')
                ? antwortet({ kinds: [{ key: 'verwaltungstermin', label: 'Verwaltungstermin' }] })
                : antwortet({ id: 'verwaltung:7' });
        });

        const { container } = render(
            <EventComposer
                kinds={EIGENE}
                targets={[{ key: 'verwaltung', label: 'Verwaltung', writable: true }]}
                initialTarget="verwaltung"
                onSaveHere={vi.fn()}
            />,
        );

        // Eine Stufe mit einer Antwort wird nicht gestellt, sondern
        // beantwortet — und die Art ist gesetzt, nicht nur optisch weg.
        await waitFor(() => expect(container.querySelector('[data-slot="kind-picker"]')).toBeNull());
        expect(screen.getByLabelText('Titel')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Ohne Rueckfrage' } });
        fireEvent.change(screen.getByLabelText('Datum'), { target: { value: '2026-11-03' } });
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(anfragen.some((a) => a.url.endsWith('/sources/verwaltung/events'))).toBe(true));
        const anlegen = anfragen.find((a) => a.url.endsWith('/sources/verwaltung/events'));
        expect(JSON.parse(String(anlegen?.init?.body)).kind).toBe('verwaltungstermin');
    });
});

describe('eine fremde Art', () => {
    it('laesst die Maske nach IHREN Regeln zeichnen', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        fireEvent.click(await screen.findByRole('button', { name: /Meeting digital/ }));

        // `requires: ['meeting_url']` ist die Angabe der ZIELART. Ohne sie
        // bekaeme jemand keine Eingabe fuer den Link und nach dem Absenden
        // eine Ablehnung.
        await waitFor(() => expect(screen.getByLabelText(/Meeting/)).toBeTruthy());
    });

    it('blendet die systemeigenen Felder aus', async () => {
        // Wer aus dem CRM im Manager anlegt, hat dort keinen Deal — ein Feld
        // dafuer naehme eine Eingabe entgegen, die nirgends ankommt.
        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                onSaveHere={vi.fn()}
                extraFields={<label htmlFor="deal">Deal</label>}
            />,
        );

        fireEvent.click(await screen.findByRole('button', { name: /Geschäftlich/ }));

        await waitFor(() => expect(screen.getByLabelText('Titel')).toBeTruthy());
        expect(screen.queryByText('Deal')).toBeNull();
    });

    it('kehren die eigenen Felder zurueck, wenn wieder eine eigene Art gewaehlt wird', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                onSaveHere={vi.fn()}
                extraFields={<label htmlFor="deal">Deal</label>}
            />,
        );

        fireEvent.click(await screen.findByRole('button', { name: /Geschäftlich/ }));
        await waitFor(() => expect(screen.queryByText('Deal')).toBeNull());

        // Zurueck ueber die Kette — der Grund, warum sie da ist.
        fireEvent.click(screen.getByRole('button', { name: 'Zurück zu: Terminart' }));
        fireEvent.click(screen.getByRole('button', { name: 'Termin' }));

        await waitFor(() => expect(screen.getByText('Deal')).toBeTruthy());
    });

    it('steht mit System im Kopf des Assistenten', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        fireEvent.click(await screen.findByRole('button', { name: /Geschäftlich/ }));

        // Die getroffene Wahl bleibt sichtbar, waehrend die naechste getroffen
        // wird — sonst muesste man zuruecksprigen, um nachzusehen.
        const kette = document.querySelector('[data-slot="composer-steps"]');
        expect(kette?.textContent).toContain('Geschäftlich (Manager)');
    });
});

/**
 * Die Stufe darf NICHT uebersprungen werden, solange die fremden Arten noch
 * unterwegs sind.
 *
 * Festgehalten auf Bitte des Keepers (11.11.2026), damit es niemand spaeter
 * wegbaut: Es sieht nach einer Feinheit aus und ist keine. Wird am Anfang
 * gezaehlt, hat ein System mit EINER eigenen Art noch keine fremden — die
 * Stufe faellt weg, der Mensch landet im Formular, und Sekunden spaeter
 * springt sie nachtraeglich auf, waehrend er schon tippt.
 */
describe('solange die fremden Arten unterwegs sind', () => {
    it('wird die Stufe nicht uebersprungen, obwohl erst eine Art da ist', async () => {
        let antwort: (ergebnis: Response) => void = () => {};

        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return new Promise<Response>((fertig) => {
                antwort = fertig;
            });
        });

        const { container } = render(
            <EventComposer
                kinds={EIGENE}
                targets={[{ key: 'manager', label: 'Manager', writable: true }]}
                onSaveHere={vi.fn()}
            />,
        );

        // Eine eigene Art, die fremden noch im Netz: Die Stufe steht, und das
        // Formular steht NICHT.
        expect(container.querySelector('[data-slot="kind-picker"]')).toBeTruthy();
        expect(screen.queryByLabelText('Titel')).toBeNull();

        antwort({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ kinds: FREMDE_ARTEN }),
        } as Response);

        await waitFor(() => expect(artenliste()).toHaveLength(3));

        // Und niemand wurde zwischendurch weitergeschubst.
        expect(screen.queryByLabelText('Titel')).toBeNull();
    });

    it('wird sie uebersprungen, sobald klar ist, dass nur eine Art bleibt', async () => {
        // Die Gegenprobe: Erst wenn die Antwort da ist, ist „nur eine Art" eine
        // gemessene Aussage statt einer ueber etwas, das noch unterwegs war.
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return antwortet({ kinds: [] });
        });

        const { container } = render(
            <EventComposer
                kinds={EIGENE}
                targets={[{ key: 'manager', label: 'Manager', writable: true }]}
                onSaveHere={vi.fn()}
            />,
        );

        await waitFor(() => expect(container.querySelector('[data-slot="kind-picker"]')).toBeNull());
        expect(screen.getByLabelText('Titel')).toBeTruthy();
    });
});

/**
 * Die ganze Kette, wie Bastian sie am 11.11.2026 beschrieben hat: „ich muss
 * erst sehen ob aufgabe oder termin, dann die terminarten auswahl sehen und
 * dann das entsprechende einfügen formular bekommen".
 */
describe('die drei Stufen zusammen', () => {
    const offen = [{ id: 7, title: 'Abgabe vorbereiten' }];

    it('fuehrt von der Weiche ueber die Terminart zu den Feldern', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: vi.fn() }}
            />,
        );

        // Stufe 1: die Weiche, und nur sie.
        expect(screen.getByRole('button', { name: 'Aufgabe einplanen' })).toBeTruthy();
        expect(document.querySelector('[data-slot="kind-picker"]')).toBeNull();
        expect(screen.queryByLabelText('Titel')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Termin' }));

        // Stufe 2: die Terminart, noch keine Felder.
        await waitFor(() => expect(artenliste()).toHaveLength(3));
        expect(screen.queryByLabelText('Titel')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Meeting digital/ }));

        // Stufe 3: die Felder — und zwar die der gewaehlten Art.
        await waitFor(() => expect(screen.getByLabelText('Titel')).toBeTruthy());
        expect(screen.getByLabelText(/Meeting/)).toBeTruthy();

        const kette = document.querySelector('[data-slot="composer-steps"]');
        expect(kette?.textContent).toContain('Meeting digital (Manager)');
    });

    /**
     * Gefunden am 11.11.2026 beim Einbau der Aufgaben-Stufen (#9798), vorhanden
     * seit dem Assistenten (#9795): Die Weiche rechnete mit dem Weg, der noch
     * OFFEN war, statt mit dem gerade gewaehlten. Wer in den Aufgaben-Weg sah
     * und dann „Termin" nahm, kam an der Terminart vorbei — direkt ins
     * Formular, mit der Art, die zufaellig im Entwurf stand. Eine uebersprungene
     * Wahl, die niemand getroffen hat.
     */
    it('fuehrt auch nach einem Abstecher in den Aufgaben-Weg wieder auf die Terminart', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: vi.fn() }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Zurück zu: Was' }));
        fireEvent.click(screen.getByRole('button', { name: 'Termin' }));

        await waitFor(() => expect(artenliste()).toHaveLength(3));
        expect(screen.queryByLabelText('Titel')).toBeNull();
    });

    it('fragt im Aufgaben-Weg nicht nach einer Terminart', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: vi.fn() }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));

        // Dort entsteht ein Zeitblock im System der Aufgabe. Eine Terminart
        // hat er nicht, und eine Liste dafuer waere eine Frage ohne Wirkung.
        //
        // Umgeschrieben am 11.11.2026 (#9798): Vorher stand hinter der Weiche
        // SOFORT der Zeitraum („Wann") samt Suche auf einer Stufe. Jetzt
        // kommt zuerst die Aufgabe und der Zeitraum danach — die Aussage des
        // Tests ist unveraendert, nur das, was man als erstes sieht, ist die
        // Aufgabensuche statt des Zeitraums.
        await waitFor(() =>
            expect(document.querySelector('[data-slot="plannable-search"]')).toBeTruthy(),
        );
        expect(document.querySelector('[data-slot="kind-picker"]')).toBeNull();
    });

    it('laesst sich von den Feldern auf die Terminart zurueckspringen', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        fireEvent.click(await screen.findByRole('button', { name: /Frist|Geschäftlich/ }));
        await waitFor(() => expect(screen.getByLabelText('Titel')).toBeTruthy());

        fireEvent.click(screen.getByRole('button', { name: 'Zurück zu: Terminart' }));

        expect(screen.queryByLabelText('Titel')).toBeNull();
        expect(artenliste()).toHaveLength(3);
    });

    it('fragt die Art nicht zweimal — im Formular steht keine Artenliste mehr', async () => {
        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={vi.fn()} />);

        fireEvent.click(await screen.findByRole('button', { name: /Geschäftlich/ }));
        await waitFor(() => expect(screen.getByLabelText('Titel')).toBeTruthy());

        // Wer die Art gerade gewaehlt hat, soll sie nicht gleich darunter noch
        // einmal waehlen. `EventForm` verbirgt seine Auswahl ab einem Eintrag
        // von selbst — es bekommt genau die gewaehlte Art herein.
        expect(screen.queryByLabelText('Terminart')).toBeNull();
    });

    it('belegt den Entwurf mit der ersten ANLEGBAREN Art vor', () => {
        // Ein Produkt, das seine Liste in Registry-Reihenfolge hereingibt, hat
        // vorne vielleicht eine Art stehen, die niemand waehlen darf. Stand sie
        // im Entwurf, wurde der Termin mit ihr abgeschickt.
        const gespeichert = vi.fn();

        render(
            <EventComposer
                kinds={[{ key: 'urlaub', label: 'Urlaub', creatable: false }, ...EIGENE]}
                onSaveHere={gespeichert}
            />,
        );

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Mit Vorgabe' } });
        fireEvent.change(screen.getByLabelText('Datum'), { target: { value: '2026-11-03' } });
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(gespeichert.mock.calls[0][0].kind).toBe('crm_termin');
    });

    it('laesst die Art beim BEARBEITEN weiter aendern', () => {
        // Dort ist es ein gueltiger Vorgang, und die Stufe gibt es nicht.
        render(
            <EventComposer
                kinds={[
                    ...EIGENE,
                    { key: 'crm_intern', label: 'Intern' },
                    { key: 'deal', label: 'Deal-Termin', creatable: false },
                ]}
                value={{ ...emptyDraft(), kind: 'crm_termin', title: 'Steht schon' }}
                onSaveHere={vi.fn()}
            />,
        );

        const wahl = screen.getByLabelText('Terminart') as HTMLSelectElement;

        // Gefiltert wird auch hier — aber `creatable` sagt „darf man das
        // auswaehlen", nicht „darf das existieren".
        expect(Array.from(wahl.options).map((o) => o.textContent)).toEqual(['Termin', 'Intern']);
    });

    it('behaelt die Art eines bestehenden Termins, auch wenn sie nicht anlegbar ist', () => {
        // Ein eingeplanter Block entsteht durchs Hereinziehen einer Aufgabe.
        // Faellt seine Art aus der Liste, waere das ein Formular ohne Regeln —
        // und ohne die Felder, die diese Art traegt.
        render(
            <EventComposer
                kinds={[...EIGENE, { key: 'block', label: 'Aufgaben-Zeitblock', creatable: false }]}
                value={{ ...emptyDraft(), kind: 'block', title: 'Aus einer Aufgabe entstanden' }}
                onSaveHere={vi.fn()}
            />,
        );

        const wahl = screen.getByLabelText('Terminart') as HTMLSelectElement;
        expect(wahl.value).toBe('block');
        expect(Array.from(wahl.options).map((o) => o.textContent)).toEqual([
            'Termin',
            'Aufgaben-Zeitblock',
        ]);
    });
});

describe('der Entwurf', () => {
    it('liegt beim Produkt, wenn es ihn mitbringt', () => {
        // Manche Masken brauchen ihn draussen: Der Manager spiegelt ihn in seine
        // Nutzlast und seine Zusatzfelder lesen ihn mit. Ein Composer, der ihn
        // einschloesse, zwaenge sie zu einem zweiten Zustand daneben.
        const geaendert = vi.fn();
        const entwurf = {
            kind: 'crm_termin',
            title: 'Vom Produkt gehalten',
            date: '2026-11-03',
            endDate: '',
            start: '09:00',
            end: '10:00',
            allDay: false,
            location: '',
            description: '',
            meetingUrl: '',
            category: '',
            categoryId: '',
            recurrence: null,
        };

        render(
            <EventComposer kinds={EIGENE} draft={entwurf} onDraftChange={geaendert} onSaveHere={vi.fn()} />,
        );

        expect((screen.getByLabelText('Titel') as HTMLInputElement).value).toBe('Vom Produkt gehalten');

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Geändert' } });

        // Der Composer schreibt NICHT selbst — er meldet und laesst das Produkt
        // entscheiden. Sonst gaebe es den Entwurf zweimal.
        expect(geaendert).toHaveBeenCalledTimes(1);
        expect(geaendert.mock.calls[0][0].title).toBe('Geändert');
        expect((screen.getByLabelText('Titel') as HTMLInputElement).value).toBe('Vom Produkt gehalten');
    });

    it('liegt beim Composer, wenn das Produkt keinen mitbringt', () => {
        render(<EventComposer kinds={EIGENE} onSaveHere={vi.fn()} />);

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Selbst gehalten' } });

        expect((screen.getByLabelText('Titel') as HTMLInputElement).value).toBe('Selbst gehalten');
    });
});

describe('speichern', () => {
    it('geht im eigenen Kalender ueber das Produkt', async () => {
        const gespeichert = vi.fn();

        render(<EventComposer kinds={EIGENE} targets={ZIELE} onSaveHere={gespeichert} />);

        // Seit dem 11.11.2026 steht die Artenwahl davor: Erst die Art, dann
        // die Felder. Was unveraendert gilt, ist das Ziel des Tests — der
        // eigene Kalender gehoert dem Produkt.
        fireEvent.click(await screen.findByRole('button', { name: 'Termin' }));

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Rückruf' } });
        fireEvent.change(screen.getByLabelText('Datum'), { target: { value: '2026-11-03' } });
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        expect(gespeichert).toHaveBeenCalledTimes(1);
        expect(gespeichert.mock.calls[0][0].title).toBe('Rückruf');
        // Kein Netzweg: Der eigene Kalender gehoert dem Produkt.
        expect(anfragen.filter((a) => a.url.endsWith('/events'))).toHaveLength(0);
    });

    it('geht bei fremdem Ziel ueber das Paket, nicht ueber das Produkt', async () => {
        const gespeichert = vi.fn();
        const fertig = vi.fn();

        render(
            <EventComposer
                kinds={EIGENE}
                targets={ZIELE}
                onSaveHere={gespeichert}
                onSavedThere={fertig}
            />,
        );

        fireEvent.click(await screen.findByRole('button', { name: /Geschäftlich/ }));
        await waitFor(() => expect(screen.getByLabelText('Titel')).toBeTruthy());

        fireEvent.change(screen.getByLabelText('Titel'), { target: { value: 'Abstimmung' } });
        fireEvent.change(screen.getByLabelText('Datum'), { target: { value: '2026-11-03' } });
        fireEvent.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(fertig).toHaveBeenCalled());

        // Der Termin entsteht drueben — das Produkt wird gar nicht gefragt.
        expect(gespeichert).not.toHaveBeenCalled();

        const anlegen = anfragen.find((a) => a.url.endsWith('/sources/manager/events'));
        expect(anlegen).toBeTruthy();
        expect(JSON.parse(String(anlegen?.init?.body)).title).toBe('Abstimmung');
    });
});

/**
 * Der zweite Weg: eine bestehende Aufgabe einplanen.
 *
 * Er ist der Grund, warum der Moduswechsel im Paket steht und nicht in den
 * vier Produkten: „Neuer Termin" fragt überall dasselbe (#7846).
 */
describe('der Aufgaben-Weg', () => {
    const offen = [{ id: 7, title: 'Abgabe vorbereiten' }];

    /** Ohne angestecktes Aufgabensystem gibt es nichts einzuplanen. */
    it('erscheint nicht, wenn das Produkt keine Suche mitgibt', () => {
        render(<EventComposer kinds={EIGENE} value={null} onSaveHere={() => {}} />);

        expect(screen.queryByText('Aufgabe einplanen')).toBeNull();
    });

    it('bietet beide Wege, sobald eine Suche da ist', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: () => {} }}
            />,
        );

        expect(screen.getByRole('button', { name: 'Termin' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Aufgabe einplanen' })).toBeTruthy();
    });

    /**
     * Beim Bearbeiten steht schon fest, was der Eintrag ist — dieselbe
     * Begründung wie bei der Zielwahl darüber.
     */
    it('fehlt beim Bearbeiten', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={{ ...emptyDraft(), kind: 'termin', title: 'Steht schon' }}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: () => {} }}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Aufgabe einplanen' })).toBeNull();
    });

    /**
     * Umgeschrieben am 11.11.2026 (#9798), nicht geloescht: Der Gegenstand ist
     * unveraendert — eingeplant wird mit Zeitraum, nicht nur die Aufgabe. Nur
     * der Weg dorthin hat eine Stufe mehr, und am Ende steht ein Knopf.
     *
     * Dass er dort steht, ist nicht nur die Reihenfolge aus dem Auftrag: Vorher
     * legte der Klick auf einen TREFFER sofort an. Wer in einer Trefferliste
     * danebengreift, hatte damit einen Eintrag erzeugt, den er nicht wollte —
     * und der Zeitraum, den er gar nicht gesehen hatte, stand schon drin.
     */
    it('gibt den Zeitraum mit, nicht nur die Aufgabe', async () => {
        const eingeplant = vi.fn();

        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '14:00', end: '15:30' }}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: eingeplant }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(await screen.findByText('Abgabe vorbereiten'));

        // Die Wahl allein plant NICHT ein.
        expect(eingeplant).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Einplanen' }));

        expect(eingeplant).toHaveBeenCalledWith(
            7,
            { date: '2026-03-12', start: '14:00', end: '15:30' },
            // Woraus eingeplant wird, reist mit: Die Kennung allein ist
            // zweideutig, sobald es mehrere Aufgabensysteme gibt. Leer heisst
            // „es gab keine Systemstufe", wie bei der Terminart.
            { source: '', task: offen[0] },
        );
    });

    /** Eine neue Aufgabe anzulegen gehört dem Aufgabensystem — mit dem Zeitraum. */
    it('nimmt den Zeitraum in den Weg hinaus mit', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '14:00', end: '15:30' }}
                onSaveHere={() => {}}
                plannable={{
                    search: () => Promise.resolve(offen),
                    onSchedule: () => {},
                    newHref: '/tasks/create',
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));

        const hinaus = screen.getByText('Neue Aufgabe anlegen') as HTMLAnchorElement;
        expect(hinaus.getAttribute('href')).toBe('/tasks/create?date=2026-03-12&start=14:00&end=15:30');
    });

    /** Im Termin-Weg bleibt die Maske, wie sie war. */
    /**
     * Bis zum 11.11.2026 stand das Formular sofort da und die Weiche darueber.
     * Seit dem Assistenten ist die Weiche ein eigener Schritt: Erst die Wahl,
     * dann die Felder — das ist der Zweck der Schritte und keine Nebenwirkung.
     * Was unveraendert gilt, ist das Ziel des Tests: Der Termin-Weg fuehrt zu
     * den Feldern, nicht zur Aufgabensuche.
     */
    it('zeigt die Felder, sobald „Termin" gewaehlt ist', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: () => {} }}
            />,
        );

        // Auf der ersten Stufe steht die Wahl allein — noch keine Felder.
        expect(screen.queryByLabelText(/Titel/)).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Termin' }));

        expect(screen.getByLabelText(/Titel/)).toBeTruthy();
    });

    /**
     * Ohne Aufgabensystem gibt es nichts zu fragen. Dann darf der Assistent
     * nicht im Weg stehen: keine Kette, keine Stufe, direkt die Felder.
     */
    it('fragt nicht, wo es nur eine Antwort gibt', () => {
        const { container } = render(
            <EventComposer kinds={EIGENE} value={null} onSaveHere={() => {}} />,
        );

        expect(container.querySelector('[data-slot="composer-steps"]')).toBeNull();
        expect(screen.getByLabelText(/Titel/)).toBeTruthy();
    });

    /** Beim Bearbeiten steht fest, was der Eintrag ist — kein Assistent. */
    it('zeigt beim Bearbeiten keine Schritte', () => {
        const { container } = render(
            <EventComposer
                kinds={EIGENE}
                value={{ ...emptyDraft(), kind: 'crm_termin', title: 'Schon da' }}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: () => {} }}
            />,
        );

        expect(container.querySelector('[data-slot="composer-steps"]')).toBeNull();
        expect(screen.getByLabelText(/Titel/)).toBeTruthy();
    });

    /**
     * Der Rueckweg ist der Grund fuer die Kette. Von den Feldern zurueck auf die
     * Weiche, und die getroffene Wahl steht dort noch.
     */
    it('laesst sich ueber die Kette zurueckspringen', () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={() => {}}
                plannable={{ search: () => Promise.resolve(offen), onSchedule: () => {} }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Termin' }));
        expect(screen.getByLabelText(/Titel/)).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Zurück zu: Was' }));

        expect(screen.queryByLabelText(/Titel/)).toBeNull();
        expect(screen.getByRole('button', { name: 'Aufgabe einplanen' })).toBeTruthy();
    });
});

/**
 * Ein Produkt, das seinen Entwurf selbst hält, gibt die Vorgabe dort hinein —
 * nicht als `preset`. Der markierte Zeitraum stünde sonst im Termin-Weg und
 * fehlte im Aufgaben-Weg daneben (Brain, 16.10.2026).
 */
it('nimmt den Zeitraum auch aus einem gesteuerten Entwurf', async () => {
    const eingeplant = vi.fn();

    render(
        <EventComposer
            kinds={EIGENE}
            draft={{ ...emptyDraft(), kind: 'crm_termin', date: '2026-04-01', start: '11:00', end: '12:00' }}
            onDraftChange={() => {}}
            onSaveHere={() => {}}
            plannable={{ search: () => Promise.resolve([{ id: 3, title: 'Aus dem Entwurf' }]), onSchedule: eingeplant }}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
    fireEvent.click(await screen.findByText('Aus dem Entwurf'));
    // Seit #9798 bestaetigt ein Knopf — der Zeitraum steht dort vorbelegt, und
    // genau das ist der Punkt: einmal bestaetigen, nicht nochmal eingeben.
    fireEvent.click(screen.getByRole('button', { name: 'Einplanen' }));

    expect(eingeplant).toHaveBeenCalledWith(
        3,
        { date: '2026-04-01', start: '11:00', end: '12:00' },
        { source: '', task: { id: 3, title: 'Aus dem Entwurf' } },
    );
});

/**
 * Der Aufgaben-Weg in Stufen: System, Aufgabe, Zeitraum (#9798).
 *
 * Bastian am 11.11.2026, woertlich: „und in der liste zum planen einer aufgabe
 * muss ich ja auch auswählen können aus welchem system und dann am besten pro
 * system die entsprechenden aufgaben". Und die Begruendung, warum das hier
 * anders laeuft als bei den Terminarten: „sonst kriege ich ja einfach eine
 * unendlich lange Liste mit hunderten Aufgaben."
 *
 * Das ist der ganze Unterschied. Zehn Terminarten passen in eine Liste, dort
 * steht das System klein daneben. Hunderte Aufgaben passen nicht, also wird das
 * System vorher gewaehlt.
 */
describe('die Aufgaben-Stufen', () => {
    const SYSTEME = [
        { key: 'manager', label: 'Manager' },
        { key: 'verwaltung', label: 'Verwaltung' },
    ];

    const PRO_SYSTEM: Record<string, Array<{ id: number; title: string }>> = {
        manager: [{ id: 1, title: 'Angebot schreiben' }],
        verwaltung: [{ id: 2, title: 'Ticket beantworten' }],
    };

    /** Merkt sich, wonach gefragt wurde — Begriff und System. */
    function suche(gefragt: Array<unknown[]>) {
        return (...argumente: unknown[]) => {
            gefragt.push(argumente);

            return Promise.resolve(PRO_SYSTEM[String(argumente[1] ?? '')] ?? []);
        };
    }

    it('fuehrt von der Weiche ueber das System zur Aufgabe zum Zeitraum', async () => {
        const eingeplant = vi.fn();
        const gefragt: Array<unknown[]> = [];

        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '14:00', end: '15:30' }}
                onSaveHere={vi.fn()}
                plannable={{ sources: SYSTEME, search: suche(gefragt), onSchedule: eingeplant }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));

        // Stufe: das System — und noch keine Aufgaben. Sie zu laden, bevor das
        // System gewaehlt ist, waere genau die unendliche Liste.
        expect(document.querySelector('[data-slot="task-source-picker"]')).toBeTruthy();
        expect(document.querySelector('[data-slot="plannable-search"]')).toBeNull();
        expect(gefragt).toHaveLength(0);

        fireEvent.click(screen.getByRole('button', { name: 'Verwaltung' }));

        // Stufe: die Aufgaben DIESES Systems, und noch kein Zeitraum.
        expect(await screen.findByText('Ticket beantworten')).toBeTruthy();
        expect(gefragt[0]).toEqual(['', 'verwaltung']);
        expect(screen.queryByText('Angebot schreiben')).toBeNull();
        expect(document.querySelector('[data-slot="plannable-slot"]')).toBeNull();

        fireEvent.click(screen.getByText('Ticket beantworten'));

        // Stufe: der Zeitraum, vorbelegt, mit dem Knopf daneben.
        expect(document.querySelector('[data-slot="plannable-slot"]')).toBeTruthy();

        // Vorbelegt, nicht leer: Wer aus dem Raster kommt, soll einmal
        // bestaetigen und nicht nochmal eingeben. Das Datumsfeld zeigt deutsch
        // und meldet ISO nach aussen (siehe DateField).
        const felder = Array.from(
            document.querySelectorAll('[data-slot="plannable-slot"] input'),
        ) as HTMLInputElement[];
        expect(felder.map((feld) => feld.value)).toContain('12.03.2026');

        // Und die gewaehlte Aufgabe steht beim Knopf, nicht nur klein im Kopf.
        expect(
            document.querySelector('[data-slot="plannable-chosen"]')?.textContent,
        ).toBe('Ticket beantworten');

        fireEvent.click(screen.getByRole('button', { name: 'Einplanen' }));

        expect(eingeplant).toHaveBeenCalledWith(
            2,
            { date: '2026-03-12', start: '14:00', end: '15:30' },
            { source: 'verwaltung', task: PRO_SYSTEM.verwaltung![0] },
        );
    });

    /**
     * Die drei Felder im Zeitraum sind beschriftet (#9808).
     *
     * In der Abnahme (#9799) stand ueber ihnen nur „Wann", und ein Vorleser
     * sagte „Textfeld, Auswahl, Auswahl, Auswahl, Auswahl" — welches Paar der
     * Beginn und welches das Ende war, stand nirgends.
     *
     * Und zwar SICHTBAR beschriftet, mit DENSELBEN Woertern wie dort: Die
     * beiden Zeiten heissen im normalen Formular „Von" und „Bis". Einmal
     * beschriftet und einmal nicht war nicht nur ein Vorleser-Problem, sondern
     * eine Ungleichheit — und zwei Woerter fuer dieselbe Sache waeren die
     * naechste. Geloest ist sie erst, wenn beide Wege gleich aussehen.
     */
    it('beschriftet Datum, Von und Bis im Zeitraum', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '14:00', end: '15:30' }}
                onSaveHere={vi.fn()}
                plannable={{ sources: SYSTEME, search: suche([]), onSchedule: vi.fn() }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Verwaltung' }));
        fireEvent.click(await screen.findByText('Ticket beantworten'));

        const stufe = document.querySelector('[data-slot="plannable-slot"]');

        // Sichtbar: drei Woerter, die man lesen kann — und es sind die des
        // Formulars.
        expect(stufe?.textContent).toContain('Datum');
        expect(stufe?.textContent).toContain('Von');
        expect(stufe?.textContent).toContain('Bis');

        // Beim Datum ist das sichtbare Wort ueber `for` mit dem Feld
        // verknuepft — derselbe Weg wie im Formular. Genau „Datum" und nicht
        // /Datum/: Der Knopf daneben heisst „Datum auswaehlen" und ist eine
        // zweite Handlung mit eigenem Namen.
        const datum = screen.getByLabelText('Datum');
        expect((datum as HTMLInputElement).value).toBe('12.03.2026');
        expect(datum.getAttribute('data-slot')).toBe('date-field');

        // Bei den Uhrzeiten benennt das Wort die GRUPPE aus beiden Listen —
        // `getByRole('group')` und nicht `getByLabelText`, das fuer
        // Bedienelemente gedacht ist.
        expect(screen.getByRole('group', { name: /Von/ }).textContent).toContain('14');
        expect(screen.getByRole('group', { name: /Bis/ }).textContent).toContain('15');

        // Und darin tragen die beiden Listen ihre Rolle.
        expect(screen.getAllByLabelText('Stunde')).toHaveLength(2);
        expect(screen.getAllByLabelText('Minute')).toHaveLength(2);

        // Die Ueberschrift der Stufe bleibt daneben stehen.
        expect(stufe?.textContent).toContain('Wann');
    });

    /** Die Woerter gehoeren dem Produkt, wie alle anderen der Maske. */
    it('laesst die drei Namen ueberschreiben', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{ sources: SYSTEME, search: suche([]), onSchedule: vi.fn() }}
                labels={{ plannableSlotStart: 'Von wann', plannableSlotEnd: 'Bis wann' }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Verwaltung' }));
        fireEvent.click(await screen.findByText('Ticket beantworten'));

        // Sichtbar und als Name der Gruppe — ein Wort, beides.
        expect(screen.getByRole('group', { name: 'Von wann' })).toBeTruthy();
        expect(screen.getByRole('group', { name: 'Bis wann' })).toBeTruthy();
        expect(document.querySelector('[data-slot="plannable-slot"]')?.textContent).toContain('Von wann');
    });

    it('nennt im Kopf, welches System und welche Aufgabe gewaehlt sind', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{ sources: SYSTEME, search: suche([]), onSchedule: vi.fn() }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Manager' }));
        fireEvent.click(await screen.findByText('Angebot schreiben'));

        // Die getroffene Wahl bleibt sichtbar, waehrend die naechste getroffen
        // wird — sonst muesste man zuruecksprigen, um nachzusehen, was man
        // gerade einplant.
        const kette = document.querySelector('[data-slot="composer-steps"]');
        expect(kette?.textContent).toContain('Manager');
        expect(kette?.textContent).toContain('Angebot schreiben');
    });

    /**
     * Eine Stufe mit einer einzigen Antwort wird nicht gestellt — sie wird
     * beantwortet. Und „beantwortet" heisst hier: Die Suche fragt dieses System,
     * nicht keines.
     */
    it('entfaellt bei einem einzigen System — und fragt es trotzdem', async () => {
        const gefragt: Array<unknown[]> = [];
        const eingeplant = vi.fn();

        const { container } = render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '09:00', end: '10:00' }}
                onSaveHere={vi.fn()}
                plannable={{
                    sources: [{ key: 'verwaltung', label: 'Verwaltung' }],
                    search: suche(gefragt),
                    onSchedule: eingeplant,
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));

        expect(container.querySelector('[data-slot="task-source-picker"]')).toBeNull();
        expect(await screen.findByText('Ticket beantworten')).toBeTruthy();
        expect(gefragt[0]).toEqual(['', 'verwaltung']);

        fireEvent.click(screen.getByText('Ticket beantworten'));
        fireEvent.click(screen.getByRole('button', { name: 'Einplanen' }));

        // Das System reist mit, obwohl es niemand angeklickt hat.
        expect(eingeplant.mock.calls[0][2]).toEqual({
            source: 'verwaltung',
            task: PRO_SYSTEM.verwaltung![0],
        });
    });

    /**
     * Ohne Systemangabe gibt es die Stufe gar nicht, und die Suche fragt ohne
     * Systembegriff — das ist der Aufruf, den CRM, Verwaltung und Brain heute
     * uebergeben. Keine Zeile Aenderung dort.
     */
    it('fehlt ganz, wo das Produkt keine Systeme nennt', async () => {
        const gefragt: Array<unknown[]> = [];
        const eingeplant = vi.fn();

        const { container } = render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '09:00', end: '10:00' }}
                onSaveHere={vi.fn()}
                plannable={{
                    search: (begriff: string) => {
                        gefragt.push([begriff]);

                        return Promise.resolve([{ id: 5, title: 'Ohne System' }]);
                    },
                    onSchedule: eingeplant,
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));

        expect(container.querySelector('[data-slot="task-source-picker"]')).toBeNull();
        expect(await screen.findByText('Ohne System')).toBeTruthy();

        fireEvent.click(screen.getByText('Ohne System'));
        fireEvent.click(screen.getByRole('button', { name: 'Einplanen' }));

        // Leer heisst „es gab keine Systemstufe" — dieselbe Regel wie bei der
        // Terminart, wo leer „der eigene Kalender" heisst.
        expect(eingeplant.mock.calls[0][2]).toEqual({
            source: '',
            task: { id: 5, title: 'Ohne System' },
        });
    });

    /**
     * Die drei Zustaende, durch den Composer hindurch (Wiki #595). Entscheidend
     * ist der Name: Er kommt aus der LOKALEN Beschriftung des Systems, nicht
     * aus der Antwort der Gegenstelle.
     */
    it('zeigt den Grund mit Namen, wenn das gewaehlte System nicht antwortet', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{
                    sources: SYSTEME,
                    // Umgeschrieben am 11.11.2026 (#9810): Der Ausfall ist
                    // ein Objekt mit typisiertem Grund (`failures`) statt eines
                    // Satzes (`failed`). Festgehalten wird weiter dasselbe —
                    // der Name kommt aus der LOKALEN Beschriftung, nicht aus
                    // der Antwort der Gegenstelle.
                    search: async () => ({
                        tasks: [],
                        ok: [],
                        failures: {
                            verwaltung: {
                                source: 'verwaltung',
                                reason: 'unreachable',
                                message: 'Zeitüberschreitung nach 5s',
                                may_retry: true,
                            },
                        },
                    }),
                    onSchedule: vi.fn(),
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Verwaltung' }));

        const hinweis = await screen.findByRole('status');
        expect(hinweis.textContent).toContain('Verwaltung');
        expect(hinweis.textContent).toContain('Zeitüberschreitung nach 5s');

        // „Nichts offen" waere hier eine Auskunft, die niemand geben kann.
        expect(screen.queryByTestId('plannable-empty')).toBeNull();
    });

    it('sagt „nichts offen" nur, wenn das System geantwortet hat', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{
                    sources: SYSTEME,
                    search: async () => ({ tasks: [], ok: ['manager'] }),
                    onSchedule: vi.fn(),
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Manager' }));

        expect(await screen.findByTestId('plannable-empty')).toBeTruthy();
        expect(screen.queryByRole('status')).toBeNull();
    });

    /**
     * Nicht ueberspringen und nicht weiterschieben, solange die Antwort noch
     * unterwegs ist — der Fang der Terminart-Biene, hier an der Stelle, an die
     * er gehoert: Die Systemliste ist lokale Angabe und kann nicht nachtraeglich
     * wachsen; gefragt wird erst hier.
     */
    it('schiebt niemanden weiter, solange die Ernte laeuft', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{
                    sources: SYSTEME,
                    search: () => new Promise(() => {}),
                    onSchedule: vi.fn(),
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Manager' }));

        // Die Stufe steht, der Zeitraum steht NICHT — und es steht weder eine
        // Leermeldung noch ein Ausfall da. Beides waere eine Aussage ueber
        // etwas, das noch laeuft.
        expect(document.querySelector('[data-slot="plannable-search"]')).toBeTruthy();
        expect(document.querySelector('[data-slot="plannable-slot"]')).toBeNull();
        expect(screen.queryByTestId('plannable-empty')).toBeNull();
        expect(screen.queryByRole('status')).toBeNull();
    });

    /**
     * Ueber die Kette zurueck und ein anderes System gewaehlt: Die Aufgabe des
     * vorigen ist dann keine Wahl mehr. Blieb sie stehen, plante der Knopf auf
     * der letzten Stufe etwas ein, was auf der Stufe davor nicht mehr sichtbar
     * war.
     */
    it('wirft die gewaehlte Aufgabe weg, wenn das System gewechselt wird', async () => {
        const eingeplant = vi.fn();

        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{ sources: SYSTEME, search: suche([]), onSchedule: eingeplant }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Manager' }));
        fireEvent.click(await screen.findByText('Angebot schreiben'));

        expect(document.querySelector('[data-slot="plannable-slot"]')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Zurück zu: Aufgabensystem' }));
        fireEvent.click(screen.getByRole('button', { name: 'Verwaltung' }));

        // Die Aufgaben des neuen Systems stehen da, die alte Wahl ist weg —
        // auch im Kopf.
        expect(await screen.findByText('Ticket beantworten')).toBeTruthy();
        const kette = document.querySelector('[data-slot="composer-steps"]');
        expect(kette?.textContent).not.toContain('Angebot schreiben');
        expect(eingeplant).not.toHaveBeenCalled();
    });

    /**
     * `busy` fuehrte dieser Typ seit dem 16.10.2026, und NIEMAND las es. Ein
     * Platz, den ein Produkt fuellen kann und den keiner abholt, ist dasselbe
     * Uebel wie ein Platz, aus dem gelesen und den keiner fuellt (Wiki #595).
     * Erst mit dem bestaetigenden Knopf gab es die Stelle, an die es gehoert.
     */
    it('sperrt den Einplanen-Knopf, solange das Einplanen laeuft', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                onSaveHere={vi.fn()}
                plannable={{
                    sources: SYSTEME,
                    search: suche([]),
                    onSchedule: vi.fn(),
                    busy: true,
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Manager' }));
        fireEvent.click(await screen.findByText('Angebot schreiben'));

        // Sonst legen zwei Klicks zwei Bloecke an.
        expect((screen.getByRole('button', { name: 'Einplanen' }) as HTMLButtonElement).disabled).toBe(
            true,
        );
    });

    /** Eine neue Aufgabe anzulegen gehoert dem Aufgabensystem — mit dem Zeitraum. */
    it('fuehrt den Weg hinaus an der Aufgaben-Stufe, wo die Aufgabe fehlt', async () => {
        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                preset={{ date: '2026-03-12', start: '14:00', end: '15:30' }}
                onSaveHere={vi.fn()}
                plannable={{
                    sources: SYSTEME,
                    search: async () => ({ tasks: [], ok: ['manager'] }),
                    onSchedule: vi.fn(),
                    newHref: '/tasks/create',
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Aufgabe einplanen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Manager' }));

        // Dort, wo man merkt, dass es die Aufgabe nicht gibt — und der
        // vorbelegte Zeitraum reist schon mit, obwohl er erst eine Stufe
        // spaeter bestaetigt wird.
        const hinaus = (await screen.findByText('Neue Aufgabe anlegen')) as HTMLAnchorElement;
        expect(hinaus.getAttribute('href')).toBe('/tasks/create?date=2026-03-12&start=14:00&end=15:30');
    });
});

/**
 * Ein Ziel, das nicht antwortet (Bug #1416).
 *
 * Die andere Haelfte der Kette, deren PHP-Seite in `RemoteEventSourceTest` und
 * `EventSourceControllerTest` liegt: Der Arten-Abruf eines unerreichbaren
 * Zielsystems scheitert jetzt mit 502, statt mit 200 und einer leeren Liste zu
 * antworten. Erst damit kann die Maske den Unterschied sagen, um den es geht —
 * „niemand ist zustaendig" und „ich konnte nicht nachsehen" sind zwei Aussagen
 * (Wiki #595).
 *
 * Bis zum 11.11.2026 war der Hinweis am KindPicker unerreichbarer Code: Der
 * Prop war gebaut, der Text stand, der Test im Paket gruente — und in der
 * Produktion kam nie ein Wert an.
 */
describe('ein Ziel, das nicht antwortet', () => {
    /** Der Arten-Abruf scheitert, alles andere antwortet. */
    function artenFallenAus() {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return url.endsWith('/kinds')
                ? antwortet({ message: 'Verwaltung nicht erreichbar' }, false, 502)
                : antwortet({ id: 'verwaltung:7' });
        });
    }

    it('nennt das System beim Namen, statt seine Arten einfach weglassen', async () => {
        artenFallenAus();

        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                targets={[{ key: 'verwaltung', label: 'Verwaltung', writable: true }]}
                onSaveHere={vi.fn()}
            />,
        );

        // Der LOKALE Name, nicht der Schluessel: Die Herkunft setzt der
        // Fragende, nicht der Befragte (Wiki #595).
        const hinweis = await screen.findByText(/Nicht erreichbar: Verwaltung/);
        expect(hinweis.textContent).toContain('die übrigen sind vollständig');
        expect(hinweis.textContent).not.toContain('verwaltung');
    });

    /**
     * Ein Ausfall nimmt niemandem die Liste weg — dieselbe Regel wie an der
     * Aufgabensuche. Wer seine eigene Art waehlen will, kann das weiter.
     */
    it('laesst die uebrigen Arten waehlbar', async () => {
        artenFallenAus();

        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                targets={[{ key: 'verwaltung', label: 'Verwaltung', writable: true }]}
                onSaveHere={vi.fn()}
            />,
        );

        await screen.findByText(/Nicht erreichbar: Verwaltung/);

        expect(artenliste()).toContain('Termin');
    });

    /**
     * Und die Gegenprobe, ohne die der Test nur „zeigt irgendwann irgendwas":
     * Antwortet der Abruf mit 200 und einer leeren Liste — die Form, die vor
     * Bug #1416 vom Server kam —, erscheint der Hinweis NICHT. Das war der
     * Fehler, und es ist der Unterschied, den die PHP-Seite herstellt.
     */
    it('schweigt bei 200 mit leerer Artenliste — der alten Antwortform', async () => {
        vi.stubGlobal('fetch', (url: string, init?: RequestInit) => {
            anfragen.push({ url, init });

            return url.endsWith('/kinds') ? antwortet({ kinds: [] }) : antwortet({ id: 'verwaltung:7' });
        });

        render(
            <EventComposer
                kinds={EIGENE}
                value={null}
                targets={[{ key: 'verwaltung', label: 'Verwaltung', writable: true }]}
                onSaveHere={vi.fn()}
            />,
        );

        await screen.findByText('Was für ein Termin?');
        await waitFor(() => expect(anfragen.some((a) => a.url.endsWith('/kinds'))).toBe(true));

        expect(screen.queryByText(/Nicht erreichbar/)).toBeNull();
    });
});
