<?php

namespace Peppermint\Calendar\Ics;

/**
 * Builds iCalendar content lines (RFC 5545).
 *
 * The two things every hand-rolled serialiser gets wrong are escaping and
 * folding. Escaping is easy to remember; folding is not, because nothing
 * breaks until someone writes a long description — and then it breaks in the
 * user's calendar app, not in ours.
 */
class IcsWriter
{
    /** RFC 5545: a content line is at most 75 octets, excluding the line break. */
    protected const OCTET_LIMIT = 75;

    /** @var array<int, string> */
    protected array $lines = [];

    /**
     * @param  array<string, string|null>  $parameters
     */
    public function property(string $name, ?string $value, array $parameters = [], bool $raw = false): self
    {
        if ($value === null || $value === '') {
            return $this;
        }

        $line = $name;

        foreach ($parameters as $key => $parameterValue) {
            if ($parameterValue === null || $parameterValue === '') {
                continue;
            }

            $line .= ';'.$key.'='.$parameterValue;
        }

        $line .= ':'.($raw ? $value : $this->escape($value));

        $this->lines[] = $line;

        return $this;
    }

    public function begin(string $component): self
    {
        $this->lines[] = 'BEGIN:'.$component;

        return $this;
    }

    public function end(string $component): self
    {
        $this->lines[] = 'END:'.$component;

        return $this;
    }

    public function toString(): string
    {
        $out = '';

        foreach ($this->lines as $line) {
            $out .= $this->fold($line)."\r\n";
        }

        return $out;
    }

    /**
     * Escapes backslash, semicolon, comma and line breaks — in that order.
     * Backslash first, otherwise the escapes added below get escaped again.
     */
    public function escape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);

        return str_replace([';', ','], ['\;', '\\,'], $value);
    }

    /**
     * Folds a long line by inserting CRLF + a single space. Counted in octets,
     * not characters: a folded multi-byte character would produce a broken
     * file, and umlauts in a German title are enough to hit that.
     */
    protected function fold(string $line): string
    {
        if (strlen($line) <= self::OCTET_LIMIT) {
            return $line;
        }

        $folded = '';
        $current = '';

        foreach (mb_str_split($line) as $character) {
            // The continuation line starts with a space, which counts towards
            // the limit — so a chunk may only grow to 74 octets after the first.
            $limit = $folded === '' ? self::OCTET_LIMIT : self::OCTET_LIMIT - 1;

            if (strlen($current) + strlen($character) > $limit) {
                $folded .= ($folded === '' ? '' : "\r\n ").$current;
                $current = '';
            }

            $current .= $character;
        }

        return $folded.($folded === '' ? '' : "\r\n ").$current;
    }
}
