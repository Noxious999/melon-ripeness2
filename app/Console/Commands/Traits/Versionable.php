<?php
namespace App\Console\Commands\Traits;

use Illuminate\Support\Facades\Log; // Opsional: untuk logging jika JSON decode gagal
use Illuminate\Support\Facades\Storage;
use Throwable;

trait Versionable
{
    /**
     * Mendapatkan nomor versi model berikutnya berdasarkan file metadata.
     * Jika file tidak ada atau key 'version' tidak ada, mulai dari 1.
     *
     * @param string $metaPath Path lengkap ke file metadata JSON.
     * @return int Nomor versi berikutnya.
     */
    protected function getNextModelVersion(string $metaPath): int
    {
        $currentVersion = 0; // Default ke 0 jika file tidak ada atau invalid

        if (Storage::disk('s3')->exists($metaPath)) {
            try {
                $content = Storage::disk('s3')->get($metaPath);
                if ($content !== null && ! empty($content)) { // Periksa null dan tidak kosong
                    $meta = json_decode($content, true);
                    // Cek jika decode berhasil dan hasilnya array
                    if (json_last_error() === JSON_ERROR_NONE && is_array($meta)) {
                        // Ambil versi saat ini, default 0 jika key tidak ada atau bukan integer
                        $currentVersion = isset($meta['version']) && is_int($meta['version']) ? $meta['version'] : 0;
                    } else {
                        Log::warning("Gagal decode JSON atau format tidak valid saat mengambil versi model.", ['path' => $metaPath, 'json_error' => json_last_error_msg()]);
                    }
                } else {
                    Log::info("File metadata S3 kosong atau gagal dibaca, memulai versi dari 0.", ['s3_path' => $metaPath]);
                }
            } catch (Throwable $e) {
                Log::error("Gagal membaca file metadata saat mengambil versi model.", ['path' => $metaPath, 'error' => $e->getMessage()]);
                // Biarkan currentVersion tetap 0 jika gagal baca file
            }
        }

        // Kembalikan versi berikutnya
        return $currentVersion + 1;
    }
}
