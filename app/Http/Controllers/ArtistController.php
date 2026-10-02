<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArtistRequest;
use App\Models\Artist;
use App\Traits\Helpers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ArtistController extends Controller
{
    use Helpers;

    public function index(Request $request)
    {

        if ($request->wantsJson()) {
            return $this->autocompList(Artist::class);
        }

        $baseQuery = QueryBuilder::for(Artist::class)
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Artist/Index', [
            'artists' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function indexTrash(Request $request)
    {

        $baseQuery = QueryBuilder::for(Artist::class)
            ->onlyTrashed()
            ->allowedSorts(['name'])
            ->allowedFilters([
                AllowedFilter::callback('search', function (Builder $query, $value) {
                    \App\Support\SearchFilter::apply($query, $value, ['name']);
                }),
            ]);

        return Inertia::render('Artist/Trash/Index', [
            'artists' => $baseQuery->paginate($this->perPage($request)),
            'applied_filters' => $request->get('filter', []),
        ]);
    }

    public function create()
    {
        return Inertia::render('Artist/Create');
    }

    public function store(ArtistRequest $request)
    {
        $artist = Artist::create($request->validated());

        return redirect()
            ->route('artist.edit', $artist->id)
            ->with('success', 'Artista creato con successo!');
    }

    public function edit(Artist $artist)
    {
        return Inertia::render('Artist/Edit', [
            'artist' => $artist,
        ]);
    }

    public function update(ArtistRequest $request, Artist $artist)
    {
        $artist->update($request->validated());

        return redirect()
            ->back()
            ->with('success', 'Artista salvato!');
    }

    public function restore(Artist $artist)
    {
        // $user = User::withTrashed();
        if (! $artist->trashed()) {
            return back()->withErrors('L\'artista non è nel cestino.');
        }

        $artist->restore();

        return back()->with('success', 'Artista ripristinato con successo');
    }

    public function destroy(?Artist $artist, ArtistRequest $request)
    {
        return $this->standardDestroy(
            Artist::class,
            $artist,
            $request,
            relationshipChecks: [
                'records' => 'Impossibile eliminare l\'artista "[NAME]" perché è ancora associato a [COUNT] disco/i.',
            ],
            messages: [
                'bulk' => 'Record eliminati con successo',
                'force' => 'Artista eliminato definitivamente',
                'soft' => 'Artista eliminato con successo',
            ]
        );
    }
}
