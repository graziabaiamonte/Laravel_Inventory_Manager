<?php

namespace App\Http\Controllers;

use App\Services\External\DiscogsClient;
use Illuminate\Http\Request;

class DiscogsController extends Controller
{
    public function __construct(private DiscogsClient $discogsClient) {}

    public function getRelease(Request $request)
    {
        $request->validate([
            'release_id' => 'required|regex:/^[A-Za-z0-9\-_\.\/\s]+$/',
        ]);

        $release_id = $request->input('release_id');

        if (! $release_id) {
            return back()->with('error', 'No release ID provided');
        }

        if ($release_id) {
            $result = $this->discogsClient->getRelease($release_id);

            if ($result['success']) {
                $releaseData = $result['data'];

                return back()->with([
                    'success' => 'Release found successfully',
                    'discogsData' => $releaseData,
                ]);
            } else {
                return back()->with('error', 'Failed to get release: '.$result['error']);
            }
        }
    }

    public function search(Request $request)
    {
        $request->validate([
            'barcode' => 'nullable|regex:/^[A-Za-z0-9\-_\.\/\s]+$/',
            'cat_number' => 'nullable|regex:/^[A-Za-z0-9\-_\.\/\s]+$/',
        ]);

        $barcode = $request->input('barcode', '');
        $cat_number = $request->input('cat_number', '');

        if (! $barcode && ! $cat_number) {
            return back()->with('error', 'No barcode or catalog number provided');
        }

        $result = $this->discogsClient->search($barcode, $cat_number);

        if ($result['success']) {
            $releaseData = $result['data'];

            return back()->with([
                'success' => 'Release found successfully',
                'discogsData' => $releaseData,
            ]);
        } else {
            return back()->with('error', 'Failed to get release: '.$result['error']);
        }
    }
}
