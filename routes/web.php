<?php

// File: routes/web.php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AppMaintenanceController;
use App\Http\Controllers\DatasetChangeController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\MelonController;
use App\Http\Controllers\PredictionController;
use Illuminate\Support\Facades\Route;

// --- Rute Anotasi ---
Route::get('/annotate', [AnnotationController::class, 'index'])->name('annotate.index');
Route::post('/annotate/save', [AnnotationController::class, 'save'])->name('annotate.save');
// Route untuk menyajikan gambar dari storage (hati-hati keamanan jika path bisa dimanipulasi)
Route::get('/storage-image/{path}', [AnnotationController::class, 'serveStorageImage'])
    ->where('path', '.*') // Izinkan karakter apa saja termasuk '/' dalam path
    ->name('storage.image');
// Route AJAX untuk estimasi bbox
Route::post('/annotate/estimate-bbox', [AnnotationController::class, 'estimateBboxAjax'])
    ->name('annotate.estimate_bbox');

// --- Rute Utama Melon (Dashboard) ---
// Redirect root ke dashboard melon
Route::get('/', function () {
    return redirect()->route('melon.index');
});
// Halaman dashboard utama
Route::get('/melon', [MelonController::class, 'index'])
    ->name('melon.index');

Route::post('/clear-application-cache', [AppMaintenanceController::class, 'clearCache'])
    ->name('app.clear_cache');

// --- Rute Prediksi ---
// Endpoint utama untuk prediksi (misalnya, prediksi default)
Route::post('/predict', [PredictionController::class, 'predictDefault'])
    ->name('predict.default');
Route::post('/upload-image-for-predict', [PredictionController::class, 'handleImageUpload'])
    ->name('predict.upload_image_temp'); // Beri nama rute
// Endpoint untuk mendapatkan hasil dari model lain (jika ada fitur perbandingan)
Route::post('/predict/all-results', [PredictionController::class, 'getAllOtherModelResults'])
    ->name('predict.all_results');
Route::post('/predict/run-bbox-classify-on-demand', [PredictionController::class, 'runBboxAndClassifyOnDemand'])
    ->name('predict.run_bbox_classify_on_demand');

// --- Rute Feedback ---
// Endpoint untuk feedback hasil deteksi
Route::post('/feedback/detection', [FeedbackController::class, 'handleDetectionFeedback'])
    ->name('feedback.detection')->middleware('throttle:10,1'); // Batasi 3 request per menit
// Endpoint untuk feedback hasil klasifikasi
Route::post('/feedback/classification', [FeedbackController::class, 'handleClassificationFeedback'])
    ->name('feedback.classification')->middleware('throttle:10,1');

// --- Rute Halaman Evaluasi & Kualitas Dataset ---
// Halaman utama evaluasi
Route::get('/evaluate', [EvaluationController::class, 'showEvaluationPage'])
    ->name('evaluate.index');

// Endpoint AJAX untuk aksi dataset (get_stats, analyze, adjust)
Route::post('/evaluate/dataset/action', [EvaluationController::class, 'handleDatasetAction'])
    ->name('evaluate.dataset.action')->middleware('throttle:10,1'); // Batasi 10 request per menit

// Endpoint SSE untuk ekstraksi fitur
Route::get('/evaluate/stream-extract-features-incremental', [EvaluationController::class, 'streamExtractFeaturesIncremental'])
    ->name('evaluate.stream.extract_features_incremental');
Route::get('/evaluate/stream-extract-features-overwrite', [EvaluationController::class, 'streamExtractFeaturesOverwrite'])
    ->name('evaluate.stream.extract_features_overwrite');

Route::get('/evaluate/dataset/recent-updates-content', [EvaluationController::class, 'getRecentUpdatesTabContent'])
    ->name('evaluate.dataset.recent_updates_content');

Route::post('/api/submit-from-pi', [PredictionController::class, 'handleSubmissionFromPi'])
    ->name('api.submit_from_pi');

// Endpoint untuk mentrigger kamera Raspberry Pi dari Laravel
Route::post('/api/trigger-pi-camera', [PredictionController::class, 'triggerPiCamera']) // Atau gunakan RaspberryPiController
    ->name('api.trigger_pi_camera');

// Endpoint SSE untuk training classifier LANGSUNG
Route::get('/evaluate/stream-train-classifier', [EvaluationController::class, 'streamTrainClassifier'])
    ->name('evaluate.stream.train_classifier');

// Endpoint SSE untuk training detector LANGSUNG
Route::get('/evaluate/stream-train-detector', [EvaluationController::class, 'streamTrainDetector'])
    ->name('evaluate.stream.train_detector');

Route::post('/dataset-changes/mark-seen', [DatasetChangeController::class, 'markAsSeen'])
    ->name('dataset.changes.mark_seen');
