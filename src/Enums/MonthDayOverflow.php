<?php

namespace Peppermint\Calendar\Enums;

/**
 * What a monthly series does in months that are too short for its day —
 * a series on the 31st has no date in February.
 *
 * There is no universally correct answer, which is exactly why it is a
 * setting: skipping silently drops appointments people expect to see,
 * clamping moves them to a day nobody picked.
 */
enum MonthDayOverflow: string
{
    /** February has no 31st, so that month has no occurrence. */
    case Skip = 'skip';

    /** Fall back to the last day of the month (Feb 28th / 29th). */
    case Clamp = 'clamp';
}
