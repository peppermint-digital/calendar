<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;

class CategoryNotAllowed extends RuntimeException
{
    public static function disabled(): self
    {
        return new self(
            'Categories are switched off. Set calendar.categories.enabled to true, '
            .'run the migration, and give the kind usesCategories() = true.'
        );
    }

    public static function empty(): self
    {
        return new self('A category needs a label that survives slugging.');
    }

    public static function listIsClosed(string $label): self
    {
        return new self(
            "\"{$label}\" is not in the managed category list, and calendar.categories.mode "
            .'is "closed". Add it to the list, or switch to "personal" so people may extend it for themselves.'
        );
    }

    public static function notPermitted(string $label): self
    {
        return new self("Not allowed to create the category \"{$label}\".");
    }
}
