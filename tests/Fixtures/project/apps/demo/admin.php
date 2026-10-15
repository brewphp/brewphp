<?php

declare(strict_types=1);

namespace Apps\Demo;

use Apps\Demo\Models\Tag;
use BrewPHP\Admin\ModelAdmin;
use BrewPHP\Admin\Register;
use BrewPHP\Db\QuerySet;
use BrewPHP\Http\Request;

#[Register(Tag::class)]
final class TagAdmin extends ModelAdmin
{
    public array $listDisplay = ['slug', 'note_count'];

    public array $searchFields = ['slug'];

    public array $actions = ['shout' => 'Shout the selected tags'];

    public function noteCount(Tag $tag): int
    {
        return $tag->related('notes')->count();
    }

    public function shout(Request $request, QuerySet $tags): void
    {
        foreach ($tags as $tag) {
            $tag->slug = strtoupper((string) $tag->slug);
            $tag->save();
        }
    }
}

#[Register(Note::class)]
final class NoteAdmin extends ModelAdmin
{
    public array $listDisplay = ['text', 'tag'];

    public array $listFilter = ['tag'];

    public array $searchFields = ['text', 'tag__slug'];

    public int $listPerPage = 2;

    /**
     * Staff users who are not superusers never see "secret" notes.
     */
    public function getQueryset(Request $request): QuerySet
    {
        $notes = parent::getQueryset($request)->orderBy('text');

        return $request->user()->isSuperuser() ? $notes : $notes->exclude(text__startswith: 'secret');
    }
}
