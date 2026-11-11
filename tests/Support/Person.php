<?php

namespace Peppermint\Tasks\Tests\Support;

use Illuminate\Auth\Authenticatable as KannSichAnmelden;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ein Stellvertreter fuer die handelnde Person.
 *
 * Das Paket bringt keine Personentabelle mit — es kennt nur die Kennung
 * in `assigned_to`. Welches Modell dahintersteht, entscheidet das Produkt.
 *
 * Seit dem 11.11.2026 auch ANMELDBAR: Die Bruecken-Quellen fragen erst die
 * Wache und gehen nur mit angemeldeter Person ueber die Leitung. Ohne das hier
 * liesse sich genau dieser Riegel nicht pruefen — und ein Test, der ihn nicht
 * pruefen kann, laesst ihn beim naechsten Umbau fallen.
 */
class Person extends Model implements Authenticatable
{
    use KannSichAnmelden;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
