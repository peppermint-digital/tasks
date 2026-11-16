<?php

namespace Peppermint\Tasks\Exceptions;

use InvalidArgumentException;

/**
 * A span of time that must not be sent to another system.
 *
 * Typed and not a bare `InvalidArgumentException`, because the form a person
 * fills in is the thing that produces it: a mask wants to catch this one and
 * put the sentence next to the field, and catching
 * `InvalidArgumentException` there would also catch everything else that ever
 * goes wrong behind it.
 *
 * Thrown at construction of {@see \Peppermint\Tasks\Sources\TimeSpan} rather
 * than when sending. A value object that may hold an unusable value has to be
 * checked by everyone who touches it — and the one who forgets is the one who
 * sends it.
 */
final class InvalidTimeSpan extends InvalidArgumentException
{
    /**
     * A point in time without a zone.
     *
     * The expensive one, measured twice in this house and written down at
     * `peppermint-verwaltung/app/Mcp/Tools/CreateCalendarEventTool.php:68-93`:
     * first UTC instead of the display zone — two hours too early in every
     * event created from outside — and then the wrong lesson drawn from it.
     *
     * A naked local time is the kind of statement two systems silently
     * disagree about by two hours. Nothing throws, nothing logs, and the block
     * sits in the wrong hour of somebody's day.
     */
    public static function withoutZone(string $field, string $value): self
    {
        return new self(
            "Das Feld \"{$field}\" braucht ISO-8601 MIT Zone, bekommen hat es \"{$value}\". "
            .'Eine nackte Ortszeit ist die Sorte Angabe, bei der zwei Systeme sich '
            .'stillschweigend um zwei Stunden uneinig sind.'
        );
    }

    /**
     * The right shape, and no such moment — "2026-02-30T09:00:00+01:00".
     *
     * Its own message and not the one above, because what is missing is not
     * the zone but the day. A message that names the wrong defect sends
     * somebody looking at the wrong field, and the field it names IS right
     * here — which makes the wrong message convincing.
     */
    public static function notAMoment(string $field, string $value): self
    {
        return new self(
            "Das Feld \"{$field}\" hat die richtige Form, aber \"{$value}\" ist kein Zeitpunkt."
        );
    }

    /**
     * An end that is not after its beginning.
     *
     * Planned work is a span of HOURS — that is the whole difference between a
     * block and a deadline (`src/Calendar/TaskDeadlineSource.php:29-36`
     * argues the other side of it). A span that runs backwards is not a span,
     * and a zero-length one occupies no time: it would draw in the grid as a
     * line nobody can grab, and it would look like a successful plan.
     *
     * Refused here rather than over there, and not out of thrift: the other
     * system would refuse it too, and the message a person reads then has
     * travelled through two systems and a comm layer.
     */
    public static function endsBeforeItBegins(string $startsAt, string $endsAt): self
    {
        return new self(
            "Der Zeitraum endet nicht nach seinem Beginn: \"{$startsAt}\" bis \"{$endsAt}\". "
            .'Geplante Arbeit ist eine Spanne von Stunden.'
        );
    }
}
