<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AudioController extends Controller
{
    public function __invoke(CallRecording $call): BinaryFileResponse
    {
        Gate::authorize('view', $call);
        abort_unless(Storage::disk('local')->exists($call->path), 404);

        return response()->file(Storage::disk('local')->path($call->path), [
            'Content-Type' => $call->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
