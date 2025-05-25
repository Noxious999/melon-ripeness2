<?php
namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FeedbackService
{
    public const S3_TRAIN_SET_DIR         = DatasetService::S3_DATASET_BASE_DIR . '/train';
    public const S3_TRAIN_ANNOTATION_CSV  = DatasetService::S3_DATASET_BASE_DIR . '/annotations/train_annotations.csv';
    public const S3_UPLOADS_TEMP_FEEDBACK = 'uploads_temp_feedback_s3'; // Tidak terpakai saat ini

    // Nama file hash dipisahkan
    private const S3_DETECTION_HASH_FILE_PATH      = 'internal_data/feedback_detection_hashes.json';
    private const S3_CLASSIFICATION_HASH_FILE_PATH = 'internal_data/feedback_classification_hashes.json';

    public function __construct()
    {
    }

    /**
     * Memperbarui atau menambahkan satu baris anotasi spesifik untuk sebuah gambar
     * dalam file CSV anotasi berdasarkan BBox yang cocok.
     * Jika tidak ada BBox yang cocok, baris baru akan ditambahkan.
     * Metode ini dirancang untuk feedback klasifikasi.
     *
     * @param string $s3CsvPath Path ke file CSV anotasi di S3.
     * @param string $imagePathForCsv Identifier gambar di CSV (e.g., 'train/namafile.jpg').
     * @param string $detectionClass Kelas deteksi (seharusnya 'melon' untuk klasifikasi).
     * @param string|null $ripenessClass Kelas kematangan baru.
     * @param array|null $bboxDataForUpdate Data BBox yang akan diupdate ['cx', 'cy', 'w', 'h'].
     * @return bool True jika berhasil, false jika gagal.
     */
    public function updateSingleAnnotationForImage(
        string $s3CsvPath,
        string $imagePathForCsv,
        string $detectionClass, // Seharusnya 'melon'
        ?string $ripenessClass,
        ?array $bboxDataForUpdate // cx, cy, w, h
    ): bool {
        Log::debug("[FeedbackService::updateSingle] Mulai update untuk: {$imagePathForCsv}, BBox: " . json_encode($bboxDataForUpdate));
        $linesToKeepOrUpdate = [];
        $headerToUse         = FeatureExtractionService::CSV_HEADER; // Asumsi header standar
        $fileExistedOnS3     = false;
        $specificBboxUpdated = false;

        try {
            if (Storage::disk('s3')->exists($s3CsvPath)) {
                $fileExistedOnS3 = true;
                $csvContent      = Storage::disk('s3')->get($s3CsvPath);

                if ($csvContent !== null && ! empty(trim($csvContent))) {
                    $allLinesFromFile   = explode("\n", trim($csvContent));
                    $headerLineFromFile = array_shift($allLinesFromFile);

                    if ($headerLineFromFile) {
                        $parsedHeader   = str_getcsv($headerLineFromFile);
                        $headerFromFile = array_map('trim', $parsedHeader);
                        // Validasi header
                        if (! empty($headerFromFile) &&
                            (count(array_diff(FeatureExtractionService::CSV_HEADER, $headerFromFile)) === 0 &&
                                count(array_diff($headerFromFile, FeatureExtractionService::CSV_HEADER)) === 0)) {
                            $headerToUse = $headerFromFile;
                        } else {
                            Log::warning("[FeedbackService::updateSingle] Header CSV di S3 tidak cocok. Menggunakan header default.", ['s3_path' => $s3CsvPath]);
                        }
                    }
                    // Tambahkan header ke output
                    $linesToKeepOrUpdate[] = implode(',', $headerToUse);

                    // Proses baris yang ada
                    foreach ($allLinesFromFile as $line) {
                        if (empty(trim($line))) {
                            continue;
                        }

                        $row = str_getcsv($line);
                        if (count($row) !== count($headerToUse)) {
                            continue;
                        }
                        // Skip baris dengan jumlah kolom salah

                        $rowData = @array_combine($headerToUse, $row);
                        if ($rowData === false) {
                            continue;
                        }

                        $filenameInRow = str_replace('\\', '/', trim($rowData['filename'] ?? ''));

                        if ($filenameInRow === $imagePathForCsv && $bboxDataForUpdate && ($rowData['detection_class'] ?? '') === 'melon') {
                            // Ini baris untuk gambar yang sama dan deteksi melon, cek BBox
                            $bboxMatch = true;
                            foreach (['bbox_cx', 'bbox_cy', 'bbox_w', 'bbox_h'] as $key => $coordKey) {
                                $valFromCsv   = round((float) ($rowData[$coordKey] ?? -1), 4); // Bulatkan untuk perbandingan
                                $valForUpdate = round((float) ($bboxDataForUpdate[array_keys($bboxDataForUpdate)[$key]] ?? -2), 4);
                                if ($valFromCsv !== $valForUpdate) {
                                    $bboxMatch = false;
                                    break;
                                }
                            }

                            if ($bboxMatch) {
                                                                               // BBox cocok! Update baris ini
                                $rowData['detection_class'] = $detectionClass; // Seharusnya tetap 'melon'
                                $rowData['ripeness_class']  = $ripenessClass ?? '';
                                // Kolom BBox sudah cocok, tidak perlu diubah nilainya, hanya kelasnya
                                $linesToKeepOrUpdate[] = $this->arrayToCsvString($this->orderRowData($rowData, $headerToUse));
                                $specificBboxUpdated   = true;
                                Log::info("[FeedbackService::updateSingle] Baris BBox spesifik diperbarui untuk {$imagePathForCsv}");
                                continue; // Lanjut ke baris berikutnya, jangan duplikat
                            }
                        }
                        // Jika filename tidak cocok, atau BBox tidak cocok, atau bukan deteksi melon, simpan baris apa adanya
                        $linesToKeepOrUpdate[] = $line;
                    }
                } else { // File kosong atau hanya header
                    $linesToKeepOrUpdate[] = implode(',', $headerToUse);
                }
            } else { // File belum ada
                $linesToKeepOrUpdate[] = implode(',', $headerToUse);
            }

            // Jika BBox spesifik tidak ditemukan (dan tidak diupdate di atas), tambahkan sebagai baris baru
            if (! $specificBboxUpdated && $bboxDataForUpdate && $detectionClass === 'melon') {
                $newRowData = [
                    'filename'        => $imagePathForCsv,
                    'set'             => 'train', // Asumsi feedback selalu untuk set train
                    'detection_class' => $detectionClass,
                    'ripeness_class'  => $ripenessClass ?? '',
                    'bbox_cx'         => (string) round((float) ($bboxDataForUpdate['cx'] ?? 0), 6),
                    'bbox_cy'         => (string) round((float) ($bboxDataForUpdate['cy'] ?? 0), 6),
                    'bbox_w'          => (string) round((float) ($bboxDataForUpdate['w'] ?? 0), 6),
                    'bbox_h'          => (string) round((float) ($bboxDataForUpdate['h'] ?? 0), 6),
                ];
                $linesToKeepOrUpdate[] = $this->arrayToCsvString($this->orderRowData($newRowData, $headerToUse));
                Log::info("[FeedbackService::updateSingle] Baris BBox spesifik baru ditambahkan untuk {$imagePathForCsv}");
            } elseif (! $bboxDataForUpdate && $detectionClass === 'non_melon' && ! $specificBboxUpdated) {
                // Kasus untuk feedback deteksi "non_melon" (dari removeAnnotationEntry jika diubah)
                $newRowData = [
                    'filename'        => $imagePathForCsv, 'set'       => 'train',
                    'detection_class' => 'non_melon', 'ripeness_class' => '',
                    'bbox_cx'         => '', 'bbox_cy'                 => '', 'bbox_w' => '', 'bbox_h' => '',
                ];
                $linesToKeepOrUpdate[] = $this->arrayToCsvString($this->orderRowData($newRowData, $headerToUse));
                Log::info("[FeedbackService::updateSingle] Baris 'non_melon' ditambahkan/diperbarui untuk {$imagePathForCsv}");
            }

            // Hapus header duplikat jika ada (misal jika file awal kosong dan kita tambah header, lalu loop juga tambah header)
            if (count($linesToKeepOrUpdate) > 1 && $linesToKeepOrUpdate[0] === $linesToKeepOrUpdate[1] && str_contains($linesToKeepOrUpdate[0], 'filename')) {
                array_shift($linesToKeepOrUpdate);
            }

            $finalCsvContent = implode("\n", $linesToKeepOrUpdate);
            if (! empty(trim($finalCsvContent)) && ! Str::endsWith($finalCsvContent, "\n")) {
                $finalCsvContent .= "\n";
            }

            if (Storage::disk('s3')->put($s3CsvPath, $finalCsvContent, 'private')) {
                Log::info("[FeedbackService::updateSingle] CSV anotasi berhasil diupdate granular di S3 untuk: {$imagePathForCsv}", ['s3_path' => $s3CsvPath]);
                return true;
            }
            Log::error("[FeedbackService::updateSingle] Gagal menulis ulang CSV anotasi ke S3 (granular).", ['s3_path' => $s3CsvPath]);
            return false;

        } catch (Throwable $e) {
            Log::error("[FeedbackService::updateSingle] Error saat update CSV anotasi granular di S3.", ['s3_path' => $s3CsvPath, 'image_id' => $imagePathForCsv, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Menghapus semua entri anotasi untuk gambar tertentu dari file CSV.
     * Berguna jika feedback deteksi diubah menjadi 'non_melon' setelah sebelumnya 'melon',
     * atau jika file gambar dihapus dari set training.
     *
     * @param string $s3CsvPath Path ke file CSV anotasi di S3.
     * @param string $imageIdentifierInCsv Identifier gambar di CSV (e.g., 'train/namafile.jpg').
     * @return bool True jika berhasil atau jika entri tidak ditemukan, false jika gagal tulis.
     */
    public function removeAnnotationEntry(string $s3CsvPath, string $imageIdentifierInCsv): bool
    {
        Log::debug("[FeedbackService::removeEntry] Mencoba menghapus entri untuk: {$imageIdentifierInCsv} dari CSV: {$s3CsvPath}");
        $linesToKeep          = [];
        $headerToUse          = FeatureExtractionService::CSV_HEADER;
        $entryFoundAndRemoved = false;

        try {
            if (! Storage::disk('s3')->exists($s3CsvPath)) {
                Log::info("[FeedbackService::removeEntry] File CSV tidak ditemukan, tidak ada yang dihapus: {$s3CsvPath}");
                return true; // Dianggap berhasil karena tidak ada yang perlu dihapus
            }

            $csvContent = Storage::disk('s3')->get($s3CsvPath);
            if ($csvContent === null || empty(trim($csvContent))) {
                Log::info("[FeedbackService::removeEntry] File CSV kosong, tidak ada yang dihapus: {$s3CsvPath}");
                return true;
            }

            $allLinesFromFile   = explode("\n", trim($csvContent));
            $headerLineFromFile = array_shift($allLinesFromFile);

            if (! $headerLineFromFile) {
                Log::warning("[FeedbackService::removeEntry] File CSV tidak memiliki header: {$s3CsvPath}");
                return false; // Ada konten tapi tidak ada header, ini aneh
            }

            $parsedHeader   = str_getcsv($headerLineFromFile);
            $headerFromFile = array_map('trim', $parsedHeader);
            if (! empty($headerFromFile) &&
                (count(array_diff(FeatureExtractionService::CSV_HEADER, $headerFromFile)) === 0 &&
                    count(array_diff($headerFromFile, FeatureExtractionService::CSV_HEADER)) === 0)) {
                $headerToUse = $headerFromFile;
            }
            $linesToKeep[] = implode(',', $headerToUse); // Selalu simpan header

            foreach ($allLinesFromFile as $line) {
                if (empty(trim($line))) {
                    continue;
                }

                $row = str_getcsv($line);
                if (count($row) !== count($headerToUse)) {
                    continue;
                }

                $rowData = @array_combine($headerToUse, $row);
                if ($rowData === false) {
                    continue;
                }

                $filenameInRow = str_replace('\\', '/', trim($rowData['filename'] ?? ''));
                if ($filenameInRow !== $imageIdentifierInCsv) {
                    $linesToKeep[] = $line; // Simpan baris jika bukan untuk gambar yang akan dihapus
                } else {
                    $entryFoundAndRemoved = true;
                    Log::debug("[FeedbackService::removeEntry] Menemukan dan akan menghapus baris untuk: {$imageIdentifierInCsv}");
                }
            }

            if (! $entryFoundAndRemoved) {
                Log::info("[FeedbackService::removeEntry] Tidak ada entri yang cocok ditemukan untuk dihapus bagi: {$imageIdentifierInCsv}");
                // Tidak perlu tulis ulang jika tidak ada yang berubah
                return true;
            }

            $finalCsvContent = implode("\n", $linesToKeep);
            if (! empty(trim($finalCsvContent)) && ! Str::endsWith($finalCsvContent, "\n")) {
                $finalCsvContent .= "\n";
            }
            // Jika setelah penghapusan hanya tersisa header, atau kosong, sesuaikan
            if (count($linesToKeep) <= 1 && trim($linesToKeep[0]) === trim(implode(',', FeatureExtractionService::CSV_HEADER))) {
                // $finalCsvContent = ""; // Kosongkan jika hanya header, atau biarkan header saja
                // Biarkan header saja untuk konsistensi
            }

            if (Storage::disk('s3')->put($s3CsvPath, $finalCsvContent, 'private')) {
                Log::info("[FeedbackService::removeEntry] Entri untuk {$imageIdentifierInCsv} berhasil dihapus (atau tidak ada) dari CSV S3: {$s3CsvPath}");
                return true;
            }
            Log::error("[FeedbackService::removeEntry] Gagal menulis ulang CSV setelah menghapus entri.", ['s3_path' => $s3CsvPath]);
            return false;

        } catch (Throwable $e) {
            Log::error("[FeedbackService::removeEntry] Error saat menghapus entri CSV.", ['s3_path' => $s3CsvPath, 'image_id' => $imageIdentifierInCsv, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // Helper untuk mengurutkan data baris sesuai urutan header
    private function orderRowData(array $rowDataArray, array $headerOrder): array
    {
        $orderedRow = [];
        foreach ($headerOrder as $headerKey) {
            $value = $rowDataArray[$headerKey] ?? '';
            // Khusus untuk kolom BBox, format sebagai string dengan 6 angka desimal
            if (in_array($headerKey, ['bbox_cx', 'bbox_cy', 'bbox_w', 'bbox_h']) && is_numeric($value)) {
                $orderedRow[] = number_format((float) $value, 6, '.', '');
            } else {
                $orderedRow[] = trim((string) $value);
            }
        }
        return $orderedRow;
    }

    // Metode updateAnnotationsForImage yang ada di file Anda bisa disederhanakan
    // atau diganti dengan pemanggilan ke updateSingleAnnotationForImage jika hanya satu baris
    // yang di-feedback. Jika feedback mengirim BANYAK baris, maka metode ini masih relevan.
    // Untuk kasus feedback saat ini (satu BBox per feedback klasifikasi), metode di bawah ini
    // perlu sedikit penyesuaian agar memanggil updateSingleAnnotationForImage atau
    // logika updateSingleAnnotationForImage di-merge ke sini.
    // Demi kemudahan, saya akan biarkan metode ini seperti yang Anda kirim,
    // TAPI Idealnya `handleClassificationFeedback` memanggil `updateSingleAnnotationForImage`
    public function updateAnnotationsForImage(
        string $s3CsvPath,
        string $imageIdentifier, // Ini adalah $imagePathForCsv (e.g., train/namafile.jpg)
        array $newAnnotationRows // Array berisi array asosiatif untuk setiap BBox/baris
    ): bool {
        Log::debug("[FeedbackService::updateAnnotationsForImage] Memulai update CSV (multiple rows): {$s3CsvPath} untuk gambar: {$imageIdentifier}");
        $linesToKeep     = [];
        $headerToUse     = FeatureExtractionService::CSV_HEADER;
        $fileExistedOnS3 = false;

        try {
            if (Storage::disk('s3')->exists($s3CsvPath)) {
                $fileExistedOnS3 = true;
                $csvContent      = Storage::disk('s3')->get($s3CsvPath);
                if ($csvContent !== null && ! empty(trim($csvContent))) {
                    $allLinesFromFile   = explode("\n", trim($csvContent));
                    $headerLineFromFile = array_shift($allLinesFromFile);
                    if ($headerLineFromFile) {
                        $parsedHeader   = str_getcsv($headerLineFromFile);
                        $headerFromFile = array_map('trim', $parsedHeader);
                        if (! empty($headerFromFile) && (count(array_diff($headerToUse, $headerFromFile)) === 0 && count(array_diff($headerFromFile, $headerToUse)) === 0)) {
                            $headerToUse = $headerFromFile;
                        } else {
                            Log::warning("[FeedbackService::updateAnnotationsForImage] Header CSV S3 tidak cocok, pakai default.", ['s3_path' => $s3CsvPath]);
                        }
                    }
                    $linesToKeep = $allLinesFromFile; // Simpan semua baris data dulu
                }
            }

            $outputLines   = [];
            $outputLines[] = implode(',', $headerToUse); // Header selalu ada

            // Hapus entri lama untuk imageIdentifier ini
            foreach ($linesToKeep as $line) {
                if (empty(trim($line))) {
                    continue;
                }

                $row = str_getcsv($line);
                if (count($row) !== count($headerToUse)) {
                    continue;
                }

                $rowData = @array_combine($headerToUse, $row);
                if ($rowData === false) {
                    continue;
                }

                $filenameInRow = str_replace('\\', '/', trim($rowData['filename'] ?? ''));
                if ($filenameInRow !== $imageIdentifier) {
                    $outputLines[] = $line;
                } else {
                    Log::debug("[FeedbackService::updateAnnotationsForImage] Menghapus entri CSV lama untuk: {$imageIdentifier}");
                }
            }

            // Tambahkan semua baris baru (yang sudah diformat) untuk imageIdentifier ini
            foreach ($newAnnotationRows as $newRowDataArray) {
                $orderedValues = $this->orderRowData($newRowDataArray, $headerToUse);
                $outputLines[] = $this->arrayToCsvString($orderedValues);
            }

            // Hapus header duplikat jika ada (jika file awalnya kosong atau hanya header)
            if (count($outputLines) > 1 && $outputLines[0] === $outputLines[1] && str_contains($outputLines[0], 'filename')) {
                array_shift($outputLines);
            }

            $finalCsvContent = implode("\n", $outputLines);
            if (! empty(trim($finalCsvContent)) && ! Str::endsWith($finalCsvContent, "\n")) {
                $finalCsvContent .= "\n";
            }

            if (Storage::disk('s3')->put($s3CsvPath, $finalCsvContent, 'private')) {
                Log::info("[FeedbackService::updateAnnotationsForImage] CSV anotasi berhasil diupdate (multi-row) di S3 untuk: {$imageIdentifier}", ['s3_path' => $s3CsvPath]);
                return true;
            }
            Log::error("[FeedbackService::updateAnnotationsForImage] Gagal menulis ulang CSV anotasi (multi-row) ke S3.", ['s3_path' => $s3CsvPath]);
            return false;

        } catch (Throwable $e) {
            Log::error("[FeedbackService::updateAnnotationsForImage] Error saat update CSV anotasi (multi-row) di S3.", ['s3_path' => $s3CsvPath, 'image_id' => $imageIdentifier, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // Metode updateAnnotationCsv Anda yang ada (digunakan oleh handleDetectionFeedback untuk kasus non_melon)
    // Sebaiknya ini juga memanggil updateSingleAnnotationForImage atau updateAnnotationsForImage
    // untuk konsistensi. Saya akan sesuaikan agar memanggil updateSingleAnnotationForImage.
    public function updateAnnotationCsv(
        string $s3CsvPath,
        string $imagePathForCsv,
        string $datasetSet,               // 'train', 'valid', atau 'test'
        string $newDetectionClass,        // 'melon' atau 'non_melon'
        ?string $newRipenessClass = null, // 'ripe' atau 'unripe', atau null jika non_melon
        ?string $newBboxString = null     // string "cx,cy,w,h" atau null
    ): bool {
        // Untuk feedback 'non_melon', $newRipenessClass dan $newBboxString akan null
        // Untuk feedback 'melon' yang AWAITING_BBOX, kita tidak update CSV di sini,
        // tapi jika Anda ingin update juga, Anda bisa tambahkan logikanya.
        // Metode ini sekarang lebih cocok untuk 'non_melon' atau BBox tunggal yang diketahui.

        if ($newDetectionClass === 'non_melon') {
            return $this->updateSingleAnnotationForImage($s3CsvPath, $imagePathForCsv, 'non_melon', null, null);
        } elseif ($newDetectionClass === 'melon' && $newBboxString && $newRipenessClass) {
            $bboxData = $this->parseBboxString($newBboxString);
            if ($bboxData) {
                return $this->updateSingleAnnotationForImage($s3CsvPath, $imagePathForCsv, 'melon', $newRipenessClass, $bboxData);
            }
            Log::warning("[FeedbackService::updateAnnotationCsv] Format BBox string tidak valid untuk update.", ['bbox_string' => $newBboxString]);
            return false;
        }
        // Jika $newDetectionClass adalah 'melon' tapi tidak ada BBox/Ripeness, mungkin tidak ada aksi CSV di sini
        // atau Anda perlu logika khusus. Untuk sekarang, anggap hanya kasus di atas yang relevan.
        Log::info("[FeedbackService::updateAnnotationCsv] Kondisi tidak memenuhi syarat untuk update CSV (misal, melon tanpa BBox).", compact('newDetectionClass', 'newRipenessClass', 'newBboxString'));
        return true; // Dianggap sukses jika tidak ada aksi yang perlu dilakukan
    }

    private function parseBboxString(?string $bboxString): ?array
    {
        if ($bboxString === null) {
            return null;
        }

        $parts = array_map('trim', explode(',', $bboxString));
        if (count($parts) === 4 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2]) && is_numeric($parts[3])) {
            return ['cx' => $parts[0], 'cy' => $parts[1], 'w' => $parts[2], 'h' => $parts[3]];
        }
        return null;
    }

    private function arrayToCsvString(array $fields, string $delimiter = ',', string $enclosure = '"', string $escape_char = "\\"): string
    {
        $f = fopen('php://memory', 'r+');
        if (fputcsv($f, $fields, $delimiter, $enclosure, $escape_char) === false) {
            fclose($f);return '';
        }
        rewind($f);
        $csv_line = stream_get_contents($f);
        fclose($f);
        return rtrim($csv_line, "\n");
    }

    public function checkIfFeedbackExists(string $fileHash): bool
    {
        $detectionHashes = $this->loadFeedbackHashes('detection');
        if (isset($detectionHashes[$fileHash])) {
            return true;
        }

        $classificationHashes = $this->loadFeedbackHashes('classification');
        foreach ($classificationHashes as $identifier => $details) {
            if (Str::startsWith($identifier, $fileHash . '_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Memuat array hash feedback dari file JSON di S3 berdasarkan tipe.
     *
     * @param string $feedbackType 'detection' atau 'classification'.
     * @return array<string, string> Array hash feedback.
     */
    public function loadFeedbackHashes(string $feedbackType): array
    {
        $s3FilePath = ($feedbackType === 'detection')
        ? self::S3_DETECTION_HASH_FILE_PATH
        : self::S3_CLASSIFICATION_HASH_FILE_PATH;

        Log::debug("[FeedbackService] Mencoba memuat feedback hashes tipe '{$feedbackType}' dari S3: {$s3FilePath}");
        try {
            if (Storage::disk('s3')->exists($s3FilePath)) {
                $jsonContent = Storage::disk('s3')->get($s3FilePath);
                if ($jsonContent !== null && ! empty(trim($jsonContent))) {
                    $hashes = json_decode($jsonContent, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($hashes)) {
                        Log::info("[FeedbackService] Feedback hashes tipe '{$feedbackType}' berhasil dimuat dari S3.", ['count' => count($hashes)]);
                        return $hashes;
                    }
                    Log::warning("[FeedbackService] Gagal decode JSON feedback hashes tipe '{$feedbackType}'.", ['s3_path' => $s3FilePath, 'json_error' => json_last_error_msg()]);
                } else {
                    Log::info("[FeedbackService] File feedback hashes tipe '{$feedbackType}' S3 kosong atau gagal dibaca.", ['s3_path' => $s3FilePath]);
                }
            } else {
                Log::info("[FeedbackService] File feedback hashes tipe '{$feedbackType}' tidak ditemukan di S3.", ['s3_path' => $s3FilePath]);
            }
        } catch (Throwable $e) {
            Log::error("[FeedbackService] Error membaca feedback hashes tipe '{$feedbackType}'.", ['s3_path' => $s3FilePath, 'error' => $e->getMessage()]);
        }
        return [];
    }

    /**
     * Menyimpan array hash feedback ke file JSON di S3 berdasarkan tipe.
     *
     * @param array<string, string> $hashes Array hash feedback yang akan disimpan.
     * @param string $feedbackType 'detection' atau 'classification'.
     */
    public function saveFeedbackHashes(array $hashes, string $feedbackType): void
    {
        $s3FilePath = ($feedbackType === 'detection')
        ? self::S3_DETECTION_HASH_FILE_PATH
        : self::S3_CLASSIFICATION_HASH_FILE_PATH;

        Log::debug("[FeedbackService] Mencoba menyimpan feedback hashes tipe '{$feedbackType}' ke S3: {$s3FilePath}");
        try {
            $jsonContent = json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) {
                Log::error("[FeedbackService] Gagal encode JSON untuk feedback hashes tipe '{$feedbackType}'.");
                return;
            }
            if (! Storage::disk('s3')->put($s3FilePath, $jsonContent, 'private')) {
                Log::error("[FeedbackService] Gagal menulis feedback hashes tipe '{$feedbackType}' ke S3.", ['s3_path' => $s3FilePath]);
            } else {
                Log::info("[FeedbackService] Feedback hashes tipe '{$feedbackType}' berhasil disimpan ke S3.", ['s3_path' => $s3FilePath, 'count' => count($hashes)]);
            }
        } catch (Throwable $e) {
            Log::error("[FeedbackService] Exception saat menyimpan feedback hashes tipe '{$feedbackType}'.", ['s3_path' => $s3FilePath, 'error' => $e->getMessage()]);
        }
    }
}
