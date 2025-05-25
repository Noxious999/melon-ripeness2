<?php
namespace App\Http\Controllers;

use App\Services\DatasetChangeService;
use App\Services\EvaluationService;
use App\Services\ModelService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache; // Pastikan Cache di-use
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

class MelonController extends Controller
{
    public function __construct(
        protected ModelService $modelService,
        protected DatasetChangeService $datasetChangeService // Inject
    ) {}

    public function index(Request $request): ViewContract
    {
        $result = $request->session()->pull(EvaluationService::FLASH_RESULT);

        $modelKeysForViewCacheKey = 'melon_controller_model_keys_for_view_v3';                                                                   // Key baru
        $modelKeysForView         = Cache::remember($modelKeysForViewCacheKey, now()->addHours(6), function () use ($modelKeysForViewCacheKey) { // TAMBAHKAN use ($modelKeysForViewCacheKey)
            Log::debug("[MelonController Cache Miss] Generating model key mapping for view cache '{$modelKeysForViewCacheKey}'.");
            $mapping                = [];
            $allMetadataClassifiers = $this->modelService->loadModelMetrics(null, 'classifier');
            $allMetadataDetectors   = $this->modelService->loadModelMetrics(null, 'detector');

            $combinedDataForMapping = [];
            if (is_array($allMetadataClassifiers)) {
                $combinedDataForMapping = array_merge($combinedDataForMapping, $allMetadataClassifiers);
            }
            if (is_array($allMetadataDetectors)) {
                $combinedDataForMapping = array_merge($combinedDataForMapping, $allMetadataDetectors);
            }

            if (empty($combinedDataForMapping)) {
                Log::warning("[MelonController Cache] Combined metrics from ModelService are empty. Fallback to individual metadata load for model key mapping.");
                foreach (ModelService::BASE_MODEL_KEYS as $baseKey) {
                    foreach (ModelService::MODEL_TYPES as $type) {
                        $modelKey = "{$baseKey}_{$type}";
                        $meta     = $this->modelService->loadModelMetadata($modelKey);
                        if ($meta) {
                            $combinedDataForMapping[$modelKey] = ['algorithm_class' => $meta['algorithm_class'] ?? null];
                        }
                    }
                }
            }

            if (is_array($combinedDataForMapping) && ! empty($combinedDataForMapping)) {
                foreach ($combinedDataForMapping as $key => $metaOrMetricData) {
                    $algoClass = $metaOrMetricData['algorithm_class'] ?? ($metaOrMetricData['metadata']['algorithm_class'] ?? null);
                    if (empty($algoClass)) {
                        Log::warning("[MelonController Cache] Missing algorithm_class for model key '{$key}' during mapping.");
                        continue;
                    }
                    try {
                        $reflect  = new ReflectionClass($algoClass);
                        $baseName = $reflect->getShortName();
                    } catch (Throwable $th) {
                        $baseName = class_basename($algoClass);
                    }
                    $type          = Str::endsWith($key, '_detector') ? 'Deteksi' : 'Klasifikasi';
                    $mapping[$key] = "{$baseName} ({$type})";
                }
            } else {
                Log::error("[MelonController Cache] Failed to load any metadata or metrics for model key mapping.");
            }
            Log::info("[MelonController Cache Store] Storing " . count($mapping) . " model key mappings in cache '{$modelKeysForViewCacheKey}'.");
            return $mapping;
        });

        $lastClassifierTimeFormatted = $this->getLastTrainingTime('_classifier', []);
        $lastDetectorTimeFormatted   = $this->getLastTrainingTime('_detector', []);

        $pendingAnnotationCount        = count(Cache::get('pending_bbox_annotations', []));
        $datasetChangeNotificationData = $this->datasetChangeService->getUnseenChangesNotificationData();

        return view('melon', [
            'result'                        => $result,
            'modelKeysForView'              => $modelKeysForView ?? [],
            'lastClassifierTimeFormatted'   => $lastClassifierTimeFormatted,
            'lastDetectorTimeFormatted'     => $lastDetectorTimeFormatted,
            'pendingAnnotationCount'        => $pendingAnnotationCount,
            'showDatasetChangeNotification' => $datasetChangeNotificationData['show_notification'],
            'datasetChangeSummary'          => $datasetChangeNotificationData['summary'],
        ]);
    }

    private function getLastTrainingTime(string $suffix, array $allMetadataForTime): string// $allMetadataForTime di sini akan diabaikan jika kosong
    {
        $latest = null;
        // Jika $allMetadataForTime kosong (seperti pemanggilan dari index() di atas),
        // maka loop di bawah ini akan memuat metadata dari ModelService, yang sudah di-cache.
        if (empty($allMetadataForTime)) {
            Log::debug("[MelonController] getLastTrainingTime: allMetadataForTime is empty for '{$suffix}'. Fetching individual metadata (should be cached by ModelService).");
            foreach (ModelService::BASE_MODEL_KEYS as $baseKey) {
                $modelKey = $baseKey . $suffix;
                $meta     = $this->modelService->loadModelMetadata($modelKey); // Ini akan menggunakan cache dari ModelService
                if ($meta && isset($meta['trained_at'])) {
                    $allMetadataForTime[$modelKey] = $meta;
                }
            }
        }

        if (is_array($allMetadataForTime)) {
            foreach ($allMetadataForTime as $key => $meta) {
                if (empty($meta) || ! Str::endsWith($key, $suffix) || ! isset($meta['trained_at'])) {
                    continue;
                }
                try {
                    $time = Carbon::parse($meta['trained_at']);
                    if (! $latest || $time->isAfter($latest)) {
                        $latest = $time;
                    }
                } catch (Throwable $e) {Log::warning("Error parsing trained_at for {$key}", ['date' => $meta['trained_at'] ?? null, 'error' => $e->getMessage()]);}
            }
        }
        return $latest ? $latest->isoFormat('D MMM HH:mm') : 'Belum tersedia';
    }
}
