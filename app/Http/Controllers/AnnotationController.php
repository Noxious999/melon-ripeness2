<?php
namespace App\Http\Controllers;

use App\Services\AnnotationService;
use App\Services\DatasetChangeService;
use App\Services\DatasetService;
use App\Services\FeedbackService;
use App\Services\PredictionService;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request; // Ditambahkan jika belum ada
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;   // Ditambahkan jika belum ada
use Illuminate\Support\Facades\Validator; // Ditambahkan jika belum ada
use Illuminate\Support\Str;               // Ditambahkan jika belum ada
use RuntimeException;                     // Ditambahkan jika belum ada
use Throwable;

// Ditambahkan jika belum ada

class AnnotationController extends Controller
{
    protected AnnotationService $annotationService;
    protected PredictionService $predictionService;
    protected FeedbackService $feedbackService; // Inject FeedbackService
    protected DatasetChangeService $datasetChangeService;
    protected DatasetService $datasetService; // Tambahkan properti untuk DatasetService

    public function __construct(
        AnnotationService $annotationService,
        PredictionService $predictionService,
        FeedbackService $feedbackService,           // Tambahkan FeedbackService
        DatasetChangeService $datasetChangeService, // Inject
        DatasetService $datasetService              // Inject DatasetService

    ) {
        $this->annotationService    = $annotationService;
        $this->predictionService    = $predictionService;
        $this->feedbackService      = $feedbackService; // Inisialisasi FeedbackService
        $this->datasetChangeService = $datasetChangeService;
        $this->datasetService       = $datasetService; // Inisialisasi DatasetService
    }

    // Fungsi helper untuk memastikan thumbnail ada
    private function ensureThumbnailsExistForPage(array $imagesForPage): void
    {
        foreach ($imagesForPage as $imageDetails) {
            if (isset($imageDetails['s3Path'], $imageDetails['set'], $imageDetails['filename'])) {
                // Path tempat thumbnail seharusnya berada di S3
                $expectedThumbnailPath = DatasetService::S3_THUMBNAILS_BASE_DIR . '/' . $imageDetails['set'] . '/' . $imageDetails['filename'];
                $expectedThumbnailPath = preg_replace('#/+#', '/', $expectedThumbnailPath);

                if (! Storage::disk('s3')->exists($expectedThumbnailPath)) {
                    Log::info("Halaman Anotasi (Galeri): Thumbnail tidak ditemukan untuk {$imageDetails['filename']}, mencoba membuat on-the-fly.");
                    $this->datasetService->generateAndStoreThumbnail(
                        $imageDetails['s3Path'], // Path S3 ke gambar asli
                        $imageDetails['set'],
                        $imageDetails['filename']
                    );
                }
            }
        }
    }

    public function index(Request $request): ViewContract | JsonResponse
    {
        $allImageFilesData     = $this->annotationService->getAllImageFiles();
        $annotatedFilesS3Paths = $this->annotationService->getAnnotatedFilesList();
        $unannotatedFilesData  = array_diff_key($allImageFilesData, $annotatedFilesS3Paths);

        $pendingBboxImages = Cache::get('pending_bbox_annotations', []);
        foreach ($pendingBboxImages as $s3PathPending => $cacheData) {
            if (! isset($unannotatedFilesData[$s3PathPending]) && ! isset($annotatedFilesS3Paths[$s3PathPending])) {
                $unannotatedFilesData[$s3PathPending] = [
                    's3Path'          => $s3PathPending,
                    'filename'        => basename($s3PathPending),
                    'set'             => $cacheData['set'] ?? $this->extractSetFromS3Path($s3PathPending),
                    'is_pending_bbox' => true,
                ];
            }
        }
        ksort($unannotatedFilesData);

        if ($request->ajax()) {
            if ($request->has('getGalleryPage')) {
                try {
                    $galleryPage                   = (int) $request->query('getGalleryPage', 1);
                    $currentActiveS3PathForGallery = $request->query('current_image_s3_path', null);
                    if (! $currentActiveS3PathForGallery && ! empty($unannotatedFilesData)) {
                        $firstUnannotated              = reset($unannotatedFilesData);
                        $currentActiveS3PathForGallery = $firstUnannotated['s3Path'] ?? null;
                    }

                    // --- PEMBUATAN THUMBNAIL ON-THE-FLY UNTUK AJAX GALLERY ---
                    $itemsForRequestedGalleryPage = array_slice(
                        array_values($unannotatedFilesData), // array_values untuk reset keys agar slice bekerja
                        ($galleryPage - 1) * AnnotationService::THUMBNAILS_PER_PAGE,
                        AnnotationService::THUMBNAILS_PER_PAGE
                    );
                    $this->ensureThumbnailsExistForPage($itemsForRequestedGalleryPage);
                    // --- AKHIR PEMBUATAN THUMBNAIL ON-THE-FLY ---

                    $galleryData = $this->formatGalleryData(
                        $unannotatedFilesData,
                        $currentActiveS3PathForGallery,
                        $galleryPage
                    );
                    return response()->json([
                        'success'       => true,
                        'galleryImages' => $galleryData['galleryImages'],
                        'currentPage'   => $galleryData['currentPage'],
                        'totalPages'    => $galleryData['totalPages'],
                        'totalImages'   => count($unannotatedFilesData),
                    ]);
                } catch (Throwable $e) {
                    Log::error("[AJAX getGalleryPage] Exception", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
                    return response()->json(['success' => false, 'message' => 'Gagal memuat galeri: ' . Str::limit($e->getMessage(), 100)], 500);
                }
            } elseif ($request->has('getImageData')) {
                try {
                    $s3PathForAjax = $request->query('getImageData');
                    Log::info("[AJAX getImageData] Attempting to get image data for S3 path: " . $s3PathForAjax);

                    if (! empty($allImageFilesData)) {
                        Log::debug("[AJAX getImageData] Debugging key check:", [
                            's3PathForAjax_received'                 => $s3PathForAjax,
                            'key_exists_in_allImageFilesData'        => isset($allImageFilesData[$s3PathForAjax]),
                            'type_of_s3PathForAjax'                  => gettype($s3PathForAjax),
                            'type_of_first_key_in_allImageFilesData' => gettype(array_key_first($allImageFilesData)),
                            'allImageFilesData_keys_sample'          => array_slice(array_keys($allImageFilesData), 0, 20), // Tampilkan 20 kunci pertama
                        ]);
                    } else {
                        Log::debug("[AJAX getImageData] Debugging key check: allImageFilesData is empty.");
                    }

                    if (empty($allImageFilesData)) {
                        Log::error("[AJAX getImageData] \$allImageFilesData is empty.");
                        return response()->json(['success' => false, 'message' => 'Data daftar file gambar tidak tersedia di server (kosong).'], 500);
                    }

                    if (isset($allImageFilesData[$s3PathForAjax])) {
                        if (! is_array($allImageFilesData[$s3PathForAjax]) ||
                            ! isset($allImageFilesData[$s3PathForAjax]['s3Path'], $allImageFilesData[$s3PathForAjax]['filename'], $allImageFilesData[$s3PathForAjax]['set'])) {
                            Log::error("[AJAX getImageData] Struktur data tidak valid untuk path yang diminta.", ['path' => $s3PathForAjax, 'data' => $allImageFilesData[$s3PathForAjax] ?? null]);
                            return response()->json(['success' => false, 'message' => 'Struktur data gambar internal tidak valid untuk path: ' . basename($s3PathForAjax)], 500);
                        }
                        $imageDataForAjax = $this->formatImageDataForResponse($allImageFilesData[$s3PathForAjax]);
                        return response()->json(['success' => true, 'imageData' => $imageDataForAjax]);
                    }

                    Log::warning("[AJAX getImageData] Requested S3 path not found in allImageFilesData", [
                        'requested_path'        => $s3PathForAjax,
                        'available_keys_sample' => array_slice(array_keys($allImageFilesData), 0, 10),
                    ]);
                    return response()->json(['success' => false, 'message' => 'Data gambar (' . basename($s3PathForAjax) . ') tidak ditemukan di daftar file yang tersedia (via AJAX).'], 404);

                } catch (Throwable $e) {
                    Log::error("[AJAX getImageData] Exception while processing getImageData", [
                        'error'             => $e->getMessage(),
                        'trace'             => Str::limit($e->getTraceAsString(), 1000),
                        's3_path_requested' => $request->query('getImageData'),
                    ]);
                    return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server saat mengambil data gambar: ' . Str::limit($e->getMessage(), 150)], 500);
                }
            }
            return response()->json(['success' => false, 'message' => 'Aksi AJAX tidak valid.'], 400);
        }

        $requestedImageS3Path = null;
        if ($request->has('image')) {
            $base64DecodedPath = base64_decode($request->query('image'));
            if ($base64DecodedPath !== false) {
                $normalizedDecodedPath = ltrim(str_replace('\\', '/', $base64DecodedPath), '/');
                if (Str::startsWith($normalizedDecodedPath, DatasetService::S3_DATASET_BASE_DIR . '/')) {
                    $requestedImageS3Path = $normalizedDecodedPath;
                } else {
                    $requestedImageS3Path = DatasetService::S3_DATASET_BASE_DIR . '/' . $normalizedDecodedPath;
                }
                $requestedImageS3Path = preg_replace('#/+#', '/', $requestedImageS3Path);
            }
        }

        $currentImageToDisplay     = null;
        $currentImageS3PathForView = null;

        if ($requestedImageS3Path && isset($unannotatedFilesData[$requestedImageS3Path])) {
            $currentImageToDisplay = $unannotatedFilesData[$requestedImageS3Path];
        } elseif (! empty($unannotatedFilesData)) {
            $currentImageToDisplay = reset($unannotatedFilesData);
        }

        if ($currentImageToDisplay && isset($currentImageToDisplay['s3Path'])) {
            $currentImageS3PathForView = $currentImageToDisplay['s3Path'];
            if (! isset($currentImageToDisplay['is_pending_bbox']) && isset($pendingBboxImages[$currentImageS3PathForView])) {
                $currentImageToDisplay['is_pending_bbox'] = true;
            }
        }

        $totalImagesToAnnotate = count($unannotatedFilesData);
        $dataForView           = [
            'annotationComplete'     => false,
            'annotationError'        => null,
            'imagePathForCsv'        => null,
            'imageUrl'               => null,
            'filename'               => null,
            'datasetSet'             => null,
            'isPendingBbox'          => false,
            'galleryImages'          => [],
            'currentPage'            => 1,
            'totalPages'             => 0,
            'activeThumbS3Path'      => null,
            'pendingAnnotationCount' => count($pendingBboxImages),
            'totalImages'            => $totalImagesToAnnotate,
            'clearCacheUrl'          => route('app.clear_cache'),
        ];

        if ($currentImageToDisplay && $currentImageS3PathForView) {
            // --- PEMBUATAN THUMBNAIL ON-THE-FLY UNTUK INITIAL LOAD GALLERY ---
            $initialGalleryPage         = (int) ($request->query('page', 1));
            $itemsForInitialGalleryPage = array_slice(
                array_values($unannotatedFilesData),
                ($initialGalleryPage - 1) * AnnotationService::THUMBNAILS_PER_PAGE,
                AnnotationService::THUMBNAILS_PER_PAGE
            );
            $this->ensureThumbnailsExistForPage($itemsForInitialGalleryPage);
            // --- AKHIR PEMBUATAN THUMBNAIL ON-THE-FLY ---

            $imageData   = $this->formatImageDataForResponse($currentImageToDisplay);
            $galleryData = $this->formatGalleryData($unannotatedFilesData, $currentImageS3PathForView, $initialGalleryPage);
            $dataForView = array_merge($dataForView, $imageData, $galleryData);
        } else {
            $allAnnotatedIncludingPending = true;
            if (empty($allImageFilesData)) {
                $dataForView['annotationComplete'] = true;
                $dataForView['message']            = 'Dataset gambar kosong.';
            } else {
                foreach ($allImageFilesData as $s3Path => $details) {
                    if (! isset($annotatedFilesS3Paths[$s3Path]) && ! isset($pendingBboxImages[$s3Path])) {
                        $allAnnotatedIncludingPending = false;
                        break;
                    }
                }
                if ($allAnnotatedIncludingPending) {
                    $dataForView['annotationComplete'] = true;
                    $dataForView['message']            = 'Semua gambar telah dianotasi atau sedang menunggu anotasi BBox.';
                } else if (empty($unannotatedFilesData)) {
                    $dataForView['annotationComplete'] = true;
                    $dataForView['message']            = 'Semua gambar telah dianotasi.';
                } else {
                    $dataForView['annotationError'] = 'Tidak dapat menentukan gambar berikutnya untuk anotasi.';
                }
            }
        }
        return view('annotate', $dataForView);
    }

    // Helper untuk mengekstrak set dari path S3 jika tidak ada di cacheData
    private function extractSetFromS3Path(string $s3Path): ?string
    {
        $pathToCheck = $s3Path;
        if (Str::startsWith($s3Path, DatasetService::S3_DATASET_BASE_DIR . '/')) {
            $pathToCheck = Str::after($s3Path, DatasetService::S3_DATASET_BASE_DIR . '/');
        }
        $parts = explode('/', $pathToCheck);
        if (count($parts) >= 2 && in_array($parts[0], DatasetService::DATASET_SETS)) {
            return $parts[0];
        }
        return null;
    }

    private function formatImageDataForResponse(array $imageInfo): array
    {
        $s3PathComplete = $imageInfo['s3Path']; // Ini sudah path S3 lengkap, contoh: "dataset/train/file.jpg"
        $pathForCsv     = ($imageInfo['set'] ?? 'unknown_set') . '/' . ($imageInfo['filename'] ?? basename($s3PathComplete));

        // Untuk route 'storage.image', kita encode path S3 lengkap
        $pathForUrlEncoding = $s3PathComplete;

        return [
            'imagePathForCsv' => $pathForCsv,
            'imageUrl'        => route('storage.image', ['path' => base64_encode($pathForUrlEncoding)]),
            'filename'        => $imageInfo['filename'],
            'datasetSet'      => $imageInfo['set'],
            'isPendingBbox'   => $imageInfo['is_pending_bbox'] ?? false,
            's3Path'          => $s3PathComplete,
        ];
    }

    private function formatGalleryData(array $allFilesToDisplay, ?string $currentImageS3PathForActive, int $currentPage): array
    {
        $galleryImages = [];
        foreach ($allFilesToDisplay as $s3PathKey => $fileDetails) {
            if (! is_array($fileDetails) || ! isset($fileDetails['s3Path']) || ! isset($fileDetails['filename']) || ! isset($fileDetails['set'])) {
                continue;
            }

            // Path S3 lengkap ke thumbnail: 'thumbnails/set/namafile.jpg'
            $thumbnailS3PathForEncoding = DatasetService::S3_THUMBNAILS_BASE_DIR . '/' . $fileDetails['set'] . '/' . $fileDetails['filename'];
            $thumbnailS3PathForEncoding = preg_replace('#/+#', '/', $thumbnailS3PathForEncoding);

            // Path S3 lengkap ke gambar utama (untuk data-image-path)
            $mainImageS3PathForDataAttr = $fileDetails['s3Path'];

            $galleryImages[] = [
                's3Path'                     => $fileDetails['s3Path'], // Path S3 asli gambar utama
                'thumbnailUrl'               => route('storage.image', ['path' => base64_encode($thumbnailS3PathForEncoding)]),
                'filename'                   => $fileDetails['filename'],
                'set'                        => $fileDetails['set'],
                'is_pending_bbox'            => $fileDetails['is_pending_bbox'] ?? false,
                'isActive'                   => ($fileDetails['s3Path'] === $currentImageS3PathForActive),
                'mainImageS3PathForDataAttr' => $mainImageS3PathForDataAttr,
            ];
        }

        $totalItems = count($galleryImages);
        $totalPages = $totalItems > 0 ? (int) ceil($totalItems / AnnotationService::THUMBNAILS_PER_PAGE) : 0;
        if ($totalPages == 0 && $totalItems > 0) {
            $totalPages = 1;
        }

        $currentPage     = max(1, min($currentPage, $totalPages ?: 1));
        $offset          = ($currentPage - 1) * AnnotationService::THUMBNAILS_PER_PAGE;
        $paginatedImages = array_slice($galleryImages, $offset, AnnotationService::THUMBNAILS_PER_PAGE);

        return [
            'galleryImages'     => $paginatedImages, // Pastikan variabel $paginatedImages terdefinisi dari logika paginasi Anda
            'currentPage'       => $currentPage,
            'totalPages'        => $totalPages, // Pastikan variabel $totalPages terdefinisi
            'activeThumbS3Path' => $currentImageS3PathForActive,
        ];
    }

    public function save(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image_path'       => 'required|string',
            'dataset_set'      => 'required|string|in:' . implode(',', DatasetService::DATASET_SETS),
            'detection_choice' => 'required|string|in:melon,non_melon',
            'annotations_json' => 'nullable|json',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();

        $imagePathForCsv         = $validated['image_path'];
        $s3ImagePathFull         = DatasetService::S3_DATASET_BASE_DIR . '/' . $imagePathForCsv;
        $s3ImagePathFull         = preg_replace('#/+#', '/', $s3ImagePathFull);
        $s3AnnotationCsvPath     = AnnotationService::S3_ANNOTATION_DIR . '/' . $validated['dataset_set'] . '_annotations.csv';
        $detectionClass          = $validated['detection_choice'];
        $allAnnotationRowsForCsv = [];

        if ($detectionClass === 'melon') {
            if (empty($validated['annotations_json']) || $validated['annotations_json'] === '[]') {
                return response()->json(['success' => false, 'message' => 'Jika gambar adalah melon, setidaknya satu Bounding Box dengan informasi kematangan diperlukan.'], 422);
            }
            $annotationsFromJson = json_decode($validated['annotations_json'], true);
            if (empty($annotationsFromJson) || ! is_array($annotationsFromJson)) {
                return response()->json(['success' => false, 'message' => 'Data Bounding Box (JSON) tidak valid atau kosong setelah didecode.'], 422);
            }
            foreach ($annotationsFromJson as $index => $bboxData) {
                if (! isset($bboxData['cx'], $bboxData['cy'], $bboxData['w'], $bboxData['h'], $bboxData['ripeness'])) {
                    return response()->json(['success' => false, 'message' => "Data BBox #" . ($index + 1) . " tidak lengkap."], 422);
                }
                if (! in_array($bboxData['ripeness'], ['ripe', 'unripe'])) {
                    return response()->json(['success' => false, 'message' => "Kematangan BBox #" . ($index + 1) . " tidak valid."], 422);
                }
                $allAnnotationRowsForCsv[] = [
                    'filename'        => $imagePathForCsv, 'set'   => $validated['dataset_set'],
                    'detection_class' => 'melon', 'ripeness_class' => $bboxData['ripeness'],
                    'bbox_cx'         => (string) round((float) $bboxData['cx'], 6),
                    'bbox_cy'         => (string) round((float) $bboxData['cy'], 6),
                    'bbox_w'          => (string) round((float) $bboxData['w'], 6),
                    'bbox_h'          => (string) round((float) $bboxData['h'], 6),
                ];
            }
        } else {
            $allAnnotationRowsForCsv[] = [
                'filename'        => $imagePathForCsv, 'set'       => $validated['dataset_set'],
                'detection_class' => 'non_melon', 'ripeness_class' => '',
                'bbox_cx'         => '', 'bbox_cy'                 => '', 'bbox_w' => '', 'bbox_h' => '',
            ];
        }

        try {
            $updateSuccess = $this->feedbackService->updateAnnotationsForImage(
                $s3AnnotationCsvPath,
                $imagePathForCsv, // ini 'set/namafile.jpg'
                $allAnnotationRowsForCsv
            );

            if ($updateSuccess) {
                $this->datasetChangeService->recordChange(
                    'manual_annotation_saved',
                    $imagePathForCsv,
                    count($allAnnotationRowsForCsv),
                    ['detection_class_chosen' => $detectionClass]
                );

                // Menghapus dari cache pending_bbox_annotations (ini sudah benar)
                $pendingBboxCache = Cache::get('pending_bbox_annotations', []);
                if (isset($pendingBboxCache[$s3ImagePathFull])) {
                    unset($pendingBboxCache[$s3ImagePathFull]);
                    Cache::put('pending_bbox_annotations', $pendingBboxCache, now()->addHours(24));
                }

                // !!! TAMBAHKAN BARIS INI UNTUK MEMBERSIHKAN CACHE DAFTAR FILE ANOTASI !!!
                Cache::forget('annotation_service_annotated_files_list_v4');
                Log::info("[AnnotationController::save] Cache 'annotation_service_annotated_files_list_v4' dibersihkan setelah menyimpan anotasi untuk {$imagePathForCsv}.");
                // !!! AKHIR PENAMBAHAN !!!

                // Pembuatan thumbnail (ini sudah benar dari implementasi sebelumnya)
                if ($detectionClass === 'melon' && ! empty($allAnnotationRowsForCsv)) {
                    $filenameForThumbnail = basename($imagePathForCsv);
                    Log::info("Memulai pembuatan thumbnail untuk: {$filenameForThumbnail} dari set {$validated['dataset_set']} setelah anotasi disimpan.");
                    app(DatasetService::class)->generateAndStoreThumbnail(
                        $s3ImagePathFull,
                        $validated['dataset_set'],
                        $filenameForThumbnail
                    );
                }

                $nextImageResponse = $this->getNextImageToAnnotate($s3ImagePathFull, $validated['dataset_set']);

                // Log respons dari getNextImageToAnnotate (ini sudah ada dan bagus untuk debug)
                Log::debug("[AnnotationController::save] Response from getNextImageToAnnotate: ", $nextImageResponse);

                return response()->json(array_merge(
                    ['success' => true, 'message' => 'Anotasi berhasil disimpan!'],
                    $nextImageResponse
                ));

            } else {
                Log::error("[AnnotationController::save] Gagal menyimpan anotasi melalui FeedbackService.", [
                    's3_csv_path'      => $s3AnnotationCsvPath,
                    'image_identifier' => $imagePathForCsv,
                ]);
                return response()->json(['success' => false, 'message' => 'Gagal menyimpan anotasi ke file CSV di S3.'], 500);
            }

        } catch (Throwable $e) {
            Log::error("[AnnotationController::save] Exception saat proses penyimpanan anotasi", [
                'error_message'            => $e->getMessage(),
                'error_file'               => $e->getFile(),
                'error_line'               => $e->getLine(),
                's3_image_path_identifier' => $imagePathForCsv,
                'trace'                    => Str::limit($e->getTraceAsString(), 1000),
            ]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server saat menyimpan anotasi. Silakan cek log.'], 500);
        }
    }

    private function getNextImageToAnnotate(string $currentImageS3PathDone, string $currentSet): array
    {
        Log::info("[getNextImageToAnnotate] Attempting to find next image. Set: {$currentSet}, Just Annotated: {$currentImageS3PathDone}");

        // 1. Dapatkan daftar SEMUA file yang belum dianotasi (ini akan membaca ulang dari S3)
        $allUnannotatedFilesData = $this->annotationService->getAllUnannotatedFiles();
        Log::debug("[getNextImageToAnnotate] Raw unannotated count: " . count($allUnannotatedFilesData));

        // 2. SECARA EKSPLISIT HAPUS gambar yang baru saja selesai dari daftar KANDIDAT SAAT INI.
        //    Ini penting karena $allUnannotatedFilesData di-cache per request atau pembacaan S3
        //    mungkin belum 100% sinkron dengan 'put' yang baru saja dilakukan.
        if (isset($allUnannotatedFilesData[$currentImageS3PathDone])) {
            Log::debug("[getNextImageToAnnotate] Explicitly removing '{$currentImageS3PathDone}' from current consideration.");
            unset($allUnannotatedFilesData[$currentImageS3PathDone]);
        }
        Log::debug("[getNextImageToAnnotate] Refined unannotated count: " . count($allUnannotatedFilesData));

        $nextImageToDisplay = null;

        // 3. Coba cari gambar berikutnya di SET YANG SAMA (dari daftar yang sudah disaring)
        if (! empty($allUnannotatedFilesData)) {
            foreach ($allUnannotatedFilesData as $s3Path => $details) {
                // Pastikan 'set' ada di array $details
                if (isset($details['set']) && $details['set'] === $currentSet) {
                    $nextImageToDisplay = $details;
                    Log::debug("[getNextImageToAnnotate] Found next image in SAME set '{$currentSet}': " . ($details['s3Path'] ?? 'N/A'));
                    break;
                }
            }
        }

        // 4. Jika tidak ada di set yang sama, ambil gambar PERTAMA dari SISA daftar (set lain)
        if (! $nextImageToDisplay && ! empty($allUnannotatedFilesData)) {
            // reset() akan mengambil elemen pertama dari array yang tersisa (yang sudah tanpa currentImageS3PathDone)
            $nextImageToDisplay = reset($allUnannotatedFilesData);
            if ($nextImageToDisplay && isset($nextImageToDisplay['s3Path'])) {
                Log::debug("[getNextImageToAnnotate] No more in current set. Picking first from REMAINING unannotated: {$nextImageToDisplay['s3Path']}");
            } else if ($nextImageToDisplay) {
                Log::warning("[getNextImageToAnnotate] Picked first from remaining, but s3Path key is missing.", $nextImageToDisplay);
                $nextImageToDisplay = null; // Anggap tidak valid jika struktur aneh
            }
        }

        // 5. Kembalikan hasil
        if ($nextImageToDisplay && isset($nextImageToDisplay['s3Path'])) {
            Log::info("[getNextImageToAnnotate] SUCCESSFULLY determined next image: {$nextImageToDisplay['s3Path']}");
            $imageData = $this->formatImageDataForResponse($nextImageToDisplay);
            return [
                'next_image_data'           => $imageData,
                'annotation_complete'       => false,
                'pending_annotations_count' => count(Cache::get('pending_bbox_annotations', [])),
            ];
        }

        Log::info("[getNextImageToAnnotate] No more images to annotate in any set after filtering.");
        return [
            'annotation_complete'       => true,
            'message'                   => 'Semua gambar dalam antrian telah dianotasi!',
            'pending_annotations_count' => count(Cache::get('pending_bbox_annotations', [])),
        ];
    }

    public function estimateBboxAjax(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'image_path' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Path gambar tidak valid atau tidak disertakan.', 'errors' => $validator->errors()], 422);
        }
        $validated   = $validator->validated();
        $s3ImagePath = $validated['image_path'];

        Log::info("[AnnotationController] Memulai estimasi BBox AJAX untuk S3 path: {$s3ImagePath}");

        if (! Storage::disk('s3')->exists($s3ImagePath)) {
            Log::error("[AnnotationController] File gambar tidak ditemukan di S3 untuk estimasi BBox.", ['s3_path' => $s3ImagePath]);
            return response()->json(['success' => false, 'message' => 'File gambar tidak ditemukan di server untuk estimasi.'], 404);
        }

        $estimationResult = $this->predictionService->runPythonBboxEstimator($s3ImagePath);

        if ($estimationResult && ($estimationResult['success'] ?? false) && isset($estimationResult['bboxes'])) {
            return response()->json([
                'success' => true,
                'bboxes'  => $estimationResult['bboxes'],
            ]);
        }

        $errorMessage = $estimationResult['message'] ?? 'Gagal mengestimasi Bounding Box dari server.';
        Log::warning("[AnnotationController] Estimasi BBox AJAX gagal atau tidak menemukan BBox.", ['s3_path' => $s3ImagePath, 'result' => $estimationResult]);
        return response()->json(['success' => false, 'message' => $errorMessage]);
    }

    /**
     * Menyajikan gambar dari S3 (redirect ke URL publik).
     *
     * @param string $base64Path base64 dari 'set/filename.jpg'
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse|JsonResponse
     */
    public function serveStorageImage(string $base64Path): RedirectResponse
    {
        try {
            // $s3FullDecodedPath sekarang adalah path lengkap dari root bucket,
            // contoh: 'dataset/train/gambar.jpg' atau 'thumbnails/train/gambar.jpg'
            $s3FullDecodedPath = base64_decode($base64Path);
            if ($s3FullDecodedPath === false) {
                abort(400, 'Invalid image path encoding.');
            }

            // Sanitasi path
            $s3FullDecodedPath = Str::replace('..', '', $s3FullDecodedPath);
            $s3FullDecodedPath = ltrim(str_replace('\\', '/', $s3FullDecodedPath), '/');
            $s3FullDecodedPath = preg_replace('#/+#', '/', $s3FullDecodedPath); // Pastikan hanya satu slash

            /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
            $disk = Storage::disk('s3');

            if (! $disk->exists($s3FullDecodedPath)) {
                Log::warning('AnnotationController:serveStorageImage: Gambar S3 tidak ditemukan.', ['s3_path' => $s3FullDecodedPath]);
                abort(404, 'Image not found on S3.'); // Ini akan memicu error 500 jika tidak ditangkap dengan baik oleh browser/JS
            }

            $temporaryUrl = $disk->temporaryUrl(
                $s3FullDecodedPath,
                now()->addMinutes(15) // Durasi URL temporer
            );
            Log::info("[ServeImage] Redirecting to S3 Temporary URL", ['s3_temp_url' => Str::limit($temporaryUrl, 100), 'original_path' => $s3FullDecodedPath]);
            return redirect($temporaryUrl);

        } catch (Throwable $e) {
            // Jika abort(404) dilempar, mungkin akan masuk ke sini jika tidak ada error handler khusus untuk 404 yang mengembalikan JSON.
            Log::error("[ServeImage] Exception", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            // Mengembalikan respons error yang lebih generik jika terjadi masalah tak terduga
            abort(500, 'Error serving image: ' . $e->getMessage());
        }
    }
}
