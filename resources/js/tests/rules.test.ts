import { describe, expect, it } from 'vitest';
import { groupByDay } from '../src/AgendaList';
import { eventFormRules, weekdayOf } from '../src/rules';
import { emptyDraft, emptyRecurrence, type EventDraft, type EventKind } from '../src/types';

const draft = (over: Partial<EventDraft> = {}): EventDraft => ({ ...emptyDraft('private'), ...over });

const kind = (over: Partial<EventKind> = {}): EventKind => ({ key: 'private', label: 'Privat', ...over });

describe('weekdayOf', () => {
    // Der Grund fuer diesen Test: `getDay()` zaehlt ab Sonntag, RFC 5545 ab
    // Montag. Ein Fehler in der Umrechnung faellt bei einer Wochenserie nicht
    // auf — sie laeuft, nur am falschen Tag.
    it('rechnet die Sonntags-Zaehlung auf die Montags-Zaehlung um', () => {
        expect(weekdayOf('2026-09-14')).toBe('MO');
        expect(weekdayOf('2026-09-19')).toBe('SA');
        expect(weekdayOf('2026-09-20')).toBe('SU');
    });

    it('haelt einen leeren oder unsinnigen Tag aus', () => {
        expect(weekdayOf('')).toBeNull();
        expect(weekdayOf('kein Datum')).toBeNull();
    });
});

describe('eventFormRules', () => {
    it('blendet aus, was die Art verbietet', () => {
        const rules = eventFormRules([kind({ forbids: ['meeting_url', 'recurrence_rules'] })], draft());

        expect(rules.shows('meetingUrl')).toBe(false);
        expect(rules.shows('recurrence')).toBe(false);
        expect(rules.shows('location')).toBe(true);
    });

    it('meldet eine verlangte Serie erst als fehlend und dann nicht mehr', () => {
        const requires = [kind({ requires: ['recurrence_rules'] })];

        expect(eventFormRules(requires, draft()).missing).toContain('recurrence');
        expect(eventFormRules(requires, draft({ recurrence: emptyRecurrence() })).missing).not.toContain('recurrence');
    });

    it('verlangt Uhrzeiten nur, solange der Termin nicht ganztaegig ist', () => {
        const requires = [kind({ requires: ['starts_at', 'ends_at'] })];

        expect(eventFormRules(requires, draft()).missing).toEqual(['date', 'start', 'end']);
        expect(eventFormRules(requires, draft({ allDay: true })).missing).toEqual(['date']);
    });

    it('verlangt nie ein zweites Datum, auch wenn die Art ends_at braucht', () => {
        // `endDate` und `end` teilen sich `ends_at`. Ohne den Riegel verlangte
        // jede Art mit Endzeit auch ein Enddatum — und ein leeres heisst
        // „derselbe Tag", fehlt also nicht.
        const requires = [kind({ requires: ['ends_at'] })];

        expect(eventFormRules(requires, draft({ endDate: '' })).missing).not.toContain('endDate');
    });

    it('haelt einen Entwurf erst fuer vollstaendig, wenn nichts mehr fehlt', () => {
        const requires = [kind({ requires: ['location'] })];

        expect(eventFormRules(requires, draft({ title: 'Zahnarzt', date: '2026-09-14' })).complete).toBe(false);
        expect(
            eventFormRules(requires, draft({ title: 'Zahnarzt', date: '2026-09-14', location: 'Praxis' })).complete,
        ).toBe(true);
    });

    it('faellt auf die erste Art zurueck, wenn der Entwurf keine nennt', () => {
        const rules = eventFormRules([kind({ key: 'habit', usesCategories: true })], draft({ kind: '' }));

        expect(rules.kind?.key).toBe('habit');
        expect(rules.usesCategories).toBe(true);
    });
});

describe('groupByDay', () => {
    const row = (key: string, date: string, start: string | null) => ({ key, date, start, end: null, title: key });

    it('sortiert nach Tag und Uhrzeit, egal wie die Abfrage sie liefert', () => {
        const grouped = groupByDay([
            row('spaet', '2026-09-14', '17:00'),
            row('morgen', '2026-09-15', '08:00'),
            row('frueh', '2026-09-14', '09:00'),
        ]);

        expect(Object.keys(grouped)).toEqual(['2026-09-14', '2026-09-15']);
        expect(grouped['2026-09-14']?.map((entry) => entry.key)).toEqual(['frueh', 'spaet']);
    });

    it('stellt ganztaegige Termine vor die mit Uhrzeit', () => {
        const grouped = groupByDay([row('acht', '2026-09-14', '08:00'), row('ganztags', '2026-09-14', null)]);

        expect(grouped['2026-09-14']?.map((entry) => entry.key)).toEqual(['ganztags', 'acht']);
    });

    it('laesst die uebergebene Liste unangetastet', () => {
        const rows = [row('b', '2026-09-15', '08:00'), row('a', '2026-09-14', '08:00')];
        groupByDay(rows);

        expect(rows.map((entry) => entry.key)).toEqual(['b', 'a']);
    });
});
