<?php

namespace App\Http\Controllers;

use App\Http\Requests\SowImportConfirmRequest;
use App\Http\Requests\SowImportParseRequest;
use App\Jobs\ProcessSowParseJob;
use App\Services\SowParsingService;
use App\Services\SowProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SowImportController extends Controller
{
    public function __construct(
        private SowParsingService $sowService,
        private SowProvisioningService $provisioningService,
    ) {}

    /**
     * Parse uploaded documents: extract text immediately, dispatch AI extraction to a queue.
     */
    public function parse(SowImportParseRequest $request): JsonResponse
    {
        $combinedText = $this->sowService->buildCombinedText(
            $request->validated()['documents'],
            $request->user()
        );

        if (! $combinedText) {
            return response()->json([
                'error' => 'Could not extract any text from the provided documents. Please check the files and try again.',
            ], 422);
        }

        $parseId = Str::uuid()->toString();

        Cache::put("sow_parse:{$parseId}", ['status' => 'queued'], now()->addMinutes(30));

        ProcessSowParseJob::dispatch($parseId, $combinedText);

        return response()->json(['parse_id' => $parseId]);
    }

    /**
     * Poll for parse job status.
     */
    public function parseStatus(Request $request, string $parseId): JsonResponse
    {
        $result = Cache::get("sow_parse:{$parseId}");

        if (! $result) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($result);
    }

    /**
     * Confirm and create the project hierarchy from reviewed data.
     */
    public function confirm(SowImportConfirmRequest $request): JsonResponse
    {
        $result = $this->provisioningService->provision($request->validated());

        return response()->json($result);
    }
}
