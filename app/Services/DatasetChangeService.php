<?php
namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str; // Ditambahkan
use Throwable;

class DatasetChangeService
{
    private const CHANGE_LOG_S3_PATH            = 'internal_data/dataset_change_log.json'; // Untuk histori audit
    private const CACHE_KEY_UNSEEN_CHANGES      = 'dataset_has_unseen_changes_flag';
    private const CACHE_KEY_LAST_CHANGE_SUMMARY = 'dataset_last_change_summary';
    private const CACHE_DURATION                = 10080; // 7 hari untuk flag dan ringkasan

    public function recordChange(string $changeType, string $entityIdentifier, int $itemsAffected = 1, array $additionalInfo = []): void
    {
        Log::info("[DatasetChangeService] Recording dataset change.", compact('changeType', 'entityIdentifier', 'itemsAffected', 'additionalInfo'));
        try {
            $timestamp = Carbon::now();
            $logEntry  = [
                'timestamp_iso'   => $timestamp->toIso8601String(),
                'timestamp_human' => $timestamp->isoFormat('D MMM YYYY, HH:mm:ss'),
                'type'            => $changeType,
                'identifier'      => Str::limit($entityIdentifier, 100),
                'items_affected'  => $itemsAffected,
                'details'         => $additionalInfo,
            ];

            $changeLogs = [];
            if (Storage::disk('s3')->exists(self::CHANGE_LOG_S3_PATH)) {
                $content = Storage::disk('s3')->get(self::CHANGE_LOG_S3_PATH);
                if ($content) {
                    $decoded = json_decode($content, true);
                    if (is_array($decoded)) {
                        $changeLogs = $decoded;
                    }
                }
            }

            array_unshift($changeLogs, $logEntry);
            if (count($changeLogs) > 50) { // Simpan 50 log terakhir
                $changeLogs = array_slice($changeLogs, 0, 50);
            }

            Storage::disk('s3')->put(self::CHANGE_LOG_S3_PATH, json_encode($changeLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            Cache::put(self::CACHE_KEY_UNSEEN_CHANGES, true, now()->addMinutes(self::CACHE_DURATION));
            Cache::put(self::CACHE_KEY_LAST_CHANGE_SUMMARY, [
                'type_display'       => Str::title(str_replace('_', ' ', $changeType)),
                'count'              => $itemsAffected,
                'identifier_display' => Str::limit($entityIdentifier, 50),
                'time_ago'           => $timestamp->diffForHumans(),
                'time_exact'         => $timestamp->isoFormat('D MMM, HH:mm'),
            ], now()->addMinutes(self::CACHE_DURATION));

        } catch (Throwable $e) {
            Log::error("[DatasetChangeService] Failed to record dataset change.", [
                'error' => $e->getMessage(), 'changeType' => $changeType, 'identifier' => $entityIdentifier,
            ]);
        }
    }

    public function getUnseenChangesNotificationData(): array
    {
        $hasChanges = Cache::get(self::CACHE_KEY_UNSEEN_CHANGES, false);
        $summary    = null;

        if ($hasChanges) {
            $summary = Cache::get(self::CACHE_KEY_LAST_CHANGE_SUMMARY);
            if (! $summary) {
                $summary = [
                    'type_display'       => 'Dataset',
                    'time_ago'           => 'Baru saja terdeteksi',
                    'identifier_display' => 'Beberapa data',
                    'count'              => 'beberapa',
                ];
            }
        }
        return [
            'show_notification' => (bool) $hasChanges,
            'summary'           => $summary,
        ];
    }

    public function markChangesAsSeen(): void
    {
        Cache::forget(self::CACHE_KEY_UNSEEN_CHANGES);
        Cache::forget(self::CACHE_KEY_LAST_CHANGE_SUMMARY);
        Log::info("[DatasetChangeService] Notifikasi perubahan dataset ditandai sudah dilihat.");
    }

    public function recordLastActionPerformed(string $actionKey, array $summaryData): void
    {
        $cacheKey    = 'last_action_' . Str::slug($actionKey);
        $dataToStore = array_merge($summaryData, ['performed_at' => Carbon::now()->toIso8601String()]);
        Cache::put($cacheKey, $dataToStore, now()->addDays(30));
        Log::info("[DatasetChangeService] Last action recorded.", ['action' => $actionKey, 'summary' => $summaryData]);
    }

    public function getLastActionPerformed(string $actionKey): ?array
    {
        $cacheKey   = 'last_action_' . Str::slug($actionKey);
        $lastAction = Cache::get($cacheKey);
        if ($lastAction && isset($lastAction['performed_at'])) {
            $lastAction['performed_at_human'] = Carbon::parse($lastAction['performed_at'])->isoFormat('D MMM YYYY, HH:mm');
            return $lastAction;
        }
        return null;
    }
}
