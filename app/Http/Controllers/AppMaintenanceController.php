<?php
namespace App\Http\Controllers;

use App\Services\DatasetService;
use App\Services\PredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AppMaintenanceController extends Controller
{
    public function clearCache(Request $request): JsonResponse
    {
        try {
            // 1. Bersihkan cache Laravel standar
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Artisan::call('route:clear');
            Log::info('Laravel standard caches cleared by user action.');

                                                                             // 2. Hapus file di storage/app/uploads_temp/
            $tempUploadDirS3        = PredictionService::S3_UPLOAD_DIR_TEMP; // Pastikan ini path S3
            $diskS3                 = Storage::disk('s3');                   // Gunakan disk S3
            $filesTempUpload        = $diskS3->files($tempUploadDirS3);
            $deletedTempUploadCount = $deletedTempUploadCount ?? 0; // Inisialisasi jika belum dari kode sebelumnya

            foreach ($filesTempUpload as $file) {
                if ($diskS3->delete($file)) {
                    $deletedTempUploadCount++;
                }
            }
            Log::info("Temporary upload files S3 deleted by user: {$deletedTempUploadCount} files from '{$tempUploadDirS3}'.");

            // 4. Hapus direktori thumbnail dari S3 (BARU)
            $deletedThumbnailCount = 0;
            $thumbnailBaseDirS3    = DatasetService::S3_THUMBNAILS_BASE_DIR; // 'thumbnails'

            foreach (DatasetService::DATASET_SETS as $set) {
                $thumbnailSetDirS3 = $thumbnailBaseDirS3 . '/' . $set;
                $thumbnailSetDirS3 = preg_replace('#/+#', '/', $thumbnailSetDirS3);

                if ($diskS3->exists($thumbnailSetDirS3)) {
                    $filesInThumbDir = $diskS3->files($thumbnailSetDirS3);
                    $deletedInSet    = 0;
                    foreach ($filesInThumbDir as $thumbFile) {
                        if ($diskS3->delete($thumbFile)) {
                            $deletedInSet++;
                        }
                    }
                    Log::info("Deleted {$deletedInSet} thumbnails from S3 directory: {$thumbnailSetDirS3}");
                    $deletedThumbnailCount += $deletedInSet;
                    // Opsional: Hapus direktori set jika kosong
                    // if (empty($diskS3->allFiles($thumbnailSetDirS3))) {
                    //     $diskS3->deleteDirectory($thumbnailSetDirS3);
                    //     Log::info("Deleted empty S3 thumbnail set directory: {$thumbnailSetDirS3}");
                    // }
                } else {
                    Log::info("Thumbnail directory not found, skipping delete: {$thumbnailSetDirS3}");
                }
            }
            Log::info("Total generated thumbnail files S3 deleted by user: {$deletedThumbnailCount} files from thumbnail directories.");

            // 3. Hapus cache model dari ModelService (jika Anda membuat fungsi publik untuk ini)
            // Jika tidak, Anda bisa memanggil Cache::forget() secara manual di sini untuk key-key spesifik
            // atau membuat metode di ModelService/EvaluationService untuk clear cache mereka.
            // Contoh:
            // app(ModelService::class)->clearAllModelRelatedCaches();
            // app(EvaluationService::class)->clearEvaluationDataCache();
            Artisan::call('cache:forget', ['key' => 'ml_model_all_detector_metrics']);
            Artisan::call('cache:forget', ['key' => 'ml_model_all_classifier_metrics']);
            // Tambahkan key cache lain yang perlu dihapus

            $message = "Cache aplikasi, {$deletedTempUploadCount} file sementara";
            if ($deletedThumbnailCount > 0) {
                $message .= ", dan {$deletedThumbnailCount} file thumbnail";
            }
            $message .= " berhasil dibersihkan.";

            return response()->json(['success' => true, 'message' => $message]);

        } catch (\Throwable $e) {
            Log::error("Error clearing application cache by user: " . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal membersihkan cache: ' . $e->getMessage()], 500);
        }
    }
}
