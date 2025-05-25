<?php
namespace App\Persisters;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class S3ObjectPersister// Nama kelas diubah

{
    protected string $s3Path; // Path relatif di dalam bucket S3

    /**
     * @param string $s3PathInBucket Path relatif objek di dalam bucket S3 (misal: "models/nama_model.model").
     */
    public function __construct(string $s3PathInBucket)
    {
        $this->s3Path = $s3PathInBucket;
    }

    /**
     * Menyimpan (serialize) objek ke S3.
     * @param object $estimator Objek yang akan disimpan.
     * @param string $visibility Visibility S3 ('private' atau 'public'). Default 'private'.
     * @throws RuntimeException Jika gagal menulis ke S3.
     */
    public function save(object $estimator, string $visibility = 'public'): void// Tambah parameter visibility
    {
        try {
            $serializedData = serialize($estimator);
            // Tidak perlu membuat direktori, S3 menangani prefix secara otomatis.
            if (! Storage::disk('s3')->put($this->s3Path, $serializedData, $visibility)) {
                Log::error("Gagal menulis objek ke S3", ['s3_path' => $this->s3Path]);
                throw new RuntimeException("Gagal menulis objek ke S3: {$this->s3Path}");
            }
            Log::info("Objek berhasil disimpan ke S3", ['s3_path' => $this->s3Path, 'visibility' => $visibility]);
        } catch (Throwable $e) {
            Log::error("Exception saat menyimpan objek ke S3", ['s3_path' => $this->s3Path, 'error' => $e->getMessage()]);
            // Lempar ulang agar pemanggil tahu ada masalah
            throw new RuntimeException("Exception saat menyimpan objek ke S3: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Memuat (unserialize) objek dari S3.
     * @return object Objek yang dimuat.
     * @throws RuntimeException Jika file tidak ditemukan di S3, gagal dibaca, atau hasil unserialize bukan objek.
     */
    public function load(): object
    {
        try {
            if (! Storage::disk('s3')->exists($this->s3Path)) {
                Log::error("Objek tidak ditemukan di S3 untuk dimuat", ['s3_path' => $this->s3Path]);
                throw new RuntimeException("Objek tidak ditemukan di S3: {$this->s3Path}");
            }

            $content = Storage::disk('s3')->get($this->s3Path);

            if ($content === null) { // Storage::get() mengembalikan null jika file tidak ada atau error baca
                Log::error("Gagal membaca konten objek dari S3 (null returned)", ['s3_path' => $this->s3Path]);
                throw new RuntimeException("Gagal membaca konten objek dari S3: {$this->s3Path}");
            }
            if (empty($content)) { // Cek jika kontennya string kosong
                Log::error("File objek S3 kosong", ['s3_path' => $this->s3Path]);
                throw new RuntimeException("File objek S3 kosong: {$this->s3Path}");
            }

            $loadedObject = @unserialize($content);

            if ($loadedObject === false && $content !== serialize(false)) { // Perbandingan yang lebih aman untuk unserialize(false)
                Log::error("Gagal melakukan unserialize pada objek dari S3", [
                    's3_path'       => $this->s3Path,
                    'content_start' => substr($content, 0, 100), // Log sampel konten jika korup
                ]);
                throw new RuntimeException("Gagal melakukan unserialize pada objek S3: {$this->s3Path}. Konten mungkin korup.");
            }
            if (! is_object($loadedObject)) {
                Log::error("Hasil unserialize dari S3 bukanlah objek", ['s3_path' => $this->s3Path, 'type' => gettype($loadedObject)]);
                throw new RuntimeException("Hasil unserialize dari S3 bukanlah objek: {$this->s3Path}");
            }

            Log::info("Objek berhasil dimuat dari S3", ['s3_path' => $this->s3Path, 'class' => get_class($loadedObject)]);
            return $loadedObject;
        } catch (Throwable $e) {
            Log::error("Exception saat memuat objek dari S3", ['s3_path' => $this->s3Path, 'error' => $e->getMessage()]);
            throw new RuntimeException("Exception saat memuat objek dari S3: " . $e->getMessage(), 0, $e);
        }
    }
}
