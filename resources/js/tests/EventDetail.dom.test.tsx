/**
 * @vitest-environment jsdom
 */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { EventDetail, type EventDetailData } from '../src/EventDetail';

afterEach(cleanup);

const TERMIN: EventDetailData = {
    title: 'Jahresgespräch',
    date: '2026-09-08',
    start: '09:00',
    end: '10:30',
    location: 'Büro Hamburg',
    description: 'Erste Zeile\nZweite Zeile',
    kindLabel: 'Geschäftlich',
    colour: '#2563eb',
};

describe('der Zeitraum', () => {
    it('nennt Tag und Uhrzeit', () => {
        render(<EventDetail event={TERMIN} />);

        expect(screen.getByText(/Di\., 08\. September 2026, 09:00 – 10:30/)).toBeTruthy();
    });

    it('sagt bei ganztaegig keine Uhrzeit', () => {
        render(<EventDetail event={{ ...TERMIN, allDay: true, start: null, end: null }} />);

        expect(screen.getByText(/ganztägig/)).toBeTruthy();
    });

    /** Ohne `allDay`, aber ohne Uhrzeiten: auch das ist ganztägig. */
    it('erkennt ganztaegig auch an fehlenden Uhrzeiten', () => {
        render(<EventDetail event={{ ...TERMIN, start: null, end: null }} />);

        expect(screen.getByText(/ganztägig/)).toBeTruthy();
    });

    /**
     * Ein dreitägiger Termin sah im Manager aus wie einer von einem Tag —
     * „ganztägig" ohne zweiten Tag ist eine Lüge über die Länge.
     */
    it('nennt bei mehrtaegig den zweiten Tag', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, endDate: '2026-09-10', allDay: true, start: null, end: null }}
            />,
        );

        expect(screen.getByText(/08\. September 2026 – .*10\. September 2026/)).toBeTruthy();
    });

    it('nennt bei mehrtaegig mit Uhrzeit beide Seiten', () => {
        render(<EventDetail event={{ ...TERMIN, endDate: '2026-09-10' }} />);

        expect(screen.getByText(/09:00 – .*10\. September 2026, 10:30/)).toBeTruthy();
    });

    it('kommt ohne Endzeit aus', () => {
        render(<EventDetail event={{ ...TERMIN, end: null }} />);

        expect(screen.getByText(/2026, 09:00$/)).toBeTruthy();
    });

    /** Das Produkt darf die Beschriftung übernehmen. */
    it('laesst das Produkt den Tag beschriften', () => {
        render(<EventDetail event={TERMIN} formatDate={() => 'heute'} />);

        expect(screen.getByText(/heute, 09:00 – 10:30/)).toBeTruthy();
    });
});

describe('die Felder', () => {
    it('zeigt Titel, Art, Ort und Notiz', () => {
        render(<EventDetail event={TERMIN} />);

        expect(screen.getByText('Jahresgespräch')).toBeTruthy();
        expect(screen.getByText('Geschäftlich')).toBeTruthy();
        expect(screen.getByText('Büro Hamburg')).toBeTruthy();
        expect(screen.getByText(/Erste Zeile/)).toBeTruthy();
    });

    /**
     * Ein Etikett ohne Wert liest sich wie ein fehlender Wert, und beim dritten
     * davon hört man auf, die Liste zu lesen.
     */
    it('laesst leere Felder ganz weg', () => {
        render(<EventDetail event={{ ...TERMIN, location: null, description: '' }} />);

        expect(screen.queryByText('Wo')).toBeNull();
        expect(screen.queryByText('Notiz')).toBeNull();
    });

    it('zeigt Teilnehmer mit ihrer Antwort', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, attendees: [{ name: 'Anna', status: 'zugesagt' }, { name: 'Bert' }] }}
            />,
        );

        expect(screen.getByText('Anna')).toBeTruthy();
        expect(screen.getByText('zugesagt')).toBeTruthy();
        expect(screen.getByText('Bert')).toBeTruthy();
    });

    it('uebersetzt die bekannten Antwort-Schluessel', () => {
        // Bis zum 29.10.2026 stand hier der ROHE Schluessel — ein englisches
        // Wort aus der Datenbank, mitten in einer deutschen Maske.
        render(<EventDetail event={{ ...TERMIN, attendees: [{ name: 'Anna', status: 'accepted' }] }} />);

        expect(screen.getByText('Zugesagt')).toBeTruthy();
        expect(screen.queryByText('accepted')).toBeNull();
    });

    it('laesst ein unbekanntes Antwortwort durch, statt es zu verschlucken', () => {
        // Ein Produkt mit eigenem Vokabular soll sein Wort sehen.
        render(<EventDetail event={{ ...TERMIN, attendees: [{ name: 'Anna', status: 'wartet noch' }] }} />);

        expect(screen.getByText('wartet noch')).toBeTruthy();
    });

    it('zeigt Kategorien', () => {
        render(<EventDetail event={{ ...TERMIN, categories: ['Kundentermin', 'Abrechenbar'] }} />);

        expect(screen.getByText('Kundentermin')).toBeTruthy();
        expect(screen.getByText('Abrechenbar')).toBeTruthy();
    });

    it('sagt es, wenn der Termin zu einer Serie gehoert', () => {
        render(<EventDetail event={{ ...TERMIN, recurring: true }} />);

        expect(screen.getByText('Teil einer Serie')).toBeTruthy();
    });

    it('zeigt die Farbmarke nur, wo eine Farbe bekannt ist', () => {
        render(<EventDetail event={{ ...TERMIN, colour: null }} />);

        expect(document.querySelector('[data-slot="event-detail-dot"]')).toBeNull();
    });
});

/**
 * Die Ansicht kennt keine Rechte. Ob jemand bearbeiten darf, weiss nur das
 * Produkt (privat, Teilnehmer, Projektleitung, Verwalter) — deshalb kommen die
 * Bedienelemente herein und nicht aus einem Schalter hier.
 */
describe('die produkteigenen Teile', () => {
    it('nimmt eigene Zeilen auf', () => {
        render(
            <EventDetail
                event={TERMIN}
                extra={
                    <div>
                        <dt>Projekt</dt>
                        <dd>Website-Umbau</dd>
                    </div>
                }
            />,
        );

        expect(screen.getByText('Website-Umbau')).toBeTruthy();
    });

    it('nimmt eigene Bedienelemente auf', () => {
        render(<EventDetail event={TERMIN} actions={<button type="button">Bearbeiten</button>} />);

        expect(screen.getByRole('button', { name: 'Bearbeiten' })).toBeTruthy();
    });

    /** Ohne Bedienelemente auch kein Trennstrich — sonst steht er allein da. */
    it('zeichnet ohne Bedienelemente keine Leiste', () => {
        const { container } = render(<EventDetail event={TERMIN} />);

        expect(container.querySelector('.border-t')).toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| Kategorien wählen (#7846)
|--------------------------------------------------------------------------
|
| Gemessen im Manager am 19.10.2026: 483 von 1211 Terminen sind Zeitblöcke an
| einer Aufgabe, und keiner konnte eine Kategorie tragen — damit fiel dieser
| Teil in der Auswertung komplett unter „ohne Kategorie". Ein Block entsteht
| durch Ziehen, es gibt also keinen Dialog, in dem man sie mitwählen könnte.
| Deshalb hier.
*/

const kategorien = [
    { slug: 'kundentermin', label: 'Kundentermin' },
    { slug: 'vertrieb', label: 'Vertrieb' },
];

describe('EventDetail — Kategorien wählen', () => {
    it('bleibt eine Anzeige, wenn nichts zum Ändern übergeben wird', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, categories: ['Kundentermin'], categorySlugs: ['kundentermin'] }}
            />,
        );

        expect(screen.getByText('Kundentermin')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Kundentermin' })).toBeNull();
    });

    /** Der ganze Punkt: wählbar auch dann, wenn der Termin noch keine trägt. */
    it('bietet die Wahl auch bei einem Termin ohne Kategorie', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, categories: [], categorySlugs: [] }}
                availableCategories={kategorien}
                onCategoriesChange={() => {}}
            />,
        );

        expect(screen.getByRole('button', { name: 'Kundentermin' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Vertrieb' })).toBeTruthy();
    });

    it('zeigt, was gewählt ist', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, categorySlugs: ['vertrieb'] }}
                availableCategories={kategorien}
                onCategoriesChange={() => {}}
            />,
        );

        expect(screen.getByRole('button', { name: 'Vertrieb' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('button', { name: 'Kundentermin' }).getAttribute('aria-pressed')).toBe('false');
    });

    /** Die VOLLSTÄNDIGE neue Liste, nicht die Änderung — sonst muss der Empfänger rechnen. */
    it('meldet die neue vollstaendige Liste beim Hinzufuegen', () => {
        const gemeldet: string[][] = [];

        render(
            <EventDetail
                event={{ ...TERMIN, categorySlugs: ['kundentermin'] }}
                availableCategories={kategorien}
                onCategoriesChange={(slugs) => gemeldet.push(slugs)}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Vertrieb' }));

        expect(gemeldet).toEqual([['kundentermin', 'vertrieb']]);
    });

    it('meldet die neue vollstaendige Liste beim Abwaehlen', () => {
        const gemeldet: string[][] = [];

        render(
            <EventDetail
                event={{ ...TERMIN, categorySlugs: ['kundentermin', 'vertrieb'] }}
                availableCategories={kategorien}
                onCategoriesChange={(slugs) => gemeldet.push(slugs)}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Kundentermin' }));

        expect(gemeldet).toEqual([['vertrieb']]);
    });

    /** Ein leerer Auswahlbereich ist schlimmer als keiner. */
    it('zeigt nichts, wenn die Terminart keine Kategorien anbietet', () => {
        render(
            <EventDetail
                event={{ ...TERMIN, categories: [], categorySlugs: [] }}
                availableCategories={[]}
                onCategoriesChange={() => {}}
            />,
        );

        expect(screen.queryByText('Kategorien')).toBeNull();
    });
});

describe('Dauer und Privat (#8307)', () => {
    const termin = (over: Partial<EventDetailData> = {}): EventDetailData => ({
        title: 'Besprechung',
        date: '2026-10-19',
        start: '09:00',
        end: '10:30',
        ...over,
    });

    it('nennt die Dauer, damit man sie nicht im Kopf rechnet', () => {
        render(<EventDetail event={termin()} />);

        expect(screen.getByTestId('event-detail-duration').textContent).toBe('Dauer: 1 Std. 30 Min.');
    });

    it('sagt bei einer vollen Stunde „1 Std." und nicht „1 Std. 0 Min."', () => {
        render(<EventDetail event={termin({ end: '10:00' })} />);

        expect(screen.getByTestId('event-detail-duration').textContent).toBe('Dauer: 1 Std.');
    });

    it('sagt unter einer Stunde nur die Minuten', () => {
        render(<EventDetail event={termin({ end: '09:45' })} />);

        expect(screen.getByTestId('event-detail-duration').textContent).toBe('Dauer: 45 Min.');
    });

    it('laesst sie bei einem ganztaegigen Termin weg', () => {
        // „1440 Min." ist keine Auskunft.
        render(<EventDetail event={termin({ allDay: true, start: null, end: null })} />);

        expect(screen.queryByTestId('event-detail-duration')).toBeNull();
    });

    it('laesst sie ueber mehrere Tage weg — dort sagt der Zeitraum alles', () => {
        render(<EventDetail event={termin({ endDate: '2026-10-21' })} />);

        expect(screen.queryByTestId('event-detail-duration')).toBeNull();
    });

    it('laesst sie weg statt etwas Falsches zu sagen, wenn das Ende vor dem Anfang liegt', () => {
        render(<EventDetail event={termin({ start: '22:00', end: '02:00' })} />);

        expect(screen.queryByTestId('event-detail-duration')).toBeNull();
    });

    it('kennzeichnet einen privaten Termin', () => {
        render(<EventDetail event={termin({ kindLabel: 'Geschäftlich', private: true })} />);

        // Sichtbarkeit und Terminart stehen nebeneinander: Ein geschaeftlicher
        // Termin KANN privat gestellt sein.
        expect(screen.getByTestId('event-detail-private').textContent).toContain('Privat');
        expect(screen.getByText(/Geschäftlich/)).toBeTruthy();
    });

    it('zeigt „Privat" auch ohne Terminart', () => {
        render(<EventDetail event={termin({ private: true })} />);

        expect(screen.getByTestId('event-detail-private').textContent).toContain('Privat');
    });

    it('schweigt bei einem nicht privaten Termin', () => {
        render(<EventDetail event={termin({ kindLabel: 'Geschäftlich' })} />);

        expect(screen.queryByTestId('event-detail-private')).toBeNull();
    });
});

describe('Mailadresse der Teilnehmer (#8307)', () => {
    it('zeigt sie neben dem Namen', () => {
        render(
            <EventDetail
                event={{
                    title: 'Abstimmung',
                    date: '2026-10-19',
                    attendees: [{ name: 'Anna Beispiel', email: 'anna@example.test' }],
                }}
            />,
        );

        expect(screen.getByText('anna@example.test')).toBeTruthy();
        expect(screen.getByText('Anna Beispiel')).toBeTruthy();
    });

    it('laesst sie weg, wenn sie schon der Name ist', () => {
        // Bei einem externen Gast ohne Namen ruecken Name und Adresse gleich
        // ein. Sie zweimal zu zeigen saehe aus wie ein Fehler.
        render(
            <EventDetail
                event={{
                    title: 'Abstimmung',
                    date: '2026-10-19',
                    attendees: [{ name: 'gast@example.test', email: 'gast@example.test' }],
                }}
            />,
        );

        expect(screen.getAllByText('gast@example.test')).toHaveLength(1);
    });

    it('kommt ohne Adresse aus', () => {
        render(
            <EventDetail
                event={{ title: 'Abstimmung', date: '2026-10-19', attendees: [{ name: 'Anna Beispiel' }] }}
            />,
        );

        expect(screen.getByText('Anna Beispiel')).toBeTruthy();
    });
});

/*
| Die eigene Antwort auf eine Einladung (Bug #1110 im Peppermint Manager).
|
| Vorgeschichte: Den Zusage-Knopf gab es dort nur im Bearbeiten-Dialog, und
| dort rendert er wegen einer fehlenden Prop fuer NIEMANDEN. Die
| Benachrichtigung verlinkt aber auf die Detailseite — wer eingeladen wurde,
| landete also zuverlaessig auf der einen Seite, auf der man nicht zusagen
| kann, und bekam nur „Bearbeiten" angeboten.
*/
describe('die eigene Teilnahme', () => {
    it('zeigt nichts, solange das Produkt keine Antwort reicht', () => {
        render(<EventDetail event={TERMIN} />);

        expect(screen.queryByText('Deine Teilnahme')).toBeNull();
    });

    it('zeigt nichts ohne Weg, daran etwas zu aendern', () => {
        // Eine Anzeige „noch offen" ohne Knopf ist genau der Zustand, aus dem
        // der Fehler entstand.
        render(<EventDetail event={TERMIN} myAttendance={{ status: 'pending' }} />);

        expect(screen.queryByText('Deine Teilnahme')).toBeNull();
    });

    it('bietet beides an, solange nichts geantwortet ist', () => {
        render(<EventDetail event={TERMIN} myAttendance={{ status: 'pending' }} onRespond={() => {}} />);

        expect(screen.getByText('Noch offen')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Zusagen' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Absagen' })).toBeTruthy();
    });

    it('blendet den Knopf des schon gesetzten Zustands aus', () => {
        render(<EventDetail event={TERMIN} myAttendance={{ status: 'accepted' }} onRespond={() => {}} />);

        expect(screen.queryByRole('button', { name: 'Zusagen' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Absagen' })).toBeTruthy();
    });

    it('meldet die NEUE Antwort, nicht einen Umschalt-Befehl', () => {
        const gerufen: string[] = [];

        render(
            <EventDetail
                event={TERMIN}
                myAttendance={{ status: 'pending' }}
                onRespond={(status) => gerufen.push(status)}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Zusagen' }));
        fireEvent.click(screen.getByRole('button', { name: 'Absagen' }));

        expect(gerufen).toEqual(['accepted', 'declined']);
    });

    it('fuehrt KEINEN eigenen Zustand ueber die Antwort', () => {
        // Zwei Staende derselben Sache laufen auseinander — hier waere das ein
        // Abzeichen, das etwas anderes sagt als die Datenbank. Die Seite kommt
        // mit frischen Props zurueck, und bis dahin steht das Alte da.
        render(<EventDetail event={TERMIN} myAttendance={{ status: 'pending' }} onRespond={() => {}} />);

        fireEvent.click(screen.getByRole('button', { name: 'Zusagen' }));

        expect(screen.getByText('Noch offen')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Zusagen' })).toBeTruthy();
    });
});
