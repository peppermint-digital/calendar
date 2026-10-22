/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { EventForm } from '../src/EventForm';
import { emptyDraft, emptyRecurrence, type EventDraft, type EventKind } from '../src/types';

afterEach(cleanup);

const kind = (over: Partial<EventKind> = {}): EventKind => ({ key: 'private', label: 'Privat', ...over });

const draft = (over: Partial<EventDraft> = {}): EventDraft => ({
    ...emptyDraft('private'),
    title: 'Zahnarzt',
    date: '2026-09-14',
    ...over,
});

function show(props: Partial<Parameters<typeof EventForm>[0]> = {}) {
    return render(
        <EventForm
            kinds={[kind()]}
            value={draft()}
            onChange={() => {}}
            onSubmit={() => {}}
            {...props}
        />,
    );
}

describe('EventForm', () => {
    it('laesst weg, was die Art verbietet', () => {
        show({ kinds: [kind({ forbids: ['meeting_url', 'recurrence_rules'] })] });

        expect(screen.queryByLabelText('Meeting-Link')).toBeNull();
        expect(screen.queryByText('Wiederholt sich')).toBeNull();
        expect(screen.queryByLabelText('Ort')).not.toBeNull();
    });

    it('stellt keine Frage, wenn es nur eine Antwort gibt', () => {
        show();
        expect(screen.queryByLabelText('Terminart')).toBeNull();

        cleanup();
        show({ kinds: [kind(), kind({ key: 'business', label: 'Geschaeftlich' })] });
        expect(screen.queryByLabelText('Terminart')).not.toBeNull();
    });

    /**
     * Gesucht wird die Feldgruppe — der Kasten, in dem die Beschriftung
     * steht —, nicht das unmittelbare Elternelement.
     *
     * Grund: Seit dem 22.10.2026 umschliesst `DateField` sein Eingabefeld mit
     * einem eigenen Rahmen (darin sitzt der Knopf fuer die Datumsauswahl,
     * #1070). Ein `parentElement` traefe seitdem diesen Rahmen statt der
     * Gruppe — und der Test waere rot, obwohl die Meldung unveraendert unter
     * dem Feld steht. Ueber die Beschriftung zu suchen ist unabhaengig davon,
     * wie tief ein Feld verschachtelt ist.
     */
    const gruppeVon = (feld: HTMLElement): HTMLElement => {
        let knoten = feld.parentElement;

        while (knoten && ! knoten.querySelector(':scope > label')) {
            knoten = knoten.parentElement;
        }

        if (! knoten) {
            throw new Error('Keine Feldgruppe mit Beschriftung gefunden.');
        }

        return knoten;
    };

    it('haengt die Beanstandung unter das Feld, das sie meint', () => {
        show({
            value: draft({ recurrence: emptyRecurrence() }),
            fieldErrors: { location: 'Ort fehlt', 'recurrence.until': 'Enddatum fehlt' },
        });

        // Nicht nur „steht irgendwo": Der Fehler muss beim richtigen Feld haengen.
        expect(gruppeVon(screen.getByLabelText('Ort')).textContent).toContain('Ort fehlt');
        expect(gruppeVon(screen.getByLabelText('Wiederholen bis *')).textContent).toContain('Enddatum fehlt');

        // Und nicht beim falschen — ohne das waere ein Treffer im ganzen
        // Formular schon genug, und genau davon handelt dieser Test nicht.
        expect(gruppeVon(screen.getByLabelText('Ort')).textContent).not.toContain('Enddatum fehlt');
    });

    it('laesst nicht absenden, solange die Art noch etwas verlangt', () => {
        const onSubmit = vi.fn();
        show({ kinds: [kind({ requires: ['location'] })], onSubmit });

        const knopf = screen.getByRole('button', { name: 'Speichern' });
        expect(knopf).toHaveProperty('disabled', true);

        fireEvent.click(knopf);
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('zeigt ein Enddatum nur, wo der Kalender Mehrtagestermine kennt', () => {
        show();
        expect(screen.queryByLabelText('Enddatum')).toBeNull();

        cleanup();
        show({ multiDay: true });
        expect(screen.getByLabelText('Enddatum')).not.toBeNull();
    });

    /**
     * Frueher stand hier eine Pruefung auf `min` am nativen Feld. Seit die
     * Maske ein deutsches Textfeld zeichnet (v0.48.0), gibt es das Attribut
     * nicht mehr — und es half ohnehin nur im Auswahlkalender, getippt ging es
     * vorbei. Die Regel steht jetzt in der Maske und greift immer; geprueft
     * wird sie weiter unten unter „das Datumsfeld".
     */

    it('gibt bei einer gepflegten Liste eine Auswahl statt eines Textfelds', () => {
        const kategorien = [
            { id: 7, label: 'Kundentermin' },
            { id: 8, label: 'Intern' },
        ];
        const onChange = vi.fn();

        show({
            kinds: [kind({ usesCategories: true })],
            categories: kategorien,
            categoryMode: 'closed',
            onChange,
        });

        const auswahl = screen.getByLabelText('Kategorie');
        expect(auswahl.tagName).toBe('SELECT');

        fireEvent.change(auswahl, { target: { value: '8' } });
        expect(onChange.mock.calls[0]?.[0].categoryId).toBe('8');
    });

    it('gibt bei einer persoenlichen Liste ein Textfeld mit Vorschlaegen', () => {
        show({
            kinds: [kind({ usesCategories: true })],
            categories: [{ label: 'Sport' }],
            categoryMode: 'personal',
        });

        // Der Unterschied ist nicht Geschmack: Bei einer gepflegten Liste liesse
        // ein Textfeld etwas eintippen, was der Server hinterher ablehnt.
        expect(screen.getByLabelText('Kategorie').tagName).toBe('INPUT');
    });

    it('haengt eine Erklaerung unter das Kategoriefeld, wenn das Produkt eine hat', () => {
        const kategorien = [{ id: 7, label: 'Kundentermin' }];

        show({
            kinds: [kind({ usesCategories: true })],
            categories: kategorien,
            categoryMode: 'closed',
            labels: { categoryHint: 'Pflicht ohne Projekt.' },
        });

        // Unter dem Feld, nicht irgendwo: Ein Hinweis, der drei Felder weiter
        // steht, liest sich wie die Erklaerung des falschen Feldes.
        const kategorie = screen.getByLabelText('Kategorie').parentElement;
        expect(kategorie?.textContent).toContain('Pflicht ohne Projekt.');
    });

    it('laesst die Erklaerung weg, wenn es keine gibt', () => {
        show({
            kinds: [kind({ usesCategories: true })],
            categories: [{ id: 7, label: 'Kundentermin' }],
            categoryMode: 'closed',
        });

        expect(screen.getByLabelText('Kategorie').parentElement?.querySelector('p')).toBeNull();
    });

    it('laesst die Serie weg, wo die Maske sie nicht festlegen darf', () => {
        // Nicht dasselbe wie „die Art fuehrt keine Serien": Der Manager legt
        // eine Serie beim Anlegen fest und aendert sie beim Bearbeiten ueber
        // den Geltungsbereich. Ein Block dort verspraeche etwas, das der
        // Endpunkt nicht annimmt.
        show({ allowRecurrence: false });
        expect(screen.queryByText('Wiederholt sich')).toBeNull();

        cleanup();
        show();
        expect(screen.queryByText('Wiederholt sich')).not.toBeNull();
    });

    it('haengt eine Erklaerung unter die Artenwahl', () => {
        show({
            kinds: [kind(), kind({ key: 'business', label: 'Geschaeftlich' })],
            labels: { kindHint: 'Die Art vergibt das System.' },
        });

        const art = screen.getByLabelText('Terminart').parentElement;
        expect(art?.textContent).toContain('Die Art vergibt das System.');
    });

    it('laesst ein Produkt sein eigenes Zeit-Bedienelement einsetzen', () => {
        const onChange = vi.fn();

        show({
            renderTime: ({ id, value: wert, onChange: setzen }) => (
                <select id={id} value={wert} onChange={(e) => setzen(e.target.value)}>
                    <option value="">—</option>
                    <option value="09:15">09:15</option>
                </select>
            ),
            value: draft({ start: '', end: '' }),
            onChange,
        });

        // Kein natives Zeitfeld mehr, und der eigene Baustein schreibt in denselben Entwurf.
        const von = screen.getByLabelText('Von');
        expect(von.tagName).toBe('SELECT');

        fireEvent.change(von, { target: { value: '09:15' } });
        expect(onChange.mock.calls[0]?.[0].start).toBe('09:15');
    });

    it('legt beim Einschalten der Serie Wochentag und Ende schon hin', () => {
        const onChange = vi.fn();
        show({ onChange });

        fireEvent.click(screen.getByLabelText('Wiederholt sich'));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange.mock.calls[0]?.[0].recurrence).toMatchObject({
            frequency: 'weekly',
            byDay: ['MO'],
            until: '2027-09-14',
        });
    });
});

describe('das Zeitfeld', () => {
    /**
     * Bis zum 15.10.2026 gab die Maske hier ein `<input type="time">` aus, und
     * nur der Manager reichte ueber `renderTime` zwei Auswahllisten hinein.
     * Dieselbe Maske zeigte damit je nach System zwei verschiedene Eingaben —
     * und das native Feld wird bei englischer Browsersprache zum
     * Zwoelfstundenfeld mit AM/PM, mitten in einem Kalender, der sonst 24
     * Stunden zaehlt (#7846).
     */
    it('nimmt von sich aus die beiden Listen, nicht das native Feld', () => {
        show({ value: draft({ allDay: false, start: '09:00', end: '10:00' }) });

        expect(document.querySelectorAll('[data-slot="time-field"]')).toHaveLength(2);
        expect(document.querySelectorAll('input[type="time"]')).toHaveLength(0);
    });

    /** Der Ausweg bleibt: Ein Produkt, das wirklich etwas anderes braucht, kann. */
    it('laesst sich weiter ueberschreiben', () => {
        show({
            value: draft({ allDay: false, start: '09:00', end: '10:00' }),
            renderTime: ({ id, value }) => <input id={id} data-eigen="ja" readOnly value={value} />,
        });

        expect(document.querySelectorAll('[data-eigen="ja"]')).toHaveLength(2);
        expect(document.querySelectorAll('[data-slot="time-field"]')).toHaveLength(0);
    });

    /**
     * jsdom rechnet kein Layout, also kann dieser Test die Breite nicht messen
     * — nur festhalten, dass die Spalte die weite Klasse traegt. Gemessen hat
     * es der Browser: Huelle 112px, Inhalt 126px, und die Listen standen ueber
     * dem Rand (Verwaltung, 15.10.2026).
     */
    it('gibt der Uhrzeit eine Spalte, in die zwei Listen passen', () => {
        show({ value: draft({ allDay: false, start: '09:00', end: '10:00' }) });

        const spalten = [...document.querySelectorAll('[data-slot="time-field"]')].map(
            (f) => f.parentElement?.className,
        );

        expect(spalten).toHaveLength(2);
        for (const klasse of spalten) {
            expect(klasse).toContain('w-40');
            expect(klasse).not.toContain('w-28');
        }
    });

    /** Ganztaegig gibt es keine Uhrzeit — dann auch kein Feld dafuer. */
    it('faellt ganz weg, wenn der Termin ganztaegig ist', () => {
        show({ value: draft({ allDay: true }) });

        expect(document.querySelectorAll('[data-slot="time-field"]')).toHaveLength(0);
    });
});

describe('das Datumsfeld', () => {
    /**
     * Das native `<input type="date">` folgt der Sprache des BROWSERS. Auf
     * einem englisch eingestellten Chrome stand in dieser durchweg deutschen
     * Maske `mm/dd/yyyy` — beim Datum nicht nur ungewohnt, sondern mehrdeutig:
     * Der 09.08. und der 08.09. sehen gleich aus. Am 15.10.2026 in drei von
     * vier Anwendungen gleichzeitig gesehen (#7846).
     */
    it('nimmt das deutsche Feld, nicht das native', () => {
        show({ value: draft({ allDay: true }), multiDay: true });

        const eigene = document.querySelectorAll('[data-slot="date-field"]');
        expect(eigene.length).toBeGreaterThan(0);
        eigene.forEach((feld) => expect((feld as HTMLInputElement).type).toBe('text'));

        /*
         * Seit dem 22.10.2026 gibt es native Datumsfelder — aber NUR als
         * Auswaehler hinter dem Kalender-Knopf (#1070). Frueher stand hier
         * „keins davon". Das war einfacher zu pruefen und sagte das Falsche:
         * Verboten ist nicht das native Feld, verboten ist seine ANZEIGE.
         *
         * Diese Fassung ist strenger als die alte Zaehlung auf null, denn sie
         * laesst sich nicht dadurch erfuellen, dass jemand das sichtbare Feld
         * auf `type="date"` umstellt — dann waere es weder `text` noch
         * versteckt.
         */
        document.querySelectorAll('input[type="date"]').forEach((nativ) => {
            expect(nativ.getAttribute('aria-hidden')).toBe('true');
            expect((nativ as HTMLInputElement).tabIndex).toBe(-1);
        });
    });

    /**
     * Vorher sperrte `min` am nativen Feld fruehere Tage — aber nur im
     * Auswahlkalender; getippt ging es vorbei. Jetzt eine echte Pruefung.
     */
    it('sagt es, wenn das Ende vor dem Anfang liegt', () => {
        show({ value: draft({ date: '2026-09-14', endDate: '2026-09-01' }), multiDay: true });

        expect(screen.getByText('Das Ende liegt vor dem Anfang.')).toBeTruthy();
    });

    it('schweigt, solange die Reihenfolge stimmt', () => {
        show({ value: draft({ date: '2026-09-14', endDate: '2026-09-20' }), multiDay: true });

        expect(screen.queryByText('Das Ende liegt vor dem Anfang.')).toBeNull();
    });

    /** Ein leeres Enddatum heisst „derselbe Tag" und ist kein Fehler. */
    it('schweigt bei leerem Enddatum', () => {
        show({ value: draft({ date: '2026-09-14', endDate: '' }), multiDay: true });

        expect(screen.queryByText('Das Ende liegt vor dem Anfang.')).toBeNull();
    });
});

/**
 * Jährlich — für Geburtstage und Jahrestage (19.10.2026).
 *
 * Die Auswahl baut sich aus den Beschriftungen, ein fehlender Eintrag wäre
 * also eine Frequenz, die der Server kennt und die Maske nicht anbietet.
 */
it('bietet jaehrlich als Wiederholung an', () => {
    render(
        <EventForm
            kinds={[kind()]}
            value={draft({ recurrence: { ...emptyRecurrence(), frequency: 'weekly', byDay: ['MO'] } })}
            onChange={() => {}}
            onSubmit={() => {}}
        />,
    );

    const auswahl = screen.getByLabelText('Frequenz') as HTMLSelectElement;
    const werte = Array.from(auswahl.options).map((option) => option.value);

    expect(werte).toContain('yearly');
    expect(Array.from(auswahl.options).find((o) => o.value === 'yearly')?.text).toBe('jährlich');
});

/** Eine Jahresserie braucht keine Wochentage — das Feld gehört nicht dazu. */
it('verlangt bei jaehrlich keine Wochentage', () => {
    render(
        <EventForm
            kinds={[kind()]}
            value={draft({ recurrence: { ...emptyRecurrence(), frequency: 'yearly' } })}
            onChange={() => {}}
            onSubmit={() => {}}
        />,
    );

    expect(screen.queryByText('An diesen Tagen')).toBeNull();
});
