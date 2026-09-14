<?php

namespace Peppermint\Tasks\Status;

/**
 * The four states iCalendar VTODO knows (RFC 5545 §3.8.1.11).
 *
 * This is the package's yardstick, and it is deliberately an EXTERNAL one.
 * Two applications may call the same state "open" and "todo" — that is their
 * business. But whether a task counts as finished must not be a matter of
 * taste, because export, cross-system comparison and the urgency engine all
 * hang on it.
 *
 * Four values are enough because they are enough for every calendar client in
 * the world. An application with nine states maps them onto these four; the
 * nine keep their own names and their own meaning everywhere else.
 */
enum VtodoStatus: string
{
    case NeedsAction = 'NEEDS-ACTION';
    case InProcess = 'IN-PROCESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /** Finished, one way or the other — no more urgency, no more reminders. */
    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }
}
