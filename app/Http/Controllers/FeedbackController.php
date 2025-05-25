<?php
namespace App\Http\Controllers;

use App\Services\DatasetChangeService;
use App\Services\FeatureExtractionService;
use App\Services\FeedbackService;
use App\Services\PredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class FeedbackController extends Controller
{
    protected DatasetChangeService $datasetChangeService;
    protected FeatureExtractionService $featureExtractor;
    protected PredictionService $predictionService;
    protected FeedbackService $feedbackService;

    public function __construct(
        FeatureExtractionService $featureExtractor,
        PredictionService $predictionService,
        FeedbackService $feedbackService,
        DatasetChangeService $datasetChangeService
    ) {
        $this->featureExtractor     = $featureExtractor;
        $this->predictionService    = $predictionService;
        $this->feedbackService      = $feedbackService;
        $this->datasetChangeService = $datasetChangeService;
    }

    public function handleDetectionFeedback(Request $request): JsonResponse
    {
        // Validasi input
        $data = $request->validate([
            'originalFilename'      => 'required|string|max:255',
            's3_temp_image_path'    => 'required|string',
            'is_melon'              => 'required|in:yes,no',
            'estimated_bbox'        => 'nullable|array',
            'estimated_bbox.cx'     => 'nullable|numeric|min:0|max:1',
            'estimated_bbox.cy'     => 'nullable|numeric|min:0|max:1',
            'estimated_bbox.w'      => 'nullable|numeric|min:0.001|max:1',
            'estimated_bbox.h'      => 'nullable|numeric|min:0.001|max:1',
            'main_model_prediction' => 'required|in:melon,non_melon',
            'main_model_key'        => 'required|string',
        ]);

        $s3TempImagePath        = $data['s3_temp_image_path'];
        $originalFilename       = $data['originalFilename'];
        $newIsMelonFeedback     = ($data['is_melon'] === 'yes');
        $newFeedbackClass       = $newIsMelonFeedback ? 'melon' : 'non_melon';
        $s3DestImagePathDataset = FeedbackService::S3_TRAIN_SET_DIR . '/' . $originalFilename;
        $imagePathForCsv        = 'train/' . $originalFilename;

        // Cek apakah S3 temporary path ada sebelum digunakan
        if (! Storage::disk('s3')->exists($s3TempImagePath)) {
            Log::error("[FeedbackDetection] File temporary S3 tidak ditemukan.", ['path' => $s3TempImagePath]);
            return response()->json(['success' => false, 'message' => 'File temporary tidak ditemukan di S3. Sesi mungkin sudah kedaluwarsa.'], 404);
        }

        $fileContentForHash = Storage::disk('s3')->get($s3TempImagePath);
        if ($fileContentForHash === null) {
            return response()->json(['success' => false, 'message' => 'Gagal membaca file temporary untuk hash.'], 500);
        }
        $fileHash = md5($fileContentForHash);
        unset($fileContentForHash); // Bebaskan memori

        // Cek apakah sudah ada feedback klasifikasi untuk gambar ini
        $classificationHashes             = $this->feedbackService->loadFeedbackHashes('classification');
        $hasClassificatioFeedbackForImage = false;
        foreach ($classificationHashes as $identifier => $details) {
            if (Str::startsWith($identifier, $fileHash . '_')) {
                $hasClassificatioFeedbackForImage = true;
                break;
            }
        }

        // Logika Prioritas: Jika sudah ada feedback klasifikasi (implisit melon), jangan biarkan diubah jadi non_melon via feedback deteksi umum
        if ($hasClassificatioFeedbackForImage && $newFeedbackClass === 'non_melon') {
            Log::warning("[FeedbackDetection] Ditolak: Gambar {$originalFilename} sudah memiliki feedback klasifikasi, tidak bisa diubah menjadi 'non_melon'.");
            return response()->json([
                'success'                   => false,
                'message'                   => 'Gambar ini sudah memiliki feedback klasifikasi detail (sebagai melon). Anda tidak bisa mengubah feedback deteksi menjadi "Bukan Melon". Hapus feedback klasifikasi terlebih dahulu jika ingin mengubah status gambar.',
                'saved_definitively'        => true, // Nonaktifkan tombol feedback deteksi
                'pending_annotations_count' => count(Cache::get('pending_bbox_annotations', [])),
            ], 409); // 409 Conflict
        }

        $detectionHashes       = $this->feedbackService->loadFeedbackHashes('detection');
        $existingFeedbackEntry = $detectionHashes[$fileHash] ?? null;
        $oldFeedbackClass      = null;
        $isChangingFeedback    = false;

        if ($existingFeedbackEntry) {
            if (preg_match('/\((\w+)\)/', $existingFeedbackEntry, $matches)) {
                $oldFeedbackClass = strtolower($matches[1]);
            }
            if ($oldFeedbackClass === $newFeedbackClass) {
                $humanReadableOldFeedback = Str::of($existingFeedbackEntry)->before(' - ')->trim()->toString() ?: "Feedback '$oldFeedbackClass'";
                return response()->json([
                    'success'                   => true,
                    'message'                   => "Feedback sebelumnya sudah {$humanReadableOldFeedback}. Tidak ada perubahan.",
                    'saved_definitively'        => true,
                    'pending_annotations_count' => count(Cache::get('pending_bbox_annotations', [])),
                ], 200);
            }
            $isChangingFeedback = true;
            Log::info("[FeedbackDetection] Perubahan feedback terdeteksi untuk {$originalFilename}: dari '{$oldFeedbackClass}' ke '{$newFeedbackClass}'.");
        }

        try {
            $detectionFeatures = $this->featureExtractor->extractDetectionFeatures($s3TempImagePath);
            if (! $detectionFeatures) {
                throw new RuntimeException("Gagal ekstrak fitur deteksi.");
            }

            $otherDetectorResults = $this->predictionService->runOtherDetectors($detectionFeatures, null, [$data['main_model_key']]);
            $agreeingModelCount   = ($data['main_model_prediction'] === $newFeedbackClass) ? 1 : 0;
            foreach ($otherDetectorResults as $result) {
                if (($result['success'] ?? false) && isset($result['detected']) && (($result['detected'] ? 'melon' : 'non_melon') === $newFeedbackClass)) {
                    $agreeingModelCount++;
                }
            }

            $minAgreeRequired       = 3;
            $responseMessage        = '';
            $fileActionDescription  = '';
            $csvOperationSuccess    = true;
            $askForAnnotation       = false;
            $finalFileExistsInTrain = Storage::disk('s3')->exists($s3DestImagePathDataset);
            $datasetModified        = false;
            $pendingImages          = Cache::get('pending_bbox_annotations', []);
            $imageKeyForCache       = str_replace(DIRECTORY_SEPARATOR, '/', $s3DestImagePathDataset);

            // Logika menangani perubahan feedback SEBELUM menentukan aksi utama
            if ($isChangingFeedback) {
                Log::info("[FeedbackDetection] Memproses perubahan feedback untuk {$originalFilename}...");
                // Jika berubah DARI melon (menjadi non_melon)
                if ($oldFeedbackClass === 'melon') {
                    if (isset($pendingImages[$imageKeyForCache])) {
                        unset($pendingImages[$imageKeyForCache]);
                        $datasetModified = true;
                        Log::info("[FeedbackDetection] Gambar {$originalFilename} dihapus dari pending BBox karena feedback diubah DARI melon.");
                    }
                    // Jika berubah DARI melon, kita HARUS menghapus entri CSV melon lama.
                    // Ini akan dilakukan secara implisit jika kita nanti menambahkan 'non_melon',
                    // TAPI lebih aman menghapusnya secara eksplisit di sini.
                    Log::info("[FeedbackDetection] Menghapus semua entri CSV untuk {$imagePathForCsv} karena berubah DARI melon.");
                    if ($this->feedbackService->removeAnnotationEntry(FeedbackService::S3_TRAIN_ANNOTATION_CSV, $imagePathForCsv)) {
                        $datasetModified = true;
                    }
                }
                // Jika berubah DARI non_melon (menjadi melon)
                if ($oldFeedbackClass === 'non_melon') {
                    // Hapus entri 'non_melon' lama.
                    Log::info("[FeedbackDetection] Menghapus semua entri CSV (termasuk non_melon lama) untuk {$imagePathForCsv} karena berubah DARI non_melon.");
                    if ($this->feedbackService->removeAnnotationEntry(FeedbackService::S3_TRAIN_ANNOTATION_CSV, $imagePathForCsv)) {
                        $datasetModified = true;
                    }
                }
            }

            // --- Aksi Utama Berdasarkan Konsensus BARU ---

            if ($agreeingModelCount > $minAgreeRequired) {
                // KASUS: Konsensus Tinggi (SKIP_HIGH_AGREEMENT)
                $responseMessage            = "Feedback '{$newFeedbackClass}' dicatat (konsensus tinggi: {$agreeingModelCount} model setuju). ";
                $detectionHashes[$fileHash] = "{$originalFilename} ({$newFeedbackClass}) - SKIPPED_HIGH_AGREEMENT (Agree: {$agreeingModelCount})";

                // Logika High-Agree: Dianggap 'mudah', jadi *dihapus* dari training set aktif
                if ($finalFileExistsInTrain) {
                    Storage::disk('s3')->delete($s3DestImagePathDataset);
                    $fileActionDescription  = "File dari direktori training dihapus (high agree).";
                    $finalFileExistsInTrain = false;
                    $datasetModified        = true;
                } else { $fileActionDescription = "File tidak ada di direktori training.";}

                // Hapus dari pending BBox jika ada
                if (isset($pendingImages[$imageKeyForCache])) {
                    unset($pendingImages[$imageKeyForCache]);
                    $datasetModified = true;
                    Log::info("[FeedbackDetection] High Agree: Dihapus dari pending BBox.");
                }

                // Hapus entri CSV apapun yang mungkin ada (baik melon atau non_melon)
                if ($this->feedbackService->removeAnnotationEntry(FeedbackService::S3_TRAIN_ANNOTATION_CSV, $imagePathForCsv)) {
                    $datasetModified = true;
                    Log::info("[FeedbackDetection] High Agree: Entri CSV dibersihkan.");
                }
                $responseMessage .= $fileActionDescription . " Entri CSV dibersihkan.";
                $csvOperationSuccess = true; // Dianggap sukses karena tujuannya bersih

            } else {
                // KASUS: Konsensus Rendah (PROSES KE TRAINING)
                $responseMessage = "Feedback '{$newFeedbackClass}' diterima (konsensus rendah: {$agreeingModelCount} model setuju). ";

                // Pastikan file ada di training set
                if (! $finalFileExistsInTrain) {
                    if (Storage::disk('s3')->copy($s3TempImagePath, $s3DestImagePathDataset)) {
                        $fileActionDescription  = "Gambar disalin ke direktori training.";
                        $finalFileExistsInTrain = true;
                        $datasetModified        = true;
                    } else {throw new RuntimeException("Gagal menyalin gambar ke S3 dataset training.");}
                } else {
                    $fileActionDescription = "Gambar sudah ada di direktori training (dipertahankan).";
                    if ($isChangingFeedback) {$datasetModified = true;} // Tetap tandai modifikasi jika ini perubahan
                }

                if ($finalFileExistsInTrain) {
                    if ($newIsMelonFeedback) { // Feedback BARU adalah 'melon' (low agree)
                        if (! $hasClassificatioFeedbackForImage) {
                            $askForAnnotation           = true;
                            $detectionHashes[$fileHash] = "{$originalFilename} ({$newFeedbackClass}) - AWAITING_BBOX (Agree: {$agreeingModelCount})";
                            $responseMessage .= "Gambar perlu anotasi BBox manual.";
                            if (! isset($pendingImages[$imageKeyForCache])) {
                                $pendingImages[$imageKeyForCache] = ['added_at' => now()->toDateTimeString(), 'filename' => $originalFilename, 'set' => 'train'];
                                $datasetModified                  = true;
                            }
                                                        // CSV *tidak* diupdate di sini, menunggu anotasi manual.
                                                        // Pastikan entri lama sudah dihapus (dilakukan di blok `isChangingFeedback`)
                            if (! $isChangingFeedback) { // Jika *bukan* perubahan (berarti sebelumnya tdk ada atau high-agree), pastikan bersih
                                $this->feedbackService->removeAnnotationEntry(FeedbackService::S3_TRAIN_ANNOTATION_CSV, $imagePathForCsv);
                            }
                            $csvOperationSuccess = true; // Tidak ada aksi CSV, jadi sukses
                        } else {
                            $detectionHashes[$fileHash] = "{$originalFilename} ({$newFeedbackClass}) - MELON_CONFIRMED_LOW_AGREE (Agree: {$agreeingModelCount}, HadCls)";
                            $responseMessage .= "Status deteksi sebagai melon dikonfirmasi (konsensus rendah). Anotasi BBox/Klasifikasi yang sudah ada dipertahankan.";
                            $csvOperationSuccess = true; // Tidak ada aksi CSV, biarkan yang ada
                        }
                    } else { // Feedback BARU adalah 'non_melon' (low agree)
                                 // **PERBAIKAN**: Pastikan semua entri lama (terutama melon) dihapus SEBELUM menambahkan 'non_melon'.
                                 // Ini sudah ditangani di blok `isChangingFeedback`. Jika *bukan* perubahan,
                                 // kita juga perlu memastikan bersih sebelum menambah 'non_melon'.
                        Log::info("[FeedbackDetection] Low Agree (Non-Melon): Membersihkan entri CSV lama sebelum menambah 'non_melon'.");
                        $this->feedbackService->removeAnnotationEntry(FeedbackService::S3_TRAIN_ANNOTATION_CSV, $imagePathForCsv);

                        // Sekarang tambahkan baris 'non_melon' (tanpa BBox)
                        $csvOperationSuccess = $this->feedbackService->updateSingleAnnotationForImage(
                            FeedbackService::S3_TRAIN_ANNOTATION_CSV, $imagePathForCsv,
                            'non_melon', null, null
                        );
                        if ($csvOperationSuccess) {
                            $detectionHashes[$fileHash] = "{$originalFilename} ({$newFeedbackClass}) - NON_MELON_PROCESSED (Agree: {$agreeingModelCount})";
                            $responseMessage .= "CSV anotasi diperbarui sebagai non-melon.";
                            $datasetModified = true;
                        } else {
                            $responseMessage .= "GAGAL memperbarui CSV anotasi sebagai non-melon.";
                        }
                        // Hapus dari pending BBox jika ada
                        if (isset($pendingImages[$imageKeyForCache])) {
                            unset($pendingImages[$imageKeyForCache]);
                            $datasetModified = true;
                        }
                    }
                } else {
                    $responseMessage .= "File tidak berhasil disiapkan di direktori training.";
                    $csvOperationSuccess = false;
                }
            }

            Cache::put('pending_bbox_annotations', $pendingImages, now()->addDays(30));
            $this->feedbackService->saveFeedbackHashes($detectionHashes, 'detection');

            if ($datasetModified) {
                $this->datasetChangeService->recordChange(
                    'feedback_detection_to_' . $newFeedbackClass . ($isChangingFeedback ? '_changed' : ''),
                    $originalFilename, 1,
                    ['agreeing_models' => $agreeingModelCount, 'action' => $fileActionDescription, 'old_class_if_changed' => $oldFeedbackClass]
                );
            }

            // Overall success depends on the path taken
            $overallSuccess = $csvOperationSuccess && (($agreeingModelCount > $minAgreeRequired) || $finalFileExistsInTrain);

            return response()->json([
                'success'                   => $overallSuccess,
                'message'                   => $responseMessage,
                'saved_definitively'        => true, // Umpan balik deteksi dianggap final setelah diproses
                'ask_for_annotation'        => $askForAnnotation,
                'pending_annotations_count' => count($pendingImages),
            ]);

        } catch (ValidationException $e) {return response()->json(['success' => false, 'message' => 'Input tidak valid: ' . $e->validator->errors()->first(), 'errors' => $e->errors()], 422);} catch (Throwable $e) {Log::error("Error processing detection feedback", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 1000)]);return response()->json(['success' => false, 'message' => 'Gagal memproses feedback: ' . Str::limit($e->getMessage(), 150)], 500);}
    }

    public function handleClassificationFeedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'originalFilename'      => 'required|string',
            'tempServerFilename'    => 'required|string', // Pastikan JS mengirim ini
            'actual_label'          => 'required|in:matang,belum_matang',
            'estimated_bbox'        => 'required|array',
            'estimated_bbox.cx'     => 'required|numeric|min:0|max:1',
            'estimated_bbox.cy'     => 'required|numeric|min:0|max:1',
            'estimated_bbox.w'      => 'required|numeric|min:0.001|max:1',
            'estimated_bbox.h'      => 'required|numeric|min:0.001|max:1',
            'main_model_prediction' => 'required|in:matang,belum_matang',
            'main_model_key'        => 'required|string',
        ]);

        $originalFilename         = $data['originalFilename'];
        $s3TempImageName          = $data['tempServerFilename'];
        $s3TempImagePath          = PredictionService::S3_UPLOAD_DIR_TEMP . '/' . $s3TempImageName;
        $newFeedbackRipenessClass = ($data['actual_label'] === 'matang') ? 'ripe' : 'unripe';
        $estimatedBbox            = $data['estimated_bbox'];
        $imagePathForCsv          = 'train/' . $originalFilename;
        $trainCsvPath             = FeedbackService::S3_TRAIN_ANNOTATION_CSV;
        $s3DestImagePathDataset   = FeedbackService::S3_TRAIN_SET_DIR . '/' . $originalFilename;

        // Cek S3 temp path
        if (! Storage::disk('s3')->exists($s3TempImagePath)) {
            Log::error("[FeedbackClassification] File temporary S3 tidak ditemukan.", ['path' => $s3TempImagePath]);
            return response()->json(['success' => false, 'message' => 'File temporary klasifikasi tidak ditemukan di S3.'], 404);
        }

        $fileContentForHash = Storage::disk('s3')->get($s3TempImagePath);
        if ($fileContentForHash === null) {return response()->json(['success' => false, 'message' => 'Gagal membaca file temporary untuk hash klasifikasi.'], 500);}
        $fileHash                      = md5($fileContentForHash);unset($fileContentForHash);
        $bboxStringForHash             = implode('_', array_map(fn($val) => (string) round((float) $val, 4), $estimatedBbox));
        $exactClassificationIdentifier = $fileHash . '_' . $bboxStringForHash . '_' . $newFeedbackRipenessClass;
        $bboxOnlyIdentifier            = $fileHash . '_' . $bboxStringForHash;

        $classificationHashes          = $this->feedbackService->loadFeedbackHashes('classification');
        $isChangingFeedback            = false;
        $oldSpecificIdentifierToRemove = null;
        $oldRipenessClassForLog        = null;

        // Cek jika feedback SAMA PERSIS sudah ada
        if (isset($classificationHashes[$exactClassificationIdentifier])) {
            $humanReadableOldFeedback = Str::of($classificationHashes[$exactClassificationIdentifier])->before(' - ')->trim()->toString() ?: "Feedback klasifikasi ini";
            return response()->json([
                'success'                   => true,
                'message'                   => "{$humanReadableOldFeedback} sudah pernah diberikan untuk BBox ini. Tidak ada perubahan.",
                'saved_definitively'        => true,
                'pending_annotations_count' => count(Cache::get('pending_bbox_annotations', [])),
            ], 200);
        }
        // Cek jika ada feedback BERBEDA untuk BBox yang sama (perubahan)
        foreach ($classificationHashes as $identifier => $details) {
            if (Str::startsWith($identifier, $bboxOnlyIdentifier . '_')) {
                $oldSpecificIdentifierToRemove = $identifier;
                $isChangingFeedback            = true;
                if (preg_match('/\((\w+), BBox:/', $details, $matches)) {$oldRipenessClassForLog = strtolower($matches[1]);}
                Log::info("[FeedbackClassification] Perubahan feedback klasifikasi terdeteksi untuk BBox {$bboxStringForHash} pada {$originalFilename}: dari '{$oldRipenessClassForLog}' ke '{$newFeedbackRipenessClass}'.");
                break;
            }
        }

        $detectionHashes                 = $this->feedbackService->loadFeedbackHashes('detection');
        $previousDetectionFeedbackStatus = $detectionHashes[$fileHash] ?? null;

        // Poin 1 & 6: Mencegah "Kesia-siaan" & Prioritas
        if ($previousDetectionFeedbackStatus && Str::contains($previousDetectionFeedbackStatus, 'AWAITING_BBOX')) {
            Log::info("[FeedbackClassification] Ditolak: Gambar {$originalFilename} sedang AWAITING_BBOX.");
            return response()->json([
                'success'                   => false,
                'message'                   => 'Gambar ini menunggu anotasi BBox manual. Silakan berikan feedback klasifikasi melalui Halaman Anotasi.',
                'saved_definitively'        => false,
                'pending_annotations_count' => count(Cache::get('pending_bbox_annotations', [])),
            ], 403);
        }

        // --- PERBAIKAN POIN 2 (Pastikan ini diterapkan) ---
        if ($previousDetectionFeedbackStatus && Str::contains($previousDetectionFeedbackStatus, '(non_melon)')) {
            Log::info("[FeedbackClassification] Ditolak: Gambar {$originalFilename} sudah memiliki feedback 'non_melon'.");
            return response()->json(['success' => false, 'message' => 'Feedback klasifikasi tidak diizinkan untuk gambar yang sudah diberi feedback "non_melon".'], 403);
        }
        // --- AKHIR PERBAIKAN POIN 2 ---

        try {
            $colorFeatures = $this->featureExtractor->extractColorFeaturesFromBbox($s3TempImagePath, $estimatedBbox);
            if (! $colorFeatures) {
                throw new RuntimeException("Gagal ekstrak fitur warna.");
            }

            $otherClassifierResults = $this->predictionService->runOtherClassifiers($colorFeatures, null, [$data['main_model_key']]);
            $agreeingModelCountCls  = (($data['main_model_prediction'] === 'matang' ? 'ripe' : 'unripe') === $newFeedbackRipenessClass) ? 1 : 0;
            foreach ($otherClassifierResults as $result) {
                if (($result['success'] ?? false) && isset($result['prediction'])) {
                    $modelPredictionRaw = ($result['prediction'] === 'matang') ? 'ripe' : (($result['prediction'] === 'belum_matang') ? 'unripe' : null);
                    if ($modelPredictionRaw === $newFeedbackRipenessClass) {
                        $agreeingModelCountCls++;
                    }
                }
            }

            $minAgreeRequired       = 3;
            $responseMessage        = '';
            $fileActionDescription  = '';
            $csvOperationSuccess    = false;
            $finalFileExistsInTrain = Storage::disk('s3')->exists($s3DestImagePathDataset);
            $datasetModified        = false;
            $pendingImages          = Cache::get('pending_bbox_annotations', []);
            $imageKeyForCache       = str_replace(DIRECTORY_SEPARATOR, '/', $s3DestImagePathDataset);

            // Hapus hash lama jika ini adalah perubahan
            if ($isChangingFeedback && $oldSpecificIdentifierToRemove) {
                unset($classificationHashes[$oldSpecificIdentifierToRemove]);
            }

            // Aksi berdasarkan Konsensus Klasifikasi
            $actionType = ($agreeingModelCountCls > $minAgreeRequired) ? "High Agree" : "Low Agree";
            Log::info("[FeedbackClassification] Memproses {$actionType} untuk {$originalFilename}, BBox: {$bboxStringForHash}");

            // Selalu pastikan file ada di training set jika memberikan feedback klasifikasi (kecuali high-agree-skip, tapi di sini kita *selalu* simpan)
            if (! $finalFileExistsInTrain) {
                if (Storage::disk('s3')->copy($s3TempImagePath, $s3DestImagePathDataset)) {
                    $fileActionDescription  = "Gambar disalin ke direktori training.";
                    $finalFileExistsInTrain = true;
                    $datasetModified        = true;
                } else {throw new RuntimeException("Gagal menyalin gambar ke S3 ({$actionType}).");}
            } else {
                $fileActionDescription = "Gambar sudah ada di direktori training.";
                if ($isChangingFeedback) {$datasetModified = true;} // Jika hanya mengubah CSV, tetap tandai modif
            }

            // Jika file siap, update CSV
            if ($finalFileExistsInTrain) {
                // **PENTING**: FeedbackService::updateSingleAnnotationForImage akan MENGUPDATE BBox yg sama atau MENAMBAH jika BBox beda.
                // Ini adalah perilaku yang diinginkan, karena satu gambar bisa punya >1 BBox.
                $csvOperationSuccess = $this->feedbackService->updateSingleAnnotationForImage(
                    $trainCsvPath, $imagePathForCsv, 'melon', $newFeedbackRipenessClass, $estimatedBbox
                );

                if ($csvOperationSuccess) {
                    $datasetModified = true;
                    $responseMessage = "Feedback klasifikasi '{$data['actual_label']}' diterima ({$actionType}: {$agreeingModelCountCls} model setuju). CSV anotasi diperbarui.";
                    // Tentukan hash entry berdasarkan konsensus
                    if ($agreeingModelCountCls > $minAgreeRequired) {
                        $classificationHashes[$exactClassificationIdentifier] = "{$originalFilename} ({$newFeedbackRipenessClass}, BBox: {$bboxStringForHash}) - CLS_PROCESSED_HIGH_AGREE (Agree: {$agreeingModelCountCls})";
                    } else {
                        $classificationHashes[$exactClassificationIdentifier] = "{$originalFilename} ({$newFeedbackRipenessClass}, BBox: {$bboxStringForHash}) - CLS_PROCESSED_LOW_AGREE (Agree: {$agreeingModelCountCls})";
                    }

                    // Implisit update deteksi jika belum 'melon' (atau jika 'melon' tapi AWAITING_BBOX)
                    $currentDetFeedback = $detectionHashes[$fileHash] ?? null;
                    if (! $currentDetFeedback || ! Str::contains($currentDetFeedback, '(melon)') || Str::contains($currentDetFeedback, 'AWAITING_BBOX')) {
                        $detectionHashes[$fileHash] = "{$originalFilename} (melon) - IMPLICIT_FROM_CLS_FEEDBACK (ClsAgree: {$agreeingModelCountCls})";
                        $this->feedbackService->saveFeedbackHashes($detectionHashes, 'detection');
                        Log::info("[FeedbackClassification] Umpan balik deteksi diubah menjadi 'melon' secara implisit untuk {$originalFilename}");
                        // Hapus dari pending BBox jika statusnya AWAITING_BBOX, karena sekarang sudah ada BBox+Klasifikasi
                        if (isset($pendingImages[$imageKeyForCache])) {
                            unset($pendingImages[$imageKeyForCache]);
                            Cache::put('pending_bbox_annotations', $pendingImages, now()->addDays(30));
                        }
                    }

                } else {
                    $responseMessage = "Feedback klasifikasi '{$data['actual_label']}' diterima ({$actionType}), TAPI GAGAL memperbarui CSV anotasi.";
                }
            } else {
                $responseMessage     = "Feedback klasifikasi diterima ({$actionType}), TAPI file tidak berhasil disiapkan di training untuk update CSV.";
                $csvOperationSuccess = false;
            }

            $this->feedbackService->saveFeedbackHashes($classificationHashes, 'classification');

            if ($datasetModified) {
                $this->datasetChangeService->recordChange(
                    'feedback_classification_to_' . Str::slug($data['actual_label'], '_') . ($isChangingFeedback ? '_changed' : ''),
                    $originalFilename, 1,
                    ['bbox_string' => $bboxStringForHash, 'agreeing_models' => $agreeingModelCountCls, 'action' => $fileActionDescription, 'old_ripeness_if_changed' => $oldRipenessClassForLog]
                );
            }

            // Hapus dari pending BBox *hanya* jika CSV sukses DAN file ada
            if ($csvOperationSuccess && $finalFileExistsInTrain) {
                if (isset($pendingImages[$imageKeyForCache])) {
                    unset($pendingImages[$imageKeyForCache]);
                    Cache::put('pending_bbox_annotations', $pendingImages, now()->addDays(30));
                    Log::info("[FeedbackClassification] Gambar {$originalFilename} dihapus dari pending BBox karena feedback klasifikasi berhasil.");
                }
            }

            return response()->json([
                'success'                   => $csvOperationSuccess && $finalFileExistsInTrain,
                'message'                   => $responseMessage . " " . $fileActionDescription,
                'saved_definitively'        => true, // Umpan balik klasifikasi juga dianggap final
                'pending_annotations_count' => count(Cache::get('pending_bbox_annotations', [])),
            ]);

        } catch (ValidationException $e) {return response()->json(['success' => false, 'message' => 'Input tidak valid: ' . $e->validator->errors()->first(), 'errors' => $e->errors()], 422);} catch (Throwable $e) {Log::error("Error processing classification feedback", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 1000)]);return response()->json(['success' => false, 'message' => 'Gagal memproses feedback klasifikasi: ' . Str::limit($e->getMessage(), 150)], 500);}
    }
}
