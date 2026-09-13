<?php

use Peppermint\Calendar\Ics\IcsWriter;

it('faltet eine zu lange Zeile eines fremden Dokuments', function () {
    $lang = 'DESCRIPTION:'.str_repeat('a', 200);
    $out = IcsWriter::foldDocument("BEGIN:VEVENT\r\n".$lang."\r\nEND:VEVENT");

    foreach (explode("\r\n", $out) as $zeile) {
        expect(strlen($zeile))->toBeLessThanOrEqual(75);
    }

    // Zusammengesetzt muss wieder dasselbe herauskommen.
    expect(str_replace("\r\n ", '', $out))->toContain($lang);
});

it('laesst kurze Zeilen in Ruhe', function () {
    $doc = "BEGIN:VEVENT\r\nSUMMARY:Kurz\r\nEND:VEVENT";

    expect(IcsWriter::foldDocument($doc))->toBe($doc);
});

it('zerlegt eine bereits gefaltete Zeile nicht beim zweiten Durchlauf', function () {
    // Sonst waere die Funktion nicht idempotent — und jemand ruft sie
    // irgendwann zweimal.
    $einmal = IcsWriter::foldDocument('DESCRIPTION:'.str_repeat('b', 200));

    expect(IcsWriter::foldDocument($einmal))->toBe($einmal);
});

it('zerreisst keinen Umlaut', function () {
    // Gefaltet wird in Oktett, nicht in Zeichen: Ein Umlaut belegt zwei
    // Oktett, und mitten hindurch getrennt entsteht Zeichensalat.
    $out = IcsWriter::foldDocument('SUMMARY:'.str_repeat('ä', 100));

    expect(mb_check_encoding($out, 'UTF-8'))->toBeTrue()
        ->and(str_replace("\r\n ", '', $out))->toBe('SUMMARY:'.str_repeat('ä', 100));
});
