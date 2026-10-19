<?php

namespace Peppermint\Tasks\Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Ein Stellvertreter fuer die handelnde Person.
 *
 * Das Paket bringt keine Personentabelle mit — es kennt nur die Kennung
 * in `assigned_to`. Welches Modell dahintersteht, entscheidet das Produkt.
 */
class Person extends Model
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
