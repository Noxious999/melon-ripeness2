<?php

// File: app/Services/FeatureExtractionService.php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickException;
use RuntimeException;
use Throwable;

class FeatureExtractionService
{
    // --- Konfigurasi Ukuran Resize ---
    private const RESIZE_TARGET_DETECTION_WHOLE = 128;
    private const RESIZE_TARGET_CLASSIFIER_BBOX = 64;

    // --- Konfigurasi Debug ---
    private const DEBUG_SAVE_IMAGES = false;
    private const DEBUG_IMAGE_DISK  = 'local';
    private const DEBUG_IMAGE_PATH  = 'debug_images/feature_extraction';

    // Konstanta Path dan Konfigurasi
    public const S3_FEATURE_DIR  = 'dataset/features';
    public const CSV_HEADER      = ['filename', 'set', 'detection_class', 'ripeness_class', 'bbox_cx', 'bbox_cy', 'bbox_w', 'bbox_h'];
    public const DEFAULT_K_FOLDS = 5;

    // --- Definisi Fitur Detektor ---
    public const DETECTOR_VALID_LABELS  = ['melon', 'non_melon'];
    public const DETECTOR_FEATURE_COUNT = 8;
    public const DETECTOR_THRESHOLD     = 0.50; // Ini lebih ke konstanta prediksi, mungkin lebih cocok di PredictionService
    public const DETECTOR_FEATURE_NAMES = [
        'R_mean', 'G_mean', 'B_mean',
        'R_std', 'G_std', 'B_std',
        'aspect_ratio', 'circularity',
    ];

    // --- Definisi Fitur Classifier ---
    private const EXPECTED_COLOR_FEATURES_COUNT   = 6;
    private const EXPECTED_TEXTURE_FEATURES_COUNT = 32; // 4 properti * 4 sudut * 2 jarak

    public const CLASSIFIER_VALID_LABELS  = ['ripe', 'unripe'];
    public const CLASSIFIER_FEATURE_COUNT = self::EXPECTED_COLOR_FEATURES_COUNT + self::EXPECTED_TEXTURE_FEATURES_COUNT; // Total 38

    public const CLASSIFIER_FEATURE_NAMES = [
        // 6 Color Features
        'R_mean', 'G_mean', 'B_mean',
        'R_std', 'G_std', 'B_std',
                                                                                // 32 Texture Features - URUTAN INI SUDAH SAYA VERIFIKASI SESUAI LOOP GLCM ANDA
                                                                                // Jarak 1 (GLCM_DISTANCES[0])
        'contrast0_1', 'correlation0_1', 'energy0_1', 'homogeneity0_1',         // Sudut 0
        'contrast45_1', 'correlation45_1', 'energy45_1', 'homogeneity45_1',     // Sudut 45
        'contrast90_1', 'correlation90_1', 'energy90_1', 'homogeneity90_1',     // Sudut 90
        'contrast135_1', 'correlation135_1', 'energy135_1', 'homogeneity135_1', // Sudut 135
                                                                                // Jarak 2 (GLCM_DISTANCES[1], Anda pakai suffix _2)
        'contrast0_2', 'correlation0_2', 'energy0_2', 'homogeneity0_2',         // Sudut 0
        'contrast45_2', 'correlation45_2', 'energy45_2', 'homogeneity45_2',     // Sudut 45
        'contrast90_2', 'correlation90_2', 'energy90_2', 'homogeneity90_2',     // Sudut 90
        'contrast135_2', 'correlation135_2', 'energy135_2', 'homogeneity135_2', // Sudut 135
    ];

                                                     // --- Konfigurasi GLCM ---
    private const GLCM_DISTANCES = [1, 3];           // Jarak untuk GLCM (indeks 0 adalah jarak 1, indeks 1 adalah jarak 3)
    private const GLCM_ANGLES    = [0, 45, 90, 135]; // Sudut dalam derajat
    private const GLCM_LEVELS    = 8;                // Jumlah level kuantisasi

    public function extractDetectionFeatures(string $s3Path): ?array// Ubah nama parameter agar jelas ini path S3
    {
        $baseFilename = basename($s3Path);
        Log::debug("[Detection Features - Whole Image] Starting extraction", ['path' => $baseFilename]);
        $img        = null;
        $resizedImg = null;

        try {
            $img = $this->loadImage($s3Path); // $s3Path digunakan di sini
            if (! $this->isValidImagick($img)) {
                return null;
            }

            $originalWidth  = $img->getImageWidth();
            $originalHeight = $img->getImageHeight();
            $shapeFeatures  = $this->calculateShapeFeatures($originalWidth, $originalHeight);

            $resizedImg = $this->resizeImage($img, self::RESIZE_TARGET_DETECTION_WHOLE);
            if (! $this->isValidImagick($resizedImg)) {$this->cleanupImagick($img);return null;}
            $this->saveDebugImage($resizedImg, $baseFilename, 'resize_detect_whole');

            $colorStats = $this->getColorStatistics($resizedImg);
            $this->cleanupImagick($resizedImg); // $resizedImg sudah selesai dipakai

            if (! $colorStats) {$this->cleanupImagick($img);return null;}

            $features = [
                $colorStats['r_mean'], $colorStats['g_mean'], $colorStats['b_mean'],
                $colorStats['r_std'], $colorStats['g_std'], $colorStats['b_std'],
                $shapeFeatures['aspectRatio'], $shapeFeatures['circularity'],
            ];

            if (count($features) !== self::DETECTOR_FEATURE_COUNT) {
                Log::critical("Detector feature count mismatch!", ['path' => $s3Path, 'expected' => self::DETECTOR_FEATURE_COUNT, 'got' => count($features)]);
                $this->cleanupImagick($img);return null;
            }
            Log::info("[Detection Features - Whole Image] Extracted successfully", ['path' => $baseFilename]);
            $this->cleanupImagick($img);
            return $features;
        } catch (Throwable $e) {
            Log::error("[Detection Features - Whole Image] Unexpected error", ['msg' => $e->getMessage(), 'path' => $s3Path, 'trace_snippet' => Str::limit($e->getTraceAsString(), 500)]);
            $this->cleanupImagick($img, $resizedImg);
            return null;
        }
    }

    public function extractColorFeaturesFromBbox(string $s3Path, array $bboxRel): ?array
    {
        $baseFilename = basename($s3Path);
        Log::debug("[Classifier Features - Bbox] Starting extraction", ['path' => $baseFilename, 'bbox' => $bboxRel]);
        $img        = null;
        $croppedImg = null;
        $resizedImg = null;

        if (($bboxRel['cx'] ?? -1) < 0 || ($bboxRel['cx'] ?? -1) > 1 || ($bboxRel['cy'] ?? -1) < 0 || ($bboxRel['cy'] ?? -1) > 1 || ($bboxRel['w'] ?? -1) <= 0 || ($bboxRel['h'] ?? -1) <= 0) {
            Log::error("[Classifier Features - Bbox] Invalid relative bbox coordinates.", ['path' => $baseFilename, 'bbox' => $bboxRel]);
            return null;
        }

        try {
            $img = $this->loadImage($s3Path);
            if (! $this->isValidImagick($img)) {
                return null;
            }

            $cropParams = $this->calculateAndValidateCrop($img->getImageWidth(), $img->getImageHeight(), $bboxRel['cx'], $bboxRel['cy'], $bboxRel['w'], $bboxRel['h']);
            if (! $cropParams) {$this->cleanupImagick($img);return null;}

            $croppedImg = $this->cropImage($img, $cropParams['x'], $cropParams['y'], $cropParams['w'], $cropParams['h']);
            if (! $this->isValidImagick($croppedImg)) {$this->cleanupImagick($img);return null;}
            $this->saveDebugImage($croppedImg, $baseFilename, 'crop_cls');

            $resizedImg = $this->resizeImage($croppedImg, self::RESIZE_TARGET_CLASSIFIER_BBOX);
            $this->cleanupImagick($croppedImg); // $croppedImg selesai dipakai
            if (! $this->isValidImagick($resizedImg)) {$this->cleanupImagick($img);return null;}
            $this->saveDebugImage($resizedImg, $baseFilename, 'resize_cls');

                                                                       // $resizedImg akan dimodifikasi oleh getColorStatistics jika perlu ubah colorspace
            $colorFeatures = $this->extractColorFeatures($resizedImg); // Helper akan memvalidasi jumlahnya
            if (! $colorFeatures) {                                     // Jika extractColorFeatures gagal atau jumlah tidak sesuai, $colorFeatures akan null
                Log::error("[Classifier Features - Bbox] Color feature extraction failed or count mismatch.", ['path' => $baseFilename]);
                $this->cleanupImagick($img, $resizedImg);
                return null;
            }

            // PENTING: Pastikan $resizedImg masih valid SETELAH extractColorFeatures
            if (! $this->isValidImagick($resizedImg)) {
                Log::critical("[Classifier Features - Bbox] RESIZED IMAGE BECAME INVALID after color extraction, before texture extraction!", ['path' => $baseFilename]);
                $this->cleanupImagick($img); // $resizedImg sudah invalid
                return null;
            }

            $textureFeatures = $this->extractTextureFeatures($resizedImg); // Helper akan memvalidasi jumlahnya
            if (! $textureFeatures) {                                       // Jika extractTextureFeatures gagal atau jumlah tidak sesuai, $textureFeatures akan null
                Log::error("[Classifier Features - Bbox] Texture feature extraction failed or count mismatch.", ['path' => $baseFilename]);
                $this->cleanupImagick($img, $resizedImg);
                return null;
            }
            // $resizedImg sudah di-cleanup di dalam extractTextureFeatures jika sukses, atau di catch jika error

            $features = array_merge($colorFeatures, $textureFeatures);
            if (count($features) !== self::CLASSIFIER_FEATURE_COUNT) {
                Log::critical("Classifier feature count mismatch AFTER MERGE!", ['path' => $s3Path, 'bbox' => $bboxRel, 'expected' => self::CLASSIFIER_FEATURE_COUNT, 'got' => count($features), 'color_count' => count($colorFeatures), 'texture_count' => count($textureFeatures)]);
                $this->cleanupImagick($img); // $resizedImg sudah dihandle
                return null;
            }

            Log::info("[Classifier Features - Bbox] Extracted successfully", ['path' => $baseFilename]);
            $this->cleanupImagick($img);
            return $features;
        } catch (Throwable $e) {
            Log::error("[Classifier Features - Bbox] Unexpected error", ['msg' => $e->getMessage(), 'path' => $s3Path, 'bbox' => $bboxRel, 'trace_snippet' => Str::limit($e->getTraceAsString(), 500)]);
            $this->cleanupImagick($img, $croppedImg ?? null, $resizedImg ?? null);
            return null;
        }
    }

    private function isValidImagick(?Imagick $img): bool
    {
        return $img instanceof Imagick && $img->getNumberImages() > 0 && $img->getImageWidth() > 0 && $img->getImageHeight() > 0;
    }

    //======================================================================
    // Metode Helper Internal (loadImage, calculateAndValidateCrop, cropImage, resizeImage, getColorStatistics, calculateShapeFeatures, saveDebugImage, cleanupImagick)
    // Tetap sama seperti versi sebelumnya, tidak perlu diubah kecuali ada bug.
    // Pastikan calculateShapeFeatures menggunakan width dan height yang diterima sebagai argumen.
    //======================================================================

    /** Memuat gambar dan melakukan validasi awal */
    public function loadImage(string $s3Path): ?Imagick
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 512);
        $baseFilename = basename($s3Path); // Bisa dipakai untuk logging jika mau

        if (! extension_loaded('imagick')) {
            Log::critical("Imagick extension not loaded!");
            throw new RuntimeException("Imagick extension not loaded!");
        }

        Log::debug("[loadImage] Attempting to load image from S3", ['s3_path' => $s3Path]); // Ubah info ke debug

        if (! Storage::disk('s3')->exists($s3Path)) {
            Log::error("FeatureExtractionService: Image file not found on S3.", ['s3_path' => $s3Path]);
            return null;
        }

        try {
            $imageBlob = Storage::disk('s3')->get($s3Path);
            if ($imageBlob === null) {
                Log::error("FeatureExtractionService: Failed to get image blob from S3 (null returned).", ['s3_path' => $s3Path]);
                return null;
            }
            // Log::debug("FeatureExtractionService: Successfully got image blob from S3.", ['s3_path' => $s3Path, 'blob_size_bytes' => strlen($imageBlob)]);

            $img = new Imagick();
            $img->readImageBlob($imageBlob);
            if (! $this->isValidImagick($img)) { // Gunakan helper validasi
                Log::warning("[loadImage] Loaded image is invalid (zero images/width/height).", ['path' => $s3Path]);
                $this->cleanupImagick($img);return null;
            }

            if ($img->getImageWidth() < 10 || $img->getImageHeight() < 10) {
                Log::warning("Image dimensions too small", ['path' => $s3Path, 'w' => $img->getImageWidth(), 'h' => $img->getImageHeight()]);
                $this->cleanupImagick($img);
                return null;
            }
            $allowedFormats = ['JPEG', 'PNG', 'WEBP', 'BMP', 'GIF']; // Izinkan format gambar umum yang bisa dibaca Imagick
            if (! in_array(strtoupper($img->getImageFormat()), $allowedFormats)) {
                Log::warning("Unsupported Imagick format detected after loading", ['format' => $allowedFormats, 'path' => $baseFilename]);
                $this->cleanupImagick($img);
                return null;
            }
            Log::debug("[loadImage] Image loaded successfully", ['path' => basename($s3Path), 'w' => $img->getImageWidth(), 'h' => $img->getImageHeight()]);
            return $img;
        } catch (ImagickException $e) {
            Log::error("FeatureExtractionService: ImagickException during S3 image processing.", ['s3_path' => $s3Path, 'error' => $e->getMessage(), 'code' => $e->getCode()]);
            return null;
        } catch (Throwable $e) {
            Log::error("FeatureExtractionService: General Throwable during S3 image processing.", ['s3_path' => $s3Path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Menghitung, memvalidasi, dan meng-clamp parameter crop absolut */
    /** @return array{x: int, y: int, w: int, h: int}|null */
    private function calculateAndValidateCrop(int $origW, int $origH, float $cx, float $cy, float $w, float $h): ?array
    {
        if ($w <= 0 || $w > 1.0 || $h <= 0 || $h > 1.0 || $cx < 0 || $cx > 1.0 || $cy < 0 || $cy > 1.0) {
            Log::error("Invalid relative crop dimensions/coordinates (range 0-1 violated)", ['cx' => $cx, 'cy' => $cy, 'w' => $w, 'h' => $h, 'origW' => $origW, 'origH' => $origH]);
            return null;
        }
        // Hitung koordinat absolut
        $absWidth  = $w * $origW;
        $absHeight = $h * $origH;
        $absX      = ($cx - $w / 2.0) * $origW;
        $absY      = ($cy - $h / 2.0) * $origH;

        // Clamping (pastikan bbox tetap di dalam gambar)
        $clampedX = max(0.0, $absX);
        $clampedY = max(0.0, $absY);
        // Lebar/tinggi tidak bisa lebih besar dari sisa ruang gambar dari titik X/Y
        $clampedWidth  = min($absWidth, $origW - $clampedX);
        $clampedHeight = min($absHeight, $origH - $clampedY);

        // Pembulatan dan validasi akhir (harus > 0)
        $cropX      = (int) round($clampedX);
        $cropY      = (int) round($clampedY);
        $cropWidth  = (int) round($clampedWidth);
        $cropHeight = (int) round($clampedHeight);

        if ($cropWidth <= 0 || $cropHeight <= 0) {
            Log::error("Invalid calculated crop dimensions (<=0) after clamping/rounding", ['finalW' => $cropWidth, 'finalH' => $cropHeight, 'origW' => $origW, 'origH' => $origH, 'params_in' => compact('cx', 'cy', 'w', 'h')]);
            return null;
        }

        return ['x' => $cropX, 'y' => $cropY, 'w' => $cropWidth, 'h' => $cropHeight];
    }

    /** Melakukan crop pada gambar */
    private function cropImage(Imagick $img, int $x, int $y, int $w, int $h): ?Imagick
    {
        try {
            $cropped = clone $img;              // Clone agar objek asli tidak termodifikasi
            $cropped->setImagePage(0, 0, 0, 0); // Reset virtual canvas
            if (! $cropped->cropImage($w, $h, $x, $y)) {
                Log::error("cropImage returned false.", ['w' => $w, 'h' => $h, 'x' => $x, 'y' => $y]);
                $this->cleanupImagick($cropped);
                return null;
            }
            $cropped->setImagePage($cropped->getImageWidth(), $cropped->getImageHeight(), 0, 0); // Set page agar metadata ukuran benar
            if ($cropped->getImageWidth() <= 0 || $cropped->getImageHeight() <= 0) {
                Log::error("Image dimensions became invalid after crop.", ['w' => $cropped->getImageWidth(), 'h' => $cropped->getImageHeight()]);
                $this->cleanupImagick($cropped);
                return null;
            }
            Log::debug("Image cropped successfully", ['newW' => $cropped->getImageWidth(), 'newH' => $cropped->getImageHeight()]);
            return $cropped;
        } catch (ImagickException $e) {
            Log::error("ImagickException during cropImage", ['msg' => $e->getMessage(), 'code' => $e->getCode(), 'cropW' => $w, 'cropH' => $h, 'cropX' => $x, 'cropY' => $y]);
            $this->cleanupImagick($cropped ?? null);
            return null;
        }
    }

    /** Melakukan resize gambar */
    private function resizeImage(Imagick $img, int $targetSize): ?Imagick
    {
        try {
            $resized = clone $img; // Clone agar objek asli tidak termodifikasi
                                   // FILTER_LANCZOS baik untuk downscaling, FILTER_CATROM bisa jadi alternatif
                                   // Gunakan bestfit = true agar rasio aspek terjaga, lalu extent jika perlu
            if (! $resized->resizeImage($targetSize, $targetSize, Imagick::FILTER_LANCZOS, 1, true)) {
                Log::error("resizeImage returned false.", ['target' => $targetSize]);
                $this->cleanupImagick($resized);
                return null;
            }

            // Optional: Tambahkan background jika hasil resize lebih kecil dari target (jarang terjadi dengan bestfit=true)
            // $currentW = $resized->getImageWidth();
            // $currentH = $resized->getImageHeight();
            // if ($currentW < $targetSize || $currentH < $targetSize) {
            //     $resized->setBackgroundColor(new ImagickPixel('white')); // Atau warna lain
            //     $offsetX = ($targetSize - $currentW) / 2;
            //     $offsetY = ($targetSize - $currentH) / 2;
            //     $resized->extentImage($targetSize, $targetSize, -$offsetX, -$offsetY);
            // }

            if ($resized->getImageWidth() <= 0 || $resized->getImageHeight() <= 0) {
                Log::error("Image dimensions became invalid after resize.", ['w' => $resized->getImageWidth(), 'h' => $resized->getImageHeight()]);
                $this->cleanupImagick($resized);
                return null;
            }
            Log::debug("Image resized successfully", ['target' => $targetSize, 'finalW' => $resized->getImageWidth(), 'finalH' => $resized->getImageHeight()]);
            return $resized;
        } catch (ImagickException $e) {
            Log::error("ImagickException during resizeImage", ['msg' => $e->getMessage(), 'code' => $e->getCode(), 'target' => $targetSize]);
            $this->cleanupImagick($resized ?? null);
            return null;
        }
    }

    /** Mendapatkan statistik warna RGB (mean, std dev) */
    /** @return array{r_mean: float, g_mean: float, b_mean: float, r_std: float, g_std: float, b_std: float}|null */
    private function getColorStatistics(Imagick $img): ?array// $img adalah $resizedImg
    {
        try {
            if (! $this->isValidImagick($img)) { // Validasi input
                Log::error("getColorStatistics: Input Imagick object is invalid or empty BEFORE processing.");
                return null;
            }

            $targetColorspace = Imagick::COLORSPACE_SRGB;
            if ($img->getImageColorspace() !== Imagick::COLORSPACE_RGB && $img->getImageColorspace() !== $targetColorspace) {
                if (! $img->transformImageColorspace($targetColorspace)) { // Modifies $img in-place
                    Log::warning("Failed to transform colorspace to SRGB for stats. Current colorspace: " . $img->getImageColorspace());
                    // Jika gagal transform, mungkin jangan lanjutkan atau coba dapatkan stats apa adanya
                    // Untuk saat ini, jika transform gagal, kita anggap tidak bisa dapat stats RGB yang reliable
                    return null;
                }
                // Periksa validitas SETELAH transform
                if (! $this->isValidImagick($img)) {
                    Log::error("getColorStatistics: Imagick object BECAME INVALID/EMPTY AFTER SRGB transform attempt.");
                    return null;
                }
            }

            $stats = $img->getImageChannelStatistics();
            if (empty($stats)) {
                Log::error("getImageChannelStatistics returned empty array.");
                return null;
            }

            $rStats       = $stats[Imagick::CHANNEL_RED] ?? null;
            $gStats       = $stats[Imagick::CHANNEL_GREEN] ?? null;
            $bStats       = $stats[Imagick::CHANNEL_BLUE] ?? null;
            $requiredKeys = ['mean', 'standardDeviation'];

            if (
                ! $rStats || ! $gStats || ! $bStats ||
                count(array_diff($requiredKeys, array_keys($rStats))) > 0 ||
                count(array_diff($requiredKeys, array_keys($gStats))) > 0 ||
                count(array_diff($requiredKeys, array_keys($bStats))) > 0
            ) {
                Log::error("Missing required keys (mean/standardDeviation) in image statistics.", ['stats' => $stats]);
                return null;
            }

            $quantumRange = $img->getQuantumRange()['quantumRangeLong'];
            // Normalisasi ke rentang 0-255 (umum digunakan)
            $normalize = fn($value) => ($quantumRange > 0) ? ($value / $quantumRange) * 255.0 : 0.0;

            return [
                'r_mean' => $normalize($rStats['mean']),
                'g_mean' => $normalize($gStats['mean']),
                'b_mean' => $normalize($bStats['mean']),
                'r_std'  => $normalize($rStats['standardDeviation']),
                'g_std'  => $normalize($gStats['standardDeviation']),
                'b_std'  => $normalize($bStats['standardDeviation']),
            ];
        } catch (ImagickException $e) {
            Log::error("ImagickException during getColorStatistics", ['msg' => $e->getMessage(), 'code' => $e->getCode()]);
            return null;
        }
    }

    /** Menghitung fitur bentuk sederhana dari dimensi yang diberikan */
    /** @return array{aspectRatio: float, circularity: float} */
    private function calculateShapeFeatures(int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0) {
            Log::warning("Invalid dimensions for shape feature calculation", ['width' => $width, 'height' => $height]);
            return ['aspectRatio' => 1.0, 'circularity' => 0.0]; // Default values
        }
        $aspectRatio = (float) $height / $width;
        $perimeter   = 2.0 * ($width + $height);
        $area        = (float) ($width * $height);
        // Circularity (4*pi*Area / Perimeter^2). Nilai 1 sempurna lingkaran.
        $circularity = ($perimeter > 1e-6) ? (4.0 * M_PI * $area) / pow($perimeter, 2) : 0.0;

        return [
            'aspectRatio' => round($aspectRatio, 4),
            'circularity' => round($circularity, 4),
        ];
    }

    /** Menyimpan gambar intermediate untuk debugging */
    private function saveDebugImage(?Imagick $img, string $originalFilename, string $stepName): void
    {
        if (! self::DEBUG_SAVE_IMAGES || ! $img instanceof Imagick || $img->getNumberImages() === 0) {
            return;
        }
        try {
            $disk = Storage::disk(self::DEBUG_IMAGE_DISK);
            $dir  = self::DEBUG_IMAGE_PATH;
            if (! $disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }
            $debugFilename = pathinfo($originalFilename, PATHINFO_FILENAME) . "_{$stepName}.png";
            $fullPath      = $dir . '/' . $debugFilename;

            $imgToSave = clone $img;
            $imgToSave->setImageFormat('png'); // Simpan sebagai PNG

            if ($disk->put($fullPath, $imgToSave->getImageBlob())) {
                Log::debug("Debug image saved", ['path' => $fullPath]);
            } else {
                Log::warning("Failed to save debug image", ['path' => $fullPath]);
            }
            $this->cleanupImagick($imgToSave);
        } catch (Throwable $e) {
            Log::error("Error saving debug image", ['step' => $stepName, 'error' => $e->getMessage()]);
        }
    }

    /** Membersihkan objek Imagick dengan aman */
    private function cleanupImagick( ? Imagick ...$imagickObjects) : void
    {
        foreach ($imagickObjects as $img) {
            try {
                if ($img instanceof Imagick) {
                    $img->clear(); // Hapus resource internal
                                   // $img->destroy(); // Hancurkan objek (opsional, PHP GC akan handle)
                }
            } catch (ImagickException $e) {
                // Log error jika perlu, tapi jangan hentikan proses hanya karena cleanup gagal
                Log::debug("ImagickException during cleanup: " . $e->getMessage());
            }
        }
    }

    /**
     * Ekstrak fitur warna (mean dan standar deviasi untuk setiap saluran RGB)
     * @return array<float>|null Array fitur warna atau null jika gagal
     */
    private function extractColorFeatures(Imagick $img): ?array
    {
        try {
            if (! $this->isValidImagick($img)) { // Validasi input $img
                Log::error("[FeatureExtractionService] extractColorFeatures: Input Imagick is invalid.");
                return null;
            }
            $stats = $this->getColorStatistics($img); // $img (yaitu $resizedImg) diteruskan
            if (! $stats) {
                Log::error("[FeatureExtractionService] extractColorFeatures: getColorStatistics returned null.");
                return null;
            }
            $colorFeaturesList = [
                $stats['r_mean'], $stats['g_mean'], $stats['b_mean'],
                $stats['r_std'], $stats['g_std'], $stats['b_std'],
            ];
            if (count($colorFeaturesList) !== self::EXPECTED_COLOR_FEATURES_COUNT) {
                Log::critical("[FeatureExtractionService] extractColorFeatures: Mismatch in EXPECTED_COLOR_FEATURES_COUNT.", ['expected' => self::EXPECTED_COLOR_FEATURES_COUNT, 'got' => count($colorFeaturesList)]);
                return null;
            }
            return $colorFeaturesList;
        } catch (Throwable $e) {
            Log::error("[FeatureExtractionService] Color feature extraction helper failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Hitung fitur Haralick dari matriks GLCM
     * @return array{contrast: float, correlation: float, energy: float, homogeneity: float}
     */
    private function calculateHaralickFeatures(array $glcm): array
    {
        $contrast    = 0;
        $correlation = 0;
        $energy      = 0;
        $homogeneity = 0;

        // Hitung mean dan standar deviasi
        $meanI = 0;
        $meanJ = 0;
        $stdI  = 0;
        $stdJ  = 0;

        // Hitung mean
        for ($i = 0; $i < self::GLCM_LEVELS; $i++) {
            for ($j = 0; $j < self::GLCM_LEVELS; $j++) {
                $meanI += $i * $glcm[$i][$j];
                $meanJ += $j * $glcm[$i][$j];
            }
        }

        // Hitung standar deviasi
        for ($i = 0; $i < self::GLCM_LEVELS; $i++) {
            for ($j = 0; $j < self::GLCM_LEVELS; $j++) {
                $stdI += pow($i - $meanI, 2) * $glcm[$i][$j];
                $stdJ += pow($j - $meanJ, 2) * $glcm[$i][$j];
            }
        }

        $stdI = sqrt($stdI);
        $stdJ = sqrt($stdJ);

        // Hitung fitur Haralick
        for ($i = 0; $i < self::GLCM_LEVELS; $i++) {
            for ($j = 0; $j < self::GLCM_LEVELS; $j++) {
                $pij = $glcm[$i][$j];

                // Contrast (inertia)
                $contrast += $pij * pow($i - $j, 2);

                // Correlation
                if ($stdI > 0 && $stdJ > 0) {
                    $correlation += $pij * ($i - $meanI) * ($j - $meanJ) / ($stdI * $stdJ);
                }

                // Energy (angular second moment)
                $energy += $pij * $pij;

                // Homogeneity (inverse difference moment)
                $homogeneity += $pij / (1 + abs($i - $j));
            }
        }

        return [
            'contrast'    => $contrast,
            'correlation' => $correlation,
            'energy'      => $energy,
            'homogeneity' => $homogeneity,
        ];
    }

    /**
     * Extract texture features using GLCM analysis
     * @return array<float>|null Array of texture features or null if failed
     */
    private function extractTextureFeatures(Imagick $img): ?array// $img adalah $resizedImg yang valid
    {
        $grayImg = null;
        try {
            if (! $this->isValidImagick($img)) { // Validasi input $img (yaitu $resizedImg)
                Log::error("[FeatureExtractionService] extractTextureFeatures: Input Imagick (resizedImg) is invalid before cloning.");
                return null;
            }

            $grayImg = clone $img;                  // Clone objek $resizedImg yang valid
            if (! $this->isValidImagick($grayImg)) { // Validasi hasil clone
                Log::error("[FeatureExtractionService] extractTextureFeatures: Cloned grayImg is invalid.");
                $this->cleanupImagick($grayImg); // Cleanup clone jika invalid
                return null;
            }

            // Transform ke grayscale HANYA JIKA BELUM GRAYSCALE
            // Periksa colorspace $grayImg. Jika sudah GRAY, tidak perlu transform lagi.
            if ($grayImg->getImageColorspace() !== Imagick::COLORSPACE_GRAY) {
                if (! $grayImg->transformImageColorspace(Imagick::COLORSPACE_GRAY)) {
                    Log::warning("[FeatureExtractionService] extractTextureFeatures: Failed to transform cloned image to GRAY colorspace.", ['current_colorspace' => $grayImg->getImageColorspace()]);
                    $this->cleanupImagick($grayImg);
                    return null; // Jika transform gagal, anggap gagal
                }
            }
            // Periksa validitas SETELAH transform ke GRAY
            if (! $this->isValidImagick($grayImg)) {
                Log::error("[FeatureExtractionService] extractTextureFeatures: grayImg BECAME INVALID/EMPTY AFTER GRAY transform attempt.");
                $this->cleanupImagick($grayImg);
                return null;
            }

            $width  = $grayImg->getImageWidth();
            $height = $grayImg->getImageHeight();
            // $width dan $height sudah dicek oleh isValidImagick
            $pixels = $grayImg->exportImagePixels(0, 0, $width, $height, "I", Imagick::PIXEL_FLOAT);

            if (! is_array($pixels) || empty($pixels)) {
                Log::error("Failed to get grayscale pixels for texture analysis");
                $this->cleanupImagick($grayImg);
                return null;
            }

                                    // Normalize pixel values to 0-1 range
            $maxVal = max($pixels); // Pastikan $pixels tidak kosong sebelum memanggil max()
            if ($maxVal > 0) {
                $pixels = array_map(function ($p) use ($maxVal) {
                    return $p / $maxVal;
                }, $pixels);
            }

            // Convert 1D array to 2D for GLCM calculation
            $grayImage = array_chunk($pixels, $width);

            $textureFeaturesArray = [];
            foreach (self::GLCM_DISTANCES as $distance) {
                foreach (self::GLCM_ANGLES as $angle) {
                    $glcm = $this->calculateGLCM($grayImage, $distance, $angle); // $grayImage dari $pixels
                    if (! $glcm) {
                        Log::warning("[FeatureExtractionService] GLCM calculation returned null. Aborting texture extraction.", compact('distance', 'angle'));
                        $this->cleanupImagick($grayImg);
                        return null;
                    }
                    $haralickFeatures       = $this->calculateHaralickFeatures($glcm);
                    $textureFeaturesArray[] = $haralickFeatures['contrast'];
                    $textureFeaturesArray[] = $haralickFeatures['correlation'];
                    $textureFeaturesArray[] = $haralickFeatures['energy'];
                    $textureFeaturesArray[] = $haralickFeatures['homogeneity'];
                }
            }

                                                                                          // Validasi jumlah fitur tekstur
            if (count($textureFeaturesArray) !== self::EXPECTED_TEXTURE_FEATURES_COUNT) { // <-- INI KUNCI PERBAIKANNYA
                Log::error("[FeatureExtractionService] Invalid texture feature count after GLCM processing", [
                    'expected' => self::EXPECTED_TEXTURE_FEATURES_COUNT,
                    'got'      => count($textureFeaturesArray),
                ]);
                $this->cleanupImagick($grayImg); // Cleanup sebelum return null
                return null;
            }

            $this->cleanupImagick($grayImg); // Cleanup setelah sukses dan sebelum return
            return $textureFeaturesArray;

        } catch (Throwable $e) {
            Log::error("Texture feature extraction failed due to exception", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
            if (isset($grayImg) && $grayImg instanceof Imagick) { // Pastikan cleanup jika exception terjadi
                $this->cleanupImagick($grayImg);
            }
            return null;
        }
    }

    /**
     * Calculates the Gray-Level Co-occurrence Matrix (GLCM)
     * @param array<array<float>> $grayImage 2D array of grayscale values
     * @param int $distance Distance for GLCM calculation
     * @param float $angle Angle in degrees for GLCM calculation
     * @return array<array<float>>|null Normalized GLCM or null if calculation fails
     */
    private function calculateGLCM(array $grayImage, int $distance, float $angle): ?array
    {
        try {
            // Initialize GLCM
            $glcm = array_fill(0, self::GLCM_LEVELS, array_fill(0, self::GLCM_LEVELS, 0.0));

            // Calculate dx, dy based on angle and distance
            $angleRad = deg2rad($angle);
            $dx       = (int) round($distance * cos($angleRad));
            $dy       = (int) round($distance * sin($angleRad));

            $height = count($grayImage);
            $width  = count($grayImage[0]);

            // Calculate co-occurrence frequencies
            $total = 0;
            for ($i = 0; $i < $height; $i++) {
                for ($j = 0; $j < $width; $j++) {
                    $ni = $i + $dy;
                    $nj = $j + $dx;

                    if ($ni >= 0 && $ni < $height && $nj >= 0 && $nj < $width) {
                        // Convert to GLCM levels and ensure within bounds
                        $level1 = min(self::GLCM_LEVELS - 1, max(0, (int) ($grayImage[$i][$j] * (self::GLCM_LEVELS - 1))));
                        $level2 = min(self::GLCM_LEVELS - 1, max(0, (int) ($grayImage[$ni][$nj] * (self::GLCM_LEVELS - 1))));

                        $glcm[$level1][$level2]++;
                        $total++;
                    }
                }
            }

            // Normalize GLCM
            if ($total > 0) {
                for ($i = 0; $i < self::GLCM_LEVELS; $i++) {
                    for ($j = 0; $j < self::GLCM_LEVELS; $j++) {
                        $glcm[$i][$j] /= $total;
                    }
                }
            }

            return $glcm;
        } catch (Throwable $e) {
            Log::error("GLCM calculation failed", [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);
            return null;
        }
    }
} // Akhir Class FeatureExtractionService
