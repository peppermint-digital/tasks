<?php

namespace Peppermint\Tasks\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Gegenstand, der KEINE Aufgabe ist — fuer den Nachweis, dass
 * `TaskBlockKind` den Feldnamen aus dem Modell ableitet und nicht festschreibt.
 *
 * Die Verwaltung plant Tickets statt Aufgaben; ohne diesen Nachweis haette
 * `task_id` dort fest im Paket gestanden.
 */
class Ticket extends Model
{
    protected $guarded = [];
}
