<?php

uses(Peppermint\Tasks\Tests\TestCase::class)->in('Feature');

/*
 * Attrappen der Anbieterfrage (Bug #1513).
 *
 * Bis zum 19.11.2026 fragte das Paket `AiBrain::providers()`, und die Tests
 * stellten genau das nach. Seitdem fragt es `AiBrain::anbieterMitGrund()` —
 * dieselbe Frage, dieselben Zeilen, nur mit `ok`/`error`/`message` darum
 * (Form von `gateway()`). Die bestehenden Attrappen beschreiben weiter
 * dieselben ERFOLGSFAELLE; sie wurden deshalb nur umgehuellt, nicht
 * umgeschrieben. Die Ausfaelle, die vorher nicht darstellbar waren, stehen in
 * `NabeBeimAnmeldenTest`.
 */
function anbieterAntwort(array $zeilen): array
{
    return ['ok' => true, 'data' => $zeilen, 'text' => null, 'error' => null, 'message' => null, 'product' => null];
}

/** Dasselbe fuer eine Attrappe, die je Aufruf rechnet. */
function anbieterAntwortend(Closure $zeilen): Closure
{
    return fn (...$argumente) => anbieterAntwort($zeilen(...$argumente));
}
