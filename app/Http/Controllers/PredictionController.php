<?php
namespace App\Http\Controllers;

use App\Services\FeatureExtractionService;
use App\Services\FeedbackService;
use App\Services\PredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http; // Untuk HTTP Client
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PredictionController extends Controller
{
    protected PredictionService $predictionService;
    protected FeatureExtractionService $featureExtractor;

    // Alamat IP dan port server Flask di Raspberry Pi Anda
    // Sebaiknya ini disimpan di file .env
    protected string $raspberryPiUrl;
    protected FeedbackService $feedbackService;

    public function __construct(
        PredictionService $predictionService,
        FeatureExtractionService $featureExtractor,
        FeedbackService $feedbackService
    ) {
        $this->predictionService = $predictionService;
        $this->featureExtractor  = $featureExtractor;
        $this->feedbackService   = $feedbackService;
        $this->raspberryPiUrl    = env('RASPBERRY_PI_URL', 'http://ALAMAT_IP_RASPBERRY_PI:5001'); // Ganti default jika perlu
    }

    /**
     * Menerima gambar dan data BBox dari Raspberry Pi, lalu memproses prediksi.
     */
    public function handleSubmissionFromPi(Request $request): JsonResponse
    {
        Log::info('--- PREDICTION: handleSubmissionFromPi START (BBox on Server) ---');
        Log::debug('Request Headers from Pi:', $request->headers->all());
        Log::debug('Request Files from Pi:', $request->files->all()); // Hanya file sekarang

        // --- PERUBAHAN VALIDATOR ---
        $validator = Validator::make($request->all(), [
            'image_file' => 'required|file|mimes:jpeg,png,jpg,webp|max:5120', // Max 5MB, hanya image_file
        ]);

        if ($validator->fails()) {
            Log::error('Validasi gagal untuk data dari Pi (hanya gambar).', ['errors' => $validator->errors()]);
            return response()->json(['success' => false, 'message' => 'Data gambar dari Raspberry Pi tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $imageFileFromPi = $request->file('image_file');
        // --- HAPUS BAGIAN bbox_data DARI REQUEST ---
        // $bboxJsonStringFromPi = $request->input('bbox_data'); // DIHAPUS

        $originalFilename   = $imageFileFromPi->getClientOriginalName() ?: 'pi_capture.jpg';
        $extension          = $imageFileFromPi->getClientOriginalExtension() ?: $imageFileFromPi->guessExtension() ?: 'jpg';
        $tempServerFilename = Str::uuid()->toString() . '.' . $extension;
        $s3TempPath         = null;

        try {
            $s3TempPath = Storage::disk('s3')->putFileAs(
                PredictionService::S3_UPLOAD_DIR_TEMP,
                $imageFileFromPi,
                $tempServerFilename
            );

            if (! $s3TempPath) {
                throw new RuntimeException('Gagal menyimpan gambar dari Pi ke S3.');
            }
            Log::info('Gambar dari Pi berhasil disimpan sementara ke S3.', ['s3_path' => $s3TempPath, 'original_filename' => $originalFilename]);

        } catch (Throwable $e) {
            Log::error('Error menyimpan gambar dari Pi ke S3.', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Gagal memproses gambar dari Pi di server: ' . $e->getMessage()], 500);
        }

        // --- PERUBAHAN: ESTIMASI BBOX DILAKUKAN DI SERVER LARAVEL ---
        $estimatedBboxRel            = null;
        $pythonBboxEstimationSuccess = false;
        Log::info('Memulai estimasi BBox di server Laravel untuk gambar dari Pi...', ['s3_temp_path' => $s3TempPath]);

        $bboxEstimationResult = $this->predictionService->runPythonBboxEstimator($s3TempPath);

        if ($bboxEstimationResult && ($bboxEstimationResult['success'] ?? false) && ! empty($bboxEstimationResult['bboxes'])) {
            // runPythonBboxEstimator sudah mengembalikan BBox relatif [{cx, cy, w, h}]
            $estimatedBboxRel            = $bboxEstimationResult['bboxes'][0];
            $pythonBboxEstimationSuccess = true;
            Log::info('BBox berhasil diestimasi oleh server Laravel dari gambar Pi.', ['bbox_rel' => $estimatedBboxRel]);
        } else {
            $errorMessage = $bboxEstimationResult['message'] ?? 'Estimasi BBox otomatis oleh server Laravel gagal atau tidak menemukan BBox.';
            Log::warning($errorMessage, ['filename' => $originalFilename, 's3_temp_path' => $s3TempPath, 'bbox_result' => $bboxEstimationResult]);
            // Biarkan $pythonBboxEstimationSuccess tetap false, alur selanjutnya akan menanganinya
        }
        // --- AKHIR PERUBAHAN ESTIMASI BBOX ---

        $results = $this->initializeResultArray([
            's3_path'           => $s3TempPath,
            'original_filename' => $originalFilename,
        ]);

        try {
            $detectionFeatures = $this->featureExtractor->extractDetectionFeatures($s3TempPath);
            if ($detectionFeatures === null) {
                throw new RuntimeException("Gagal mengekstrak fitur deteksi untuk gambar dari Pi: {$originalFilename}.");
            }
            $results['context']['detectionFeatures']            = $detectionFeatures;
            $results['context_features_extracted']['detection'] = true;

            $detectionResult              = $this->predictionService->performDynamicDetection($detectionFeatures);
            $results['default_detection'] = $detectionResult;

            if ($detectionResult && ($detectionResult['success'] ?? false) && ($detectionResult['detected'] ?? false)) {
                Log::info('Melon terdeteksi oleh model dinamis (gambar dari Pi).', ['filename' => $originalFilename, 'model' => $detectionResult['model_key'] ?? 'N/A']);

                // Gunakan $estimatedBboxRel dan $pythonBboxEstimationSuccess dari hasil server-side BBox estimation
                if ($pythonBboxEstimationSuccess && $estimatedBboxRel) {
                    $results['bbox']                        = $estimatedBboxRel;
                    $results['bbox_estimated_successfully'] = true;
                    $results['context']['bboxEstimated']    = true;
                    // $results['context']['bbox_from_python_rel'] tidak lagi relevan karena tidak dari Pi
                    Log::info('Menggunakan BBox hasil estimasi server Laravel.', ['bbox_rel' => $estimatedBboxRel]);

                    $colorFeatures = $this->featureExtractor->extractColorFeaturesFromBbox($s3TempPath, $estimatedBboxRel);
                    if ($colorFeatures) {
                        $results['context']['colorFeatures']                     = $colorFeatures;
                        $results['context_features_extracted']['classification'] = true;

                        $classificationResult              = $this->predictionService->performDynamicClassification($colorFeatures);
                        $results['default_classification'] = $classificationResult;
                        $results['classification_done']    = $classificationResult['success'] ?? false;
                        if (! ($classificationResult['success'] ?? false)) {
                            $results['classification_error'] = $classificationResult['error'] ?? 'Klasifikasi gagal.';
                        }
                        Log::info('Klasifikasi selesai (gambar dari Pi, BBox dari server).', ['result' => $classificationResult['prediction'] ?? 'N/A']);
                    } else {
                        $results['classification_done']  = false;
                        $results['classification_error'] = 'Gagal ekstrak fitur warna dari BBox (estimasi server) untuk klasifikasi.';
                        Log::warning($results['classification_error'], ['filename' => $originalFilename]);
                    }
                } else {
                    $results['bbox_estimated_successfully'] = false;
                    $results['classification_done']         = false;
                    $results['classification_error']        = $bboxEstimationResult['message'] ?? 'Estimasi Bounding Box oleh server Laravel gagal atau tidak menemukan BBox.';
                    $results['message']                     = $results['classification_error'];
                    Log::warning($results['message'], ['filename' => $originalFilename]);
                }
            } elseif ($detectionResult['success']) {
                $results['message']              = 'Tidak ada melon yang terdeteksi pada gambar (dari Pi).';
                $results['classification_done']  = false;
                $results['classification_error'] = $results['message'];
                Log::info($results['message'], ['filename' => $originalFilename]);
            } else { // Deteksi awal gagal
                $results['message']              = 'Proses deteksi awal gagal (gambar dari Pi): ' . ($detectionResult['error'] ?? 'Unknown error');
                $results['classification_done']  = false;
                $results['classification_error'] = $results['message'];
                Log::error($results['message'], ['filename' => $originalFilename]);
            }

            $results['success'] = true; // Jika sampai sini, proses utama dianggap sukses

            // Ambil base64 dari gambar original di S3 untuk ditampilkan di frontend
            if (Storage::disk('s3')->exists($s3TempPath)) {
                $fileContent = Storage::disk('s3')->get($s3TempPath);
                if ($fileContent) {
                    /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
                    $disk                         = Storage::disk('s3');
                    $mimeType                     = $disk->mimeType($s3TempPath) ?: ('image/' . pathinfo($s3TempPath, PATHINFO_EXTENSION));
                    $results['image_base64_data'] = 'data:' . $mimeType . ';base64,' . base64_encode($fileContent);
                }
            }

        } catch (Throwable $e) {
            Log::error('Error pada pipeline prediksi (gambar dari Pi).', ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            $results['success']                = false;
            $results['message']                = 'Terjadi kesalahan sistem saat prediksi (Pi): ' . Str::limit($e->getMessage(), 150);
            $results['default_detection']      = $results['default_detection'] ?? ['success' => false, 'error' => $results['message']];
            $results['default_classification'] = $results['default_classification'] ?? ['success' => false, 'error' => $results['message']];
        }
        // File S3 di uploads_temp TIDAK dihapus di sini agar bisa dianalisis/feedback
        Log::info('Prediksi dari Pi selesai (BBox di server). File S3 sementara TIDAK dihapus.', ['s3_path' => $s3TempPath]);

        return response()->json($results);
    }

    /**
     * Mengirim trigger ke Raspberry Pi untuk mengambil gambar.
     */
    public function triggerPiCamera(Request $request): JsonResponse
    {
        Log::info('Mencoba mentrigger kamera Raspberry Pi dari Laravel...');
        if (empty($this->raspberryPiUrl) || $this->raspberryPiUrl === 'http://ALAMAT_IP_RASPBERRY_PI:5001') {
            Log::warning('URL Raspberry Pi belum dikonfigurasi di .env (RASPBERRY_PI_URL).');
            return response()->json(['success' => false, 'message' => 'URL Raspberry Pi belum dikonfigurasi di server.'], 500);
        }

        $piEndpoint = rtrim($this->raspberryPiUrl, '/') . '/trigger-capture';

        try {
                                          // Menggunakan HTTP Client Laravel
            $response = Http::timeout(90) // Timeout 90 detik untuk seluruh proses di Pi (capture, bbox, send to laravel)
                ->post($piEndpoint);

            if ($response->successful()) {
                $piData = $response->json();
                Log::info('Raspberry Pi merespons trigger.', ['pi_response' => $piData]);
                // Jika Pi mengirimkan data prediksi Laravel sebagai responsnya, kita bisa teruskan itu
                if (isset($piData['success']) && $piData['success'] && isset($piData['laravel_response'])) {
                    return response()->json($piData['laravel_response']); // Teruskan respons dari /api/submit-from-pi
                }
                return response()->json(['success' => true, 'message' => 'Trigger ke Raspberry Pi berhasil, menunggu data gambar.', 'pi_raw_response' => $piData]);
            } else {
                Log::error('Gagal mentrigger Raspberry Pi atau Pi merespons dengan error.', [
                    'status'        => $response->status(),
                    'body'          => $response->body(),
                    'pi_url_target' => $piEndpoint,
                ]);
                return response()->json(['success' => false, 'message' => 'Gagal menghubungi Raspberry Pi atau Raspberry Pi error: ' . $response->status()], $response->status() >= 500 ? 502 : $response->status());
            }
        } catch (Throwable $e) {
            Log::error('Error koneksi saat mentrigger Raspberry Pi.', ['error' => $e->getMessage(), 'pi_url_target' => $piEndpoint]);
            return response()->json(['success' => false, 'message' => 'Error koneksi ke Raspberry Pi: ' . $e->getMessage()], 504); // Gateway Timeout
        }
    }

    private function initializeResultArray(array $contextData): array
    {
        return [
            'success'                     => false,
            'filename'                    => $contextData['original_filename'] ?? basename($contextData['s3_path'] ?? 'unknown.jpg'),
            's3_path_processed'           => $contextData['s3_path'] ?? null, // Path S3 dari file yang diproses
            'image_base64_data'           => null,                            // Untuk menampilkan gambar original di frontend
            'message'                     => '',
            'default_detection'           => null,  // Diubah dari detection_result
            'bbox'                        => null,  // Diubah dari bbox_results_rel (hanya satu bbox utama)
            'bbox_estimated_successfully' => false, // Diubah dari context.bbox_estimated_successfully
            'default_classification'      => null,  // Diubah dari classification_result
            'classification_done'         => false, // Diubah dari triggered_classification_result
            'classification_error'        => null,  // Pesan error spesifik klasifikasi
            'context_features_extracted'  => [      // Untuk melacak apakah fitur sudah ada
                'detection'      => false,
                'classification' => false,
            ],
                                               // 'detectors'                    => [], // Akan diisi oleh getAllOtherModelResults jika dipanggil
                                               // 'classifiers'                  => [], // Akan diisi oleh getAllOtherModelResults jika dipanggil
                                               // 'majority_vote'                => null, // Akan diisi oleh getAllOtherModelResults jika dipanggil
            'context'                     => [ // Data konteks untuk dikirim kembali ke frontend jika perlu
                'uploaded_s3_path'     => $contextData['s3_path'] ?? null,
                'detectionFeatures'    => null,  // Akan diisi
                'colorFeatures'        => null,  // Akan diisi jika klasifikasi berjalan
                'bboxEstimated'        => false, // Akan diupdate
                'bbox_from_python_rel' => null,  // Akan diupdate (mungkin tidak perlu dikirim balik jika sudah ada di root $results['bbox'])
                                                 // tempServerFilename mungkin tidak relevan lagi di sini karena kita sudah punya s3_path
            ],
        ];
    }

    // Method handleImageUpload Anda (pastikan S3_UPLOAD_DIR_TEMP sudah 'uploads_temp')
    public function handleImageUpload(Request $request): JsonResponse
    {
        Log::info('--- PREDICTION: handleImageUpload START ---');
        Log::debug('Request Headers:', $request->headers->all());
        Log::debug('Request Data (all):', $request->all());

        if (! $request->hasFile('imageFile')) {
            Log::error('PREDICTION: No imageFile found in the request to handleImageUpload.');
            return response()->json(['success' => false, 'message' => 'File gambar tidak ditemukan dalam permintaan.'], 400);
        }

        try {
            $validatedData = $request->validate([
                'imageFile' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120', // Max 5MB
            ]);

            $imageFile        = $validatedData['imageFile'];
            $originalFilename = $imageFile->getClientOriginalName();
            $imageContent     = file_get_contents($imageFile->getRealPath());
            $fileHash         = md5($imageContent);
            // Buat nama file unik untuk S3, ekstensi diambil dari file asli
            $extension          = $imageFile->getClientOriginalExtension() ?: $imageFile->guessExtension();
            $tempServerFilename = Str::uuid()->toString() . '.' . $extension;
            $feedbackExists     = $this->feedbackService->checkIfFeedbackExists($fileHash); // Panggil service

            // Gunakan konstanta yang sudah diperbaiki
            $s3TempPath = Storage::disk('s3')->putFileAs(
                PredictionService::S3_UPLOAD_DIR_TEMP, // Ini harusnya 'uploads_temp'
                $imageFile,
                $tempServerFilename
                // Opsi 'public' dihapus untuk mengatasi error AccessControlListNotSupported
            );

            if (! $s3TempPath) {
                Log::error('Gagal mengunggah file sementara ke S3.', ['original_filename' => $originalFilename, 'target_dir' => PredictionService::S3_UPLOAD_DIR_TEMP]);
                throw new RuntimeException('Gagal mengunggah file sementara ke S3.');
            }

            Log::info('File berhasil diunggah sementara ke S3.', ['s3_path' => $s3TempPath, 'original_filename' => $originalFilename]);

            return response()->json([
                'success'            => true,
                'message'            => 'Gambar berhasil diunggah ke S3.',
                'filename'           => $originalFilename,
                'tempServerFilename' => $tempServerFilename,
                's3_path'            => $s3TempPath,
                'feedback_exists'    => $feedbackExists, // <-- Tambahkan ini
            ]);

        } catch (ValidationException $e) {
            Log::warning('Validasi unggah gambar gagal.', ['errors' => $e->errors(), 'input' => $request->all()]);
            return response()->json(['success' => false, 'message' => 'Input tidak valid: ' . $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            Log::error('Error saat handleImageUpload ke S3.', ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server saat mengunggah gambar: ' . Str::limit($e->getMessage(), 150)], 500);
        }
    }

    public function predictDefault(Request $request): JsonResponse
    {
        // Tambahkan log ini untuk melihat apa yang sebenarnya diterima server
        Log::debug('--- PREDICT DEFAULT INPUT ---');
        Log::debug('Request Headers:', $request->headers->all());                  // Periksa Content-Type
        Log::debug('Request Raw Content:', ['content' => $request->getContent()]); // Lihat body mentah
        Log::debug('Request Decoded JSON ($request->json()->all()):', $request->json()->all() ?: ['PAYLOAD JSON KOSONG ATAU BUKAN JSON']);
        Log::debug('Request All Input ($request->all()):', $request->all()); // Apa isi $request->all()?

        // --- PERUBAHAN DI SINI ---
        // Gunakan $request->json()->all() untuk data dari body JSON
        $inputData = $request->json()->all();
        $validator = Validator::make($inputData, [ // Validasi $inputData
            'filename' => 'required|string',
            's3_path'  => 'required|string',
        ]);
        // --- AKHIR PERUBAHAN ---

        if ($validator->fails()) {
            Log::warning('PredictionController: predictDefault validation failed.', ['errors' => $validator->errors(), 'received_input' => $inputData]);
            return response()->json(['success' => false, 'message' => 'Input tidak valid untuk prediksi.', 'errors' => $validator->errors()], 422);
        }

                                                        // Jika validasi berhasil, $inputData sudah berisi data yang divalidasi
                                                        // atau gunakan $validator->validated() jika Anda lebih suka
        $validatedData       = $validator->validated(); // Atau tetap gunakan $inputData jika sudah pasti bersih
        $filenameFromRequest = $validatedData['filename'];
        $s3ImagePath         = $validatedData['s3_path'];

        Log::info('Memulai pipeline prediksi default (DINAMIS) untuk file S3', ['original_filename' => $filenameFromRequest, 's3_image_path' => $s3ImagePath]);

        $results = $this->initializeResultArray([
            's3_path'           => $s3ImagePath,
            'original_filename' => $filenameFromRequest,
        ]);

        try {
            if (! Storage::disk('s3')->exists($s3ImagePath)) {
                Log::error('File S3 tidak ditemukan untuk prediksi default.', ['s3_path' => $s3ImagePath]);
                throw new RuntimeException("File sumber di S3 tidak ditemukan: " . basename($s3ImagePath));
            }

            // Ekstrak fitur deteksi dari gambar di S3
            $detectionFeatures = $this->featureExtractor->extractDetectionFeatures($s3ImagePath);
            if ($detectionFeatures === null) {
                throw new RuntimeException("Gagal mengekstrak fitur deteksi untuk {$filenameFromRequest}.");
            }
            $results['context']['detectionFeatures']            = $detectionFeatures;
            $results['context_features_extracted']['detection'] = true;

            // === PERUBAHAN INTI UNTUK DETEKSI ===
            // Lakukan deteksi dengan metode dinamis
            $detectionResult              = $this->predictionService->performDynamicDetection($detectionFeatures);
            $results['default_detection'] = $detectionResult;
            // ===================================

            // Jika deteksi berhasil dan objek terdeteksi sebagai melon
            // Logika selanjutnya tetap sama, menggunakan $detectionResult yang baru
            if ($detectionResult && ($detectionResult['success'] ?? false) && ($detectionResult['detected'] ?? false)) {
                Log::info('Melon terdeteksi oleh model dinamis.', [
                    'filename' => $filenameFromRequest,
                    'model'    => $detectionResult['model_key'] ?? 'N/A',
                ]);

                $bboxEstimationResult = $this->predictionService->runPythonBboxEstimator($s3ImagePath);

                if ($bboxEstimationResult && ($bboxEstimationResult['success'] ?? false) && ! empty($bboxEstimationResult['bboxes'])) {
                    $results['bbox']                            = $bboxEstimationResult['bboxes'][0];
                    $results['bbox_estimated_successfully']     = true;
                    $results['context']['bboxEstimated']        = true;
                    $results['context']['bbox_from_python_rel'] = $results['bbox']; // Simpan juga di konteks jika perlu

                    Log::info('BBox berhasil diestimasi dan dikonversi ke relatif.', ['filename' => $filenameFromRequest, 'bbox_rel' => $results['bbox']]);

                    // Ekstrak fitur warna dari BBox
                    $colorFeatures = $this->featureExtractor->extractColorFeaturesFromBbox(
                        $s3ImagePath,
                        $results['bbox']
                    );

                    if ($colorFeatures) {
                        $results['context']['colorFeatures']                     = $colorFeatures;
                        $results['context_features_extracted']['classification'] = true;

                        // === PERUBAHAN INTI UNTUK KLASIFIKASI ===
                        $classificationResult = $this->predictionService->performDynamicClassification($colorFeatures);
                        // =======================================

                        $results['default_classification'] = $classificationResult;
                        $results['classification_done']    = $classificationResult['success'] ?? false;
                        if (! ($classificationResult['success'] ?? false)) {
                            $results['classification_error'] = $classificationResult['error'] ?? 'Klasifikasi dinamis gagal tanpa pesan error spesifik.';
                        }
                        Log::info('Klasifikasi dinamis selesai.', [
                            'filename' => $filenameFromRequest,
                            'model'    => $classificationResult['model_key'] ?? 'N/A',
                            'result'   => $classificationResult['prediction'] ?? 'N/A',
                        ]);
                    } else {
                        $results['classification_done']  = false;
                        $results['classification_error'] = 'Gagal ekstrak fitur warna dari BBox untuk klasifikasi.';
                        Log::warning($results['classification_error'], ['filename' => $filenameFromRequest]);
                    }
                } else {
                    $results['bbox_estimated_successfully'] = false;
                    $results['classification_done']         = false; // Klasifikasi tidak bisa dilakukan tanpa BBox
                    $results['classification_error']        = 'Estimasi Bounding Box otomatis gagal atau tidak menemukan BBox.';
                    $results['message']                     = $bboxEstimationResult['message'] ?? $results['classification_error'];
                    Log::warning($results['message'], ['filename' => $filenameFromRequest]);
                }
            } elseif ($detectionResult && ($detectionResult['success'] ?? false)) { // Deteksi berhasil tapi tidak ada melon
                $results['message']              = 'Tidak ada melon yang terdeteksi pada gambar.';
                $results['classification_done']  = false; // Tidak ada klasifikasi jika tidak ada melon
                $results['classification_error'] = $results['message'];
                Log::info($results['message'], ['filename' => $filenameFromRequest]);
            } else { // Deteksi awal gagal
                $results['message']              = 'Proses deteksi awal gagal: ' . ($detectionResult['error'] ?? 'Unknown error');
                $results['classification_done']  = false;
                $results['classification_error'] = $results['message'];
                Log::error($results['message'], ['filename' => $filenameFromRequest]);
            }

            // Jika sampai sini, set success menjadi true, pesan akan diisi oleh kondisi di atas
            $results['success'] = true;

            // Ambil base64 dari gambar original di S3 untuk ditampilkan di frontend
            if (! empty($s3ImagePath) && Storage::disk('s3')->exists($s3ImagePath)) {
                try {
                    $fileContent = Storage::disk('s3')->get($s3ImagePath);
                    if ($fileContent) {
                        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
                        $disk     = Storage::disk('s3');
                        $mimeType = $disk->mimeType($s3ImagePath);
                        if (! $mimeType) {
                            $extension = strtolower(pathinfo($s3ImagePath, PATHINFO_EXTENSION));
                            $mimeType  = 'image/' . ($extension === 'jpg' ? 'jpeg' : $extension);
                        }
                        $results['image_base64_data'] = 'data:' . $mimeType . ';base64,' . base64_encode($fileContent);
                        Log::info('Berhasil membuat base64 untuk gambar original dari S3.', ['s3_path' => $s3ImagePath]);
                    } else {
                        Log::warning('Gagal membaca konten file dari S3 untuk base64.', ['s3_path' => $s3ImagePath]);
                    }
                } catch (Throwable $e) {
                    Log::error('Error saat membuat base64 dari gambar S3.', ['s3_path' => $s3ImagePath, 'error' => $e->getMessage()]);
                }
            }

        } catch (Throwable $e) {
            Log::error('Error pada pipeline prediksi default.', ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            $results['success'] = false;
            $results['message'] = 'Terjadi kesalahan sistem saat prediksi: ' . Str::limit($e->getMessage(), 150);
            // Pastikan field lain yang diharapkan frontend ada meskipun null
            $results['default_detection']      = $results['default_detection'] ?? ['success' => false, 'error' => $results['message']];
            $results['default_classification'] = $results['default_classification'] ?? ['success' => false, 'error' => $results['message']];
        } finally {
            // File S3 di uploads_temp TIDAK dihapus di sini agar bisa dianalisis/feedback
            Log::info('Prediction default finished. Temporary file S3 NOT deleted.', ['s3_path' => $s3ImagePath]);
        }

        return response()->json($results);
    }

    // Method getAllOtherModelResults Anda (tidak perlu diubah kecuali ada masalah spesifik)
    public function getAllOtherModelResults(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename'                            => 'required|string',
            'context'                             => 'required|array',
            'context.uploaded_s3_path'            => 'required_with:run_all_detectors,run_all_classifiers|string',
            'context.detectionFeatures'           => 'nullable|array',
            'context.colorFeaturesFromContext'    => 'nullable|array',
            'context.bbox_estimated_successfully' => 'boolean',
            'context.bbox_from_python_rel'        => 'nullable|array',

            // !!! TAMBAHKAN VALIDASI UNTUK HASIL DEFAULT !!!
            'default_detection_result'            => 'nullable|array',
            'default_classification_result'       => 'nullable|array',
            // !!! AKHIR PENAMBAHAN VALIDASI !!!

            'target_detector'                     => 'nullable|string',
            'target_classifier'                   => 'nullable|string',
            'run_all_detectors'                   => 'boolean',
            'run_all_classifiers'                 => 'boolean',
        ]);

        $filename                 = $validated['filename'];
        $context                  = $validated['context'];
        $s3ImagePath              = $context['uploaded_s3_path'] ?? null;         // Path S3 utama yang sedang diproses
        $detectionFeatures        = $context['detectionFeatures'] ?? null;        // Fitur deteksi dari gambar utama
        $colorFeaturesFromContext = $context['colorFeaturesFromContext'] ?? null; // Fitur warna dari BBox utama (jika ada)
                                                                                  // $bboxRelFromContext       = $context['bbox_from_python_rel'] ?? null; // BBox relatif utama (jika ada)
                                                                                  // $bboxEstimated            = $context['bbox_estimated_successfully'] ?? false; // Apakah BBox utama berhasil diestimasi

        $targetDetector    = $validated['target_detector'] ?? null;
        $targetClassifier  = $validated['target_classifier'] ?? null;
        $runAllDetectors   = $validated['run_all_detectors'] ?? false;
        $runAllClassifiers = $validated['run_all_classifiers'] ?? false;

        $defaultDetectionResultFromRequest      = $validated['default_detection_result'] ?? null;
        $defaultClassificationResultFromRequest = $validated['default_classification_result'] ?? null;

        $results = [
            'detectors'                       => [],
            'classifiers'                     => [],
            'detector_majority_vote'          => null,
            'majority_vote'                   => null,    // Untuk klasifikasi
            'triggered_classification_result' => null,    // Untuk hasil klasifikasi yang terpicu oleh detektor lain
            'updated_context_for_frontend'    => [        // Jika ada perubahan konteks yang perlu dikirim balik
                'colorFeatures' => $colorFeaturesFromContext, // Defaultnya pakai yg lama
                'bbox_rel'      => $context['bbox_from_python_rel'] ?? null,
            ],
            'success'                         => true,
            'message'                         => '',
        ];

        $processedDetectorsOutput   = [];
        $processedClassifiersOutput = [];

        try {
            // --- Logika untuk Semua Detektor Lain atau Target Detektor ---
            if ($runAllDetectors || $targetDetector) {
                if (! $detectionFeatures && $s3ImagePath) { // Jika fitur deteksi belum ada, coba ekstrak lagi
                    Log::info("getAllOtherModelResults: Detection features missing, attempting re-extraction for S3 path: {$s3ImagePath}");
                    $detectionFeatures = $this->featureExtractor->extractDetectionFeatures($s3ImagePath);
                }

                if ($detectionFeatures) {
                    $excludeDetKeys         = [];
                    $mainDefaultDetectorKey = null;

                    // Dapatkan model_key dari hasil deteksi default
                    if ($defaultDetectionResultFromRequest && isset($defaultDetectionResultFromRequest['model_key'])) {
                        $mainDefaultDetectorKey = $defaultDetectionResultFromRequest['model_key'];
                    }

                    // Jika menjalankan semua model (bukan target spesifik) dan ada model default, eksklusikan dari pemanggilan runOtherDetectors
                    if ($runAllDetectors && ! $targetDetector && $mainDefaultDetectorKey) {
                        $excludeDetKeys[] = $mainDefaultDetectorKey;
                    }

                    $otherDetectorResultsRaw = $this->predictionService->runOtherDetectors(
                        $detectionFeatures,
                        $targetDetector,
                        $excludeDetKeys // eksklusikan model default jika $runAllDetectors true
                    );
                    $processedDetectorsOutput = $otherDetectorResultsRaw; // Ini untuk ditampilkan di list "Model Deteksi Lain"

                                                                               // --- PENGGABUNGAN HASIL UNTUK MAJORITY VOTE DETEKTOR ---
                    $allDetectorResultsForMajority = $otherDetectorResultsRaw; // Mulai dengan hasil model lain
                    if ($runAllDetectors && $defaultDetectionResultFromRequest && $mainDefaultDetectorKey) {
                        // Tambahkan hasil model default ke array untuk kalkulasi majority vote
                        // Pastikan tidak duplikat jika (secara tidak sengaja) sudah ada
                        if (! isset($allDetectorResultsForMajority[$mainDefaultDetectorKey])) {
                            $allDetectorResultsForMajority[$mainDefaultDetectorKey] = $defaultDetectionResultFromRequest;
                        }
                    }
                    // --- AKHIR PENGGABUNGAN ---

                    // Jika target detector spesifik dijalankan DAN mendeteksi melon
                    if ($targetDetector && isset($otherDetectorResultsRaw[$targetDetector])) {
                        $singleDetResult = $otherDetectorResultsRaw[$targetDetector];
                        if ($singleDetResult['success'] && $singleDetResult['detected'] && $s3ImagePath) {
                            Log::info("Target detector '{$targetDetector}' detected melon. Attempting BBox and classification.");
                            $results['message'] .= "Detektor {$targetDetector} mendeteksi melon. ";
                            $bboxRes = $this->predictionService->runPythonBboxEstimator($s3ImagePath);

                            if ($bboxRes && ($bboxRes['success'] ?? false) && ! empty($bboxRes['bboxes'])) {
                                $newBboxRel = $bboxRes['bboxes'][0];
                                // Update BBox di hasil spesifik detektor ini untuk frontend
                                $processedDetectorsOutput[$targetDetector]['bbox_rel']                    = $newBboxRel;
                                $processedDetectorsOutput[$targetDetector]['bbox_estimated_successfully'] = true;
                                $results['updated_context_for_frontend']['bbox_rel']                      = $newBboxRel; // Update konteks utama juga
                                Log::info("New BBox estimated by target detector '{$targetDetector}'.", ['bbox' => $newBboxRel]);
                                $results['message'] .= "BBox baru diestimasi. ";

                                $newColorFeatures = $this->featureExtractor->extractColorFeaturesFromBbox($s3ImagePath, $newBboxRel);
                                if ($newColorFeatures) {
                                    $results['updated_context_for_frontend']['colorFeatures'] = $newColorFeatures; // Update konteks utama
                                                                                                                   // Jalankan SEMUA classifier dengan fitur warna BARU ini
                                                                                                                   // (atau hanya best classifier jika itu yang diinginkan)
                                    $bestClassifierKeyForTrigger = $this->predictionService->getBestClassifierKey();
                                    if ($bestClassifierKeyForTrigger) {
                                        $classRes                                   = $this->predictionService->performSingleClassification($bestClassifierKeyForTrigger, $newColorFeatures);
                                        $results['triggered_classification_result'] = $classRes; // Simpan hasil klasifikasi yang terpicu
                                        $results['message'] .= "Klasifikasi otomatis dengan model terbaik (" . ($classRes['model_key'] ?? 'N/A') . ") dijalankan: " . ($classRes['prediction'] ?? 'Gagal') . ". ";
                                        Log::info("Automatic classification triggered by target detector.", ['classifier' => $bestClassifierKeyForTrigger, 'result' => $classRes['prediction'] ?? 'N/A']);
                                    }
                                } else {
                                    $results['message'] .= "Gagal ekstrak fitur warna dari BBox baru untuk klasifikasi otomatis. ";
                                    Log::warning("Failed to extract color features from new BBox for target detector '{$targetDetector}'.");
                                }
                            } else {
                                $results['message'] .= "Estimasi BBox gagal untuk detektor {$targetDetector}. ";
                                Log::warning("BBox estimation failed for target detector '{$targetDetector}'.");
                                $processedDetectorsOutput[$targetDetector]['bbox_estimated_successfully'] = false;
                            }
                        } elseif ($singleDetResult['success'] && ! $singleDetResult['detected']) {
                            $results['message'] .= "Detektor {$targetDetector} tidak mendeteksi melon. ";
                        } elseif (! $singleDetResult['success']) {
                            $results['message'] .= "Gagal menjalankan detektor {$targetDetector}: " . ($singleDetResult['error'] ?? 'Unknown') . ". ";
                        }
                    }
                } else {
                    $results['message'] .= "Gagal mendapatkan fitur deteksi untuk menjalankan model detektor lain. ";
                    Log::warning("getAllOtherModelResults: Failed to get detection features for other detectors.");
                }
            }
            $results['detectors'] = $processedDetectorsOutput; // Selalu set, bisa kosong

            // Hitung mayoritas detektor jika semua dijalankan (menggunakan hasil gabungan)
            if ($runAllDetectors && ! empty($allDetectorResultsForMajority)) {
                $results['detector_majority_vote'] = $this->predictionService->calculateDetectorMajorityVote($allDetectorResultsForMajority);
            }

            // --- Logika untuk Semua Classifier Lain atau Target Classifier ---
            $finalColorFeaturesToUse = $results['updated_context_for_frontend']['colorFeatures'] ?? $colorFeaturesFromContext;

            if (($runAllClassifiers || $targetClassifier) && $finalColorFeaturesToUse) {
                $excludeClsKeys           = [];
                $mainDefaultClassifierKey = null;

                if ($defaultClassificationResultFromRequest && isset($defaultClassificationResultFromRequest['model_key'])) {
                    $mainDefaultClassifierKey = $defaultClassificationResultFromRequest['model_key'];
                }

                if ($runAllClassifiers && ! $targetClassifier && $mainDefaultClassifierKey) {
                    $excludeClsKeys[] = $mainDefaultClassifierKey;
                }

                $processedClassifiersOutput = $this->predictionService->runOtherClassifiers(
                    $finalColorFeaturesToUse,
                    $targetClassifier,
                    $excludeClsKeys
                );
                $results['classifiers'] = $processedClassifiersOutput;

                // --- PENGGABUNGAN HASIL UNTUK MAJORITY VOTE KLASIFIKASI ---
                $allClassifierResultsForMajority = $processedClassifiersOutput;
                if ($runAllClassifiers && $defaultClassificationResultFromRequest && $mainDefaultClassifierKey) {
                    if (! isset($allClassifierResultsForMajority[$mainDefaultClassifierKey])) {
                        $allClassifierResultsForMajority[$mainDefaultClassifierKey] = $defaultClassificationResultFromRequest;
                    }
                }
                // --- AKHIR PENGGABUNGAN ---

                if ($runAllClassifiers && ! empty($allClassifierResultsForMajority)) {
                    $results['majority_vote'] = $this->predictionService->calculateMajorityVote($allClassifierResultsForMajority);
                }
                if ($targetClassifier && ! empty($processedClassifiersOutput[$targetClassifier]['prediction'] ?? null)) {
                    $results['message'] .= "Klasifikasi dengan model {$targetClassifier} selesai: " . ($processedClassifiersOutput[$targetClassifier]['prediction']) . ". ";
                } elseif ($targetClassifier) {
                    $results['message'] .= "Gagal menjalankan klasifikasi dengan model {$targetClassifier}. ";
                }

            } elseif (($runAllClassifiers || $targetClassifier) && ! $finalColorFeaturesToUse) {
                $results['message'] .= "Fitur warna tidak tersedia untuk menjalankan model klasifikasi lain. Pastikan BBox terdeteksi dan fitur warna berhasil diekstrak.";
                Log::warning("getAllOtherModelResults: Color features not available for other classifiers.");
            }

            if (empty($results['message'])) {
                $results['message'] = 'Permintaan hasil model lain berhasil diproses.';
            }

        } catch (Throwable $e) {
            Log::error('Error saat getAllOtherModelResults', ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            $results['success'] = false;
            $results['message'] = 'Gagal mengambil hasil model lain: ' . Str::limit($e->getMessage(), 150);
        }

        return response()->json($results);
    }

    public function runBboxAndClassifyOnDemand(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            's3_image_path' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Input tidak valid: Path gambar S3 diperlukan.', 'errors' => $validator->errors()], 422);
        }
        $s3ImagePath = $validator->validated()['s3_image_path'];

        Log::info("[OnDemandBC] Memulai estimasi BBox & klasifikasi untuk: {$s3ImagePath}");

        try {
            if (! Storage::disk('s3')->exists($s3ImagePath)) {
                Log::warning("[OnDemandBC] File S3 tidak ditemukan: {$s3ImagePath}");
                return response()->json(['success' => false, 'message' => "File sumber di S3 tidak ditemukan: " . basename($s3ImagePath)], 404);
            }

            $bboxEstimationResult = $this->predictionService->runPythonBboxEstimator($s3ImagePath);

            if (! $bboxEstimationResult || ! ($bboxEstimationResult['success'] ?? false) || empty($bboxEstimationResult['bboxes'])) {
                $errMsg = $bboxEstimationResult['message'] ?? 'Estimasi BBox otomatis gagal atau tidak menemukan BBox.';
                Log::warning("[OnDemandBC] Estimasi BBox gagal atau tidak ada BBox.", ['s3_path' => $s3ImagePath, 'error' => $errMsg]);
                return response()->json(['success' => false, 'message' => $errMsg]);
            }

            $newBboxRel = $bboxEstimationResult['bboxes'][0];
            Log::info("[OnDemandBC] BBox berhasil diestimasi secara on-demand.", ['s3_path' => $s3ImagePath, 'bbox_rel' => $newBboxRel]);

            $colorFeatures = $this->featureExtractor->extractColorFeaturesFromBbox($s3ImagePath, $newBboxRel);
            if (! $colorFeatures) {
                Log::warning("[OnDemandBC] Gagal ekstrak fitur warna dari BBox baru.", ['s3_path' => $s3ImagePath, 'bbox' => $newBboxRel]);
                return response()->json([
                    'success'      => false,
                    'message'      => 'Gagal mengekstrak fitur warna dari BBox yang baru diestimasi.',
                    'new_bbox_rel' => $newBboxRel,
                ]);
            }
            Log::info("[OnDemandBC] Fitur warna berhasil diekstrak dari BBox baru.", ['s3_path' => $s3ImagePath]);

            $bestClassifierKey = $this->predictionService->getBestClassifierKey();
            if (! $bestClassifierKey) {
                Log::warning("[OnDemandBC] Tidak dapat menentukan model classifier terbaik statis.");
                return response()->json([
                    'success'                  => false,
                    'message'                  => 'Tidak dapat menentukan model classifier terbaik untuk klasifikasi on-demand.',
                    'new_bbox_rel'             => $newBboxRel,
                    'new_color_features'       => $colorFeatures, // Kembalikan fitur warna meskipun classifier terbaik tidak ada
                    'color_features_extracted' => true,
                ]);
            }

            $classificationResult = $this->predictionService->performSingleClassification($bestClassifierKey, $colorFeatures);
            Log::info("[OnDemandBC] Klasifikasi on-demand selesai.", [
                's3_path'        => $s3ImagePath,
                'classifier_key' => $bestClassifierKey,
                'result_success' => $classificationResult['success'] ?? false,
            ]);

            return response()->json([
                'success'                         => true,
                'message'                         => 'Estimasi BBox dan klasifikasi on-demand berhasil.',
                'new_bbox_rel'                    => $newBboxRel,
                'new_color_features'              => $colorFeatures, // <<< TAMBAHKAN INI
                'triggered_classification_result' => $classificationResult,
                'used_classifier_key'             => $bestClassifierKey,
            ]);

        } catch (Throwable $e) {
            Log::error('[OnDemandBC] Error selama estimasi BBox & klasifikasi on-demand.', ['s3_path' => $s3ImagePath, 'error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            return response()->json(['success' => false, 'message' => 'Terjadi kesalahan server: ' . Str::limit($e->getMessage(), 150)], 500);
        }
    }
}
