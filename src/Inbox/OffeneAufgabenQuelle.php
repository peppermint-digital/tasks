<?php

namespace Peppermint\Tasks\Inbox;

use Illuminate\Database\Eloquent\Model;
use Peppermint\Inbox\Feed\Eintrag;
use Peppermint\Inbox\Quellen\InboxQuelle;
use Peppermint\Tasks\Models\Task;

/**
 * Die offenen Aufgaben dieser Person als Inbox-Art.
 *
 * ## Warum der Uebersetzer hier liegt
 *
 * Die Inbox weiss nicht, was eine Aufgabe ist — sie kennt nur Eintraege
 * mit einer Art. Dieses Paket weiss es sehr genau. Die Abhaengigkeit zeigt
 * in eine Richtung, und das Inbox-Paket bleibt frei von Fachwissen.
 *
 * ## Was diese Quelle NICHT kann
 *
 * „Zur Kenntnis genommen". Der Manager unterscheidet zwischen einer neuen
 * Aufgabe und einer, die man schon gesehen hat; das Paket kennt diesen
 * Begriff nicht, und ihn hier zu erfinden hiesse, ihn allen aufzuzwingen.
 *
 * Deshalb liefert diese Quelle den gemeinsamen Nenner: offen und mir
 * zugewiesen. Ein Produkt mit eigenem Begriff meldet statt ihrer eine
 * eigene Quelle an — die Schnittstelle ist genau dafuer da.
 *
 * ## Sortiert wird nicht hier
 *
 * Der Sammler legt alle Arten und Systeme zusammen und sortiert danach.
 * Eine Vorsortierung hier waere wirkungslos; was zaehlt, ist die GRENZE —
 * sie entscheidet, welche Aufgaben es ueberhaupt bis dorthin schaffen.
 */
class OffeneAufgabenQuelle implements InboxQuelle
{
    /** Unter welcher Art die Aufgaben stehen. */
    public const ART = 'task';

    public function art(): string
    {
        return self::ART;
    }

    public function eintraege(Model $person, int $grenze): array
    {
        return Task::query()
            ->open()
            ->where(Task::column('assigned_to'), $person->getKey())
            // Neueste zuerst: Die Grenze schneidet hinten ab, und was
            // abgeschnitten wird, soll das Aelteste sein.
            ->orderByDesc(Task::column('created_at'))
            ->limit($grenze)
            ->get()
            ->map($this->uebersetzen(...))
            ->all();
    }

    private function uebersetzen(Task $aufgabe): Eintrag
    {
        $faellig = $aufgabe->field('due_date');

        return new Eintrag(
            id: (string) $aufgabe->getKey(),
            art: self::ART,
            titel: (string) ($aufgabe->field('title') ?? ''),
            text: $this->kurz($aufgabe->field('description')),
            link: $this->verweis($aufgabe),
            symbol: 'inbox',
            // Eine feste Farbe, keine aus der Prioritaet: `TaskPriority`
            // hat gar kein `color()`. Der Aufruf lag kurz hier drin und
            // waere beim ersten Datensatz MIT Prioritaet fatal gewesen —
            // die Tests hatten keine, der Pfad war ungemessen.
            farbe: 'blue',
            zeitpunkt: $aufgabe->field('created_at')?->toIso8601String(),
            // Eine offene Aufgabe ist immer „noch zu sehen". Ein Produkt mit
            // eigenem Begriff von „zur Kenntnis genommen" meldet eine eigene
            // Quelle an, siehe Klassenkommentar.
            ungelesen: true,
            meta: array_filter([
                'status' => $aufgabe->statusDefinition()?->label(),
                'priority' => $aufgabe->priorityDefinition()?->label(),
                'due' => $faellig instanceof \DateTimeInterface ? $faellig->format('d.m.Y') : null,
            ], static fn ($wert): bool => $wert !== null && $wert !== ''),
        );
    }

    /**
     * Wohin der Klick fuehrt.
     *
     * Absolut, denn diese Zeile steht gleich in vier Oberflaechen — eine
     * relative Adresse zeigt in drei davon ins Leere.
     */
    private function verweis(Task $aufgabe): string
    {
        $muster = (string) config('tasks.inbox.link', '/tasks/{id}');

        return rtrim((string) config('app.url'), '/').str_replace('{id}', (string) $aufgabe->getKey(), $muster);
    }

    private function kurz(mixed $text): ?string
    {
        $klartext = trim(strip_tags((string) ($text ?? '')));

        if ($klartext === '') {
            return null;
        }

        return mb_strlen($klartext) > 160 ? mb_substr($klartext, 0, 157).'…' : $klartext;
    }
}
