<?php
namespace App\Http\Controllers;

use App\Services\DatasetChangeService;
use App\Services\DatasetService;
use App\Services\EvaluationService;
use Illuminate\Contracts\View\View as ViewContract; // Alias untuk View
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException; // Pastikan RuntimeException di-import
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

// Pastikan Throwable di-import

class EvaluationController extends Controller
{
    protected EvaluationService $evaluationService; // Inject service yang diperbarui
    protected DatasetService $datasetService;       // Tetap diperlukan untuk aksi dataset
    protected DatasetChangeService $datasetChangeService;

    public function __construct(
        EvaluationService $evaluationService,
        DatasetService $datasetService,
        DatasetChangeService $datasetChangeService // Inject
    ) {
        $this->evaluationService    = $evaluationService;
        $this->datasetService       = $datasetService;
        $this->datasetChangeService = $datasetChangeService; // Inisialisasi
    }

    public function streamExtractFeaturesIncremental(): StreamedResponse
    {
        return $this->streamArtisanCommand('dataset:extract-features', ['--type=all', '--set=all', '--incremental'], 'extract_features_incremental');
    }

    public function streamExtractFeaturesOverwrite(): StreamedResponse
    {
        return $this->streamArtisanCommand('dataset:extract-features', ['--type=all', '--set=all'], 'extract_features_overwrite');
    }

    public function streamTrainClassifier(): StreamedResponse
    {
        return $this->streamArtisanCommand('train:melon-classifier', ['--with-test', '--with-cv'], 'train_model_classifier');
    }

    public function streamTrainDetector(): StreamedResponse
    {
        return $this->streamArtisanCommand('train:melon-detector', ['--with-test', '--with-cv'], 'train_model_detector');
    }

    /**
     * Helper umum untuk menjalankan perintah Artisan dan stream outputnya via SSE.
     */
    private function streamArtisanCommand(string $commandName, array $arguments = [], ?string $actionKeyForLog = null): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($commandName, $arguments, $actionKeyForLog) {
            set_time_limit(0);
            if (ob_get_level() > 0) {
                @ob_end_flush();
            }

            $phpPath     = PHP_BINARY ?: 'php';
            $artisanPath = base_path('artisan');
            $command     = array_merge([$phpPath, $artisanPath, $commandName], $arguments);
            $startTime   = microtime(true);

            Log::info("[SSE Stream] Starting process: " . implode(' ', $command));
            $this->sendSseMessage(['status' => 'START', 'message' => "Memulai proses {$commandName}..."]);

            $processTimeout = match ($commandName) {
                'dataset:extract-features' => 1800.0, // 30 menit
                default                    => 7200.0, // 2 jam
            };
                                                                                                        // $process = new Process($command, base_path(), array_merge($_SERVER, $_ENV), null, null); // Menghapus timeout dari sini
            $process                 = new Process($command, base_path(), null, null, $processTimeout); // Set timeout di sini
            $commandOutputForSummary = "";

            try {
                $process->start();
                foreach ($process->getIterator($process::ITER_SKIP_ERR | $process::ITER_KEEP_OUTPUT) as $line) {
                    $trimmedLine = trim($line);
                    if (! empty($trimmedLine)) {
                        if (Str::startsWith($trimmedLine, ['✅', '🚀', 'Error', 'Warning', 'Total', 'Duration']) || Str::contains($trimmedLine, '%') || Str::contains($trimmedLine, 'Written') || Str::contains($trimmedLine, 'Skipped')) {
                            $this->sendSseMessage(['log' => $trimmedLine, 'type' => 'important']);
                            $commandOutputForSummary .= $trimmedLine . "\n";
                        } else if (strlen($trimmedLine) < 120 && ! Str::contains($trimmedLine, ['[debug]', '[info]'])) {
                            $this->sendSseMessage(['log' => $trimmedLine, 'type' => 'verbose']);
                        }
                        usleep(5000); // 5ms
                    }
                    if (connection_aborted()) {
                        Log::warning("[SSE Stream] Client disconnected during {$commandName} process.");
                        if ($process->isRunning()) {
                            $process->stop(10, SIGTERM);
                            if ($process->isRunning()) {
                                $process->stop(1, SIGKILL);
                            }
                            Log::info("[SSE Stream] Process {$commandName} stopped due to client disconnect.");
                        }
                        break;
                    }
                }

                if ($process->isRunning()) {
                    $process->wait();
                }

                $duration      = round(microtime(true) - $startTime, 2);
                $statusMessage = "Proses {$commandName} selesai dalam {$duration} detik.";
                $finalStatus   = 'DONE';

                if (! $process->isSuccessful()) {
                    $errorOutput   = $process->getErrorOutput();
                    $statusMessage = "Proses {$commandName} GAGAL (Exit Code: " . $process->getExitCode() . "). Durasi: {$duration}s.";
                    $finalStatus   = 'ERROR';
                    Log::error("[SSE Stream] Process {$commandName} failed.", ['exit_code' => $process->getExitCode(), 'stderr' => Str::limit($errorOutput, 500)]);
                    if (! empty($errorOutput)) {
                        $commandOutputForSummary .= "ERROR_STDERR: " . Str::limit($errorOutput, 200) . "\n";
                    }
                } else {
                    Log::info("[SSE Stream] Process {$commandName} finished successfully.");
                }

                $this->sendSseMessage([
                    'status'            => $finalStatus,
                    'message'           => $statusMessage,
                    'duration'          => $duration,
                    'command_name_done' => $commandName,
                ]);

                if (Str::startsWith($commandName, 'dataset:extract-features') || Str::startsWith($commandName, 'train:melon-')) {
                    $summaryForChangeService = [
                        'status'           => $finalStatus === 'DONE' ? 'Sukses' : 'Gagal',
                        'duration_seconds' => $duration,
                        'command_name'     => $commandName,
                        'arguments'        => implode(' ', $arguments),
                    ];
                    app(DatasetChangeService::class)->recordChange(
                        Str::slug($commandName, '_') . '_completed',
                        'System Process',
                        1,
                        $summaryForChangeService
                    );
                }

                if ($actionKeyForLog !== null && app()->bound(DatasetChangeService::class)) {
                    app(DatasetChangeService::class)->recordLastActionPerformed($actionKeyForLog, [
                        'status'           => $finalStatus === 'DONE' ? 'Sukses' : 'Gagal',
                        'duration_seconds' => $duration,
                        'command_name'     => $commandName,
                        'arguments'        => implode(' ', $arguments),
                        'output_summary'   => Str::limit($commandOutputForSummary, 500),
                    ]);
                }

            } catch (ProcessFailedException $e) {
                Log::error("[SSE Stream] ProcessFailedException during {$commandName} execution.", ['error' => $e->getMessage(), 'stderr' => $process->isStarted() ? $process->getErrorOutput() : 'N/A']);
                $this->sendSseMessage(['status' => 'ERROR', 'message' => "Gagal menjalankan proses {$commandName} (PFE): " . Str::limit($e->getMessage(), 150)]);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), 'Client disconnected')) {
                    Log::warning("[SSE Stream] RuntimeException caught: Client disconnected during {$commandName}.");
                } else {
                    Log::error("[SSE Stream] RuntimeException during {$commandName} execution.", ['error' => $e->getMessage()]);
                    $this->sendSseMessage(['status' => 'ERROR', 'message' => "Terjadi kesalahan runtime (RE): " . Str::limit($e->getMessage(), 200)]);
                }
            } catch (Throwable $e) {
                Log::error("[SSE Stream] Exception during {$commandName} execution.", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 1000)]);
                $this->sendSseMessage(['status' => 'ERROR', 'message' => "Terjadi kesalahan server internal (EX): " . Str::limit($e->getMessage(), 150)]);
            } finally {
                if (isset($process) && $process->isRunning()) {
                    $process->stop(10, SIGTERM);
                    if ($process->isRunning()) {
                        $process->stop(1, SIGKILL);
                    }
                    Log::warning("[SSE Stream] Process {$commandName} stopped in finally block.");
                }
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        return $response;
    }

    private function sendSseMessage(array $data): void
    {
        echo "data: " . json_encode($data) . "\n\n";
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /** Menampilkan halaman evaluasi. */
    public function showEvaluationPage(): ViewContract | RedirectResponse
    {
        try {
            $evaluationResult = $this->evaluationService->getAggregatedEvaluationDataWithBestModels();

            // --- MODIFIKASI: Tambahkan pengecekan ketat sebelum mengakses array ---
            if (! is_array($evaluationResult)) {
                Log::critical('EvaluationService::getAggregatedEvaluationDataWithBestModels() tidak mengembalikan array.', [
                    'returned_type' => gettype($evaluationResult),
                ]);
                throw new RuntimeException('Gagal memuat data evaluasi inti dari service.');
            }

            $expectedKeys = ['evaluation', 'best_classifier_key', 'best_detector_key', 'ranked_classifiers', 'ranked_detectors'];
            foreach ($expectedKeys as $expectedKey) {
                if (! array_key_exists($expectedKey, $evaluationResult)) {
                    Log::critical("Kunci yang diharapkan '{$expectedKey}' tidak ditemukan dalam hasil dari getAggregatedEvaluationDataWithBestModels().", [
                        'available_keys'      => array_keys($evaluationResult),
                        'full_result_preview' => Str::limit(json_encode($evaluationResult), 500),
                    ]);
                    // Jika 'evaluation' tidak ada, itu fatal. Untuk yang lain, bisa diberi default null/array kosong.
                    if ($expectedKey === 'evaluation') {
                        throw new RuntimeException("Data evaluasi utama ('evaluation') tidak ditemukan dari service.");
                    }
                    // Berikan default jika kunci lain yang tidak krusial hilang untuk mencegah error, tapi log tetap penting
                    $evaluationResult[$expectedKey] = match ($expectedKey) {
                        'best_classifier_key', 'best_detector_key' => null,
                        'ranked_classifiers', 'ranked_detectors' => [],
                        default                                    => null, // Seharusnya tidak sampai sini jika 'evaluation' ada
                    };
                }
            }
            // --- AKHIR MODIFIKASI ---

            $evaluation        = $evaluationResult['evaluation'];
            $bestClassifierKey = $evaluationResult['best_classifier_key'];
            $bestDetectorKey   = $evaluationResult['best_detector_key'];
            $rankedClassifiers = $evaluationResult['ranked_classifiers'];
            $rankedDetectors   = $evaluationResult['ranked_detectors'];

            $modelKeysForView = $this->evaluationService->generateModelKeysForView($evaluation ?? []); // Pass default empty array jika $evaluation null

            $dynamicAnalysis = $this->evaluationService->generateDynamicAnalysis(
                $evaluation ?? [],
                $modelKeysForView,
                $bestClassifierKey,
                $bestDetectorKey
            );

            $advancedAnalysis = [];
            if (is_array($evaluation)) {
                foreach ($evaluation as $modelKeyAdv => $dataAdv) {
                    if (empty($dataAdv) || ! is_array($dataAdv)) { // Tambah pengecekan is_array untuk $dataAdv
                        $advancedAnalysis[$modelKeyAdv] = null;
                        continue;
                    }
                    $modelAnalysis = ['model_key' => $modelKeyAdv, 'stability' => null, 'cross_validation' => null, 'overfitting' => null];

                    if (! empty($dataAdv['learning_curve_data']) && is_array($dataAdv['learning_curve_data']) && ! empty($dataAdv['learning_curve_data']['train_sizes'])) {
                        try { $modelAnalysis['stability'] = $this->evaluationService->analyzeLearningCurve($dataAdv['learning_curve_data']);} catch (Throwable $e) {}
                    }

                    $cvAccuracyScores = $dataAdv['cv_results']['metrics_per_fold']['accuracy'] ?? null;
                    if ($cvAccuracyScores && is_array($cvAccuracyScores) && ! empty($cvAccuracyScores)) {
                        try {
                            $cvStats                           = $this->evaluationService->calculateCrossValidationStats($cvAccuracyScores);
                            $ci                                = $this->evaluationService->calculateConfidenceInterval($cvAccuracyScores);
                            $modelAnalysis['cross_validation'] = ['stats' => $cvStats, 'confidence_interval' => $ci];
                        } catch (Throwable $e) {}
                    }

                    $trainingAccuracy = $dataAdv['metadata']['training_accuracy_on_processed_data'] ?? ($dataAdv['metadata']['training_accuracy'] ?? null);
                    // Pastikan validation_metrics dan metrics_per_class ada dan berupa array sebelum diakses
                    $validationAccuracy = null;
                    if (isset($dataAdv['validation_metrics']['metrics_per_class']) && is_array($dataAdv['validation_metrics']['metrics_per_class'])) {
                        $validationAccuracy = $dataAdv['validation_metrics']['metrics_per_class']['accuracy'] ?? null;
                    }

                    if (is_numeric($trainingAccuracy) && is_numeric($validationAccuracy)) {
                        try { $modelAnalysis['overfitting'] = $this->evaluationService->analyzeOverfitting((float) $trainingAccuracy, (float) $validationAccuracy);} catch (Throwable $e) {}
                    }
                    $advancedAnalysis[$modelKeyAdv] = $modelAnalysis;
                }
            }

            $pendingAnnotationCount         = count(Cache::get('pending_bbox_annotations', []));
            $datasetChangeNotificationData  = $this->datasetChangeService->getUnseenChangesNotificationData();
            $datasetUpdateStatusFromService = $this->datasetService->getDatasetUpdateStatus();
            $lastExtractIncrementalAction   = $this->datasetChangeService->getLastActionPerformed('extract_features_incremental');
            $lastExtractOverwriteAction     = $this->datasetChangeService->getLastActionPerformed('extract_features_overwrite');
            $lastTrainClassifierAction      = $this->datasetChangeService->getLastActionPerformed('train_model_classifier');
            $lastTrainDetectorAction        = $this->datasetChangeService->getLastActionPerformed('train_model_detector');

            return view('evaluate', [
                'evaluation'                    => $evaluation ?? [], // Pastikan dikirim sebagai array
                'modelKeysForView'              => $modelKeysForView,
                'dynamicAnalysis'               => $dynamicAnalysis,
                'advancedAnalysis'              => $advancedAnalysis,
                'lastClassifierTimeFormatted'   => $this->evaluationService->getLatestTrainingTimes($evaluation ?? [])[0] ?? 'N/A',
                'lastDetectorTimeFormatted'     => $this->evaluationService->getLatestTrainingTimes($evaluation ?? [])[1] ?? 'N/A',
                'datasetUpdateStatus'           => $datasetUpdateStatusFromService,
                'pendingAnnotationCount'        => $pendingAnnotationCount,
                'showDatasetChangeNotification' => $datasetChangeNotificationData['show_notification'],
                'datasetChangeSummary'          => $datasetChangeNotificationData['summary'],
                'lastExtractIncrementalAction'  => $lastExtractIncrementalAction,
                'lastExtractOverwriteAction'    => $lastExtractOverwriteAction,
                'lastTrainClassifierAction'     => $lastTrainClassifierAction,
                'lastTrainDetectorAction'       => $lastTrainDetectorAction,
                'bestClassifierKey'             => $bestClassifierKey,
                'bestDetectorKey'               => $bestDetectorKey,
                'rankedClassifiers'             => $rankedClassifiers,
                'rankedDetectors'               => $rankedDetectors,
            ]);

        } catch (Throwable $e) {
            Log::error("Error loading evaluation page data", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 1000)]);
            return redirect()->route('melon.index')
                ->with(EvaluationService::FLASH_ERROR, 'Gagal memuat halaman evaluasi: Terjadi kesalahan internal. (' . Str::limit($e->getMessage(), 100) . ')');
        }
    }

    /** Handle AJAX requests untuk dataset actions (get_stats, analyze, adjust). */
    public function handleDatasetAction(Request $request): JsonResponse
    {
        $action = $request->input('action');
        if (! $request->ajax()) {
            abort(400, 'Aksi ini hanya bisa diakses via AJAX.');
        }
        try {
            switch ($action) {
                case 'get_stats':
                    $stats = $this->datasetService->getStatistics();
                    return response()->json(['success' => true, 'stats' => $stats, 'timestamp' => now()->toDateTimeString()]);
                case 'analyze':
                    $stats    = $this->datasetService->getStatistics();
                    $analysis = $this->datasetService->analyzeQuality($stats);
                    return response()->json(['success' => true, 'message' => 'Analisis kualitas dataset selesai.', 'details' => $analysis]);
                case 'adjust':
                    $result = $this->datasetService->adjustBalance();
                    return response()->json($result);
                default:
                    return response()->json(['success' => false, 'message' => 'Aksi tidak valid.'], 400);
            }
        } catch (Throwable $e) {
            Log::error("Error handling dataset action '{$action}' (AJAX)", ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server saat memproses aksi dataset.'], 500);
        }
    }

    /**
     * Mengambil konten HTML yang sudah dirender untuk tab "Pembaruan Kualitas" via AJAX.
     */
    public function getRecentUpdatesTabContent(Request $request): JsonResponse
    {
        if (! $request->ajax()) {
            // Hanya izinkan permintaan AJAX untuk endpoint ini
            return response()->json(['success' => false, 'message' => 'Akses tidak diizinkan.'], 403);
        }

        try {
                                                               // Paksa kalkulasi ulang status dengan membersihkan cache-nya terlebih dahulu
            Cache::forget('dataset_service_update_status_v4'); // Kunci cache dari DatasetService
            $datasetUpdateStatus = $this->datasetService->getDatasetUpdateStatus();

            $pendingAnnotationCountForView = count(Cache::get('pending_bbox_annotations', []));
            $datasetChangeNotificationData = $this->datasetChangeService->getUnseenChangesNotificationData();

            // Data yang dibutuhkan oleh partial 'partials.recent-updates-tab'
            $dataForPartial = [
                'datasetUpdateStatus'           => $datasetUpdateStatus,
                'pendingAnnotationCountForView' => $pendingAnnotationCountForView,
                'showDatasetChangeNotification' => $datasetChangeNotificationData['show_notification'],
                'datasetChangeSummary'          => $datasetChangeNotificationData['summary'],
                // Anda bisa menambahkan variabel lain yang mungkin dibutuhkan oleh partial di sini
            ];

            // Render partial view menjadi string HTML
            $htmlContent = view('partials.recent-updates-tab', $dataForPartial)->render();

            return response()->json([
                'success'   => true,
                'html'      => $htmlContent,
                'timestamp' => now()->isoFormat('D MMM YYYY, HH:mm:ss'), // Timestamp pembaruan
            ]);

        } catch (Throwable $e) {
            Log::error("Error fetching recent updates tab content (AJAX)", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            return response()->json(['success' => false, 'message' => 'Gagal memuat konten pembaruan: ' . Str::limit($e->getMessage(), 100)], 500);
        }
    }

    /** Display the evaluation index page. */
    public function index(): ViewContract | RedirectResponse
    {
        return $this->showEvaluationPage();
    }
} // Akhir Class EvaluationController
