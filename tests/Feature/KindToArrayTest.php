<?php

use Peppermint\Calendar\Kinds\EventKind;
use Peppermint\Calendar\Sources\ExternalKind;
use Peppermint\Calendar\Tests\Fixtures\BusinessKind;
use Peppermint\Calendar\Tests\Fixtures\PrivateKind;

/**
 * A kind that states its requirements in every notation Laravel accepts —
 * the point being that a form learns the same thing from all of them.
 */
class RuleNotationKind extends EventKind
{
    public function key(): string
    {
        return 'notation';
    }

    public function label(): string
    {
        return 'Notation';
    }

    public function rules(): array
    {
        return [
            'location' => 'required|string|max:255',
            'meeting_url' => ['required', 'url'],
            'description' => 'nullable|string',
            'category' => ['string'],
        ];
    }
}

it('reads the required fields off the rules, in every notation', function () {
    expect((new RuleNotationKind)->requiredAttributes())
        ->toEqualCanonicalizing(['location', 'meeting_url']);
});

it('says nothing is required when a kind states no rules', function () {
    expect((new PrivateKind)->requiredAttributes())->toBe([]);
});

it('hands a user interface what it needs to build the dialogue', function () {
    expect((new PrivateKind)->toArray())->toBe([
        'key' => 'private',
        'label' => 'Private',
        'requires' => [],
        'forbids' => ['subject_type', 'subject_id'],
        'usesCategories' => false,
        'creatable' => true,
    ]);

    expect((new BusinessKind)->toArray()['usesCategories'])->toBeTrue();
});

it('marks a kind that only ever comes into being through another action', function () {
    $planned = new class extends EventKind
    {
        public function key(): string
        {
            return 'planned';
        }

        public function label(): string
        {
            return 'Geplant';
        }

        public function isUserCreatable(): bool
        {
            return false;
        }
    };

    expect($planned->toArray()['creatable'])->toBeFalse();
});

it('speaks the same shape as a kind from another system', function () {
    $own = array_keys((new PrivateKind)->toArray());
    $foreign = array_keys((new ExternalKind('x', 'X'))->toArray());

    // A dialogue reads both; every key the foreign kind has, the own one has too.
    expect(array_diff($foreign, $own))->toBe([]);
});
