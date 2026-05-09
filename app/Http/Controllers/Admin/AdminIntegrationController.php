<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Documents\Services\DocumentUploadCapabilitiesService;
use App\Http\Controllers\Controller;
use App\Models\IntegrationEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AdminIntegrationController extends Controller
{
    public function markitdown(Request $request, DocumentUploadCapabilitiesService $capabilities): View
    {
        $perPage = max(10, min(100, $request->integer('per_page', 30)));

        $events = IntegrationEvent::query()
            ->where('integration', 'markitdown')
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.integrations.markitdown', [
            'health' => $capabilities->health(),
            'baseExtensions' => $capabilities->baseExtensions(),
            'extendedExtensions' => $capabilities->extendedExtensions(),
            'allowedExtensions' => $capabilities->allowedExtensions(),
            'events' => $events,
        ]);
    }
}
