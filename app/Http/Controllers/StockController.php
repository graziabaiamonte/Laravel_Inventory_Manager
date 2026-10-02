<?php

namespace App\Http\Controllers;

use App\Facades\Flash;
use App\Http\Requests\StockRequest;
use App\Models\Stock;

class StockController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StockRequest $request)
    {
        $validated = $request->validated();

        // Check if stock already exists for this record+area combination
        $existingStock = Stock::where('record_id', $validated['record_id'])
            ->where('area_id', $validated['area_id'])
            ->first();

        if ($existingStock) {
            // Update existing stock by adding the quantity
            $existingStock->update([
                'quantity' => $existingStock->quantity + $validated['quantity'],
                'description' => $validated['description'] ?? $existingStock->description,
            ]);

            return redirect()
                ->back()
                ->with([
                    'success' => 'Stock aggiornato con successo! Quantità incrementata.',
                ]);
        }

        // Create new stock if none exists
        $stock = Stock::create($validated);

        return redirect()
            ->back()
            ->with([
                'success' => 'Stock creato con successo!',
            ]);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(StockRequest $request, Stock $stock)
    {
        $stock->update($request->validated());

        Flash::success('Stock aggiornato con successo!');

        return redirect()
            ->back();
        // ->with([
        //     'success' => 'Stock aggiornato con successo!',
        // ]);
    }

    /**
     * Update order column with dragging.
     */
    public function swap(Stock $dragging, Stock $target)
    {
        $this->authorize('update', $dragging);
        $this->authorize('update', $target);

        Stock::swapOrder($dragging, $target);

        return redirect()
            ->back()
            ->with('success', 'Stock riordinato con successo!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Stock $stock)
    {
        $this->authorize('delete', $stock);

        $deleted = $stock->deleteOrEmpty();

        if ($deleted) {
            Flash::success('Stock eliminato con successo!');
        } else {
            Flash::warning(
                'Lo stock è stato azzerato ma non eliminato: è referenziato da una vendita o da uno scarico.'
            );
        }

        return redirect()
            ->back();
        // ->with([
        //     'success' => 'Stock eliminato con successo!',
        // ]);
    }
}
