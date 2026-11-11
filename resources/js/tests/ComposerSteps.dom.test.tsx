/**
 * @vitest-environment jsdom
 */
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { ComposerSteps, type ComposerStep } from '../src/ComposerSteps';

afterEach(cleanup);

const drei: ComposerStep[] = [
    { key: 'was', label: 'Was', chosen: 'Termin' },
    { key: 'art', label: 'Terminart', chosen: 'Geschäftstermin (Manager)' },
    { key: 'felder', label: 'Felder' },
];

describe('ComposerSteps', () => {
    it('zeichnet nichts, solange es nur einen Schritt gibt', () => {
        const { container } = render(
            <ComposerSteps steps={[{ key: 'felder', label: 'Felder' }]} current="felder" />,
        );

        expect(container.querySelector('[data-slot="composer-steps"]')).toBeNull();
    });

    it('macht erledigte Schritte anklickbar und spätere nicht', () => {
        render(<ComposerSteps steps={drei} current="art" onGoTo={vi.fn()} />);

        // „Was" liegt vor dem offenen Schritt — der Rückweg.
        expect(screen.getByRole('button', { name: 'Zurück zu: Was' })).toBeTruthy();

        // „Terminart" ist der offene, „Felder" kommt erst: beide kein Knopf.
        expect(screen.queryByRole('button', { name: 'Zurück zu: Terminart' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Zurück zu: Felder' })).toBeNull();
    });

    it('springt auf den angeklickten Schritt, nicht auf den Vorgänger', () => {
        const springen = vi.fn();
        render(<ComposerSteps steps={drei} current="felder" onGoTo={springen} />);

        // Von Schritt drei direkt auf Schritt eins — das ist der Grund für die
        // Kette statt eines Zurück-Knopfs.
        screen.getByRole('button', { name: 'Zurück zu: Was' }).click();

        expect(springen).toHaveBeenCalledWith('was');
    });

    it('zeigt die getroffene Wahl neben der Beschriftung', () => {
        const { container } = render(<ComposerSteps steps={drei} current="felder" />);

        expect(container.textContent).toContain('Geschäftstermin (Manager)');
    });

    it('sagt Hilfsmitteln, wo man steht', () => {
        const { container } = render(<ComposerSteps steps={drei} current="art" onGoTo={vi.fn()} />);

        expect(container.querySelector('[data-slot="composer-steps"]')?.getAttribute('aria-label'))
            .toBe('Schritt 2 von 3');
        expect(container.querySelector('[data-step="art"]')?.getAttribute('aria-current')).toBe('step');
        expect(container.querySelector('[data-step="felder"]')?.getAttribute('aria-current')).toBeNull();
    });

    it('bleibt ohne onGoTo reine Anzeige', () => {
        render(<ComposerSteps steps={drei} current="felder" />);

        expect(screen.queryAllByRole('button')).toHaveLength(0);
    });
});
