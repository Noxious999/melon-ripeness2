<?php

// File: app/Console/Commands/ExtractFeaturesCommand.php

namespace App\Console\Commands;

use App\Services\AnnotationService;
use App\Services\DatasetService;
use App\Services\FeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use SplFileObject;
use Throwable;

class ExtractFeaturesCommand extends Command
{
    protected $signature = 'dataset:extract-features
                            {--type=all : Feature type to extract (classifier, detector, all)}
                            {--set=all : Dataset set to process (train, valid, test, all)}
                            {--incremental : Only process annotations/images missing from feature files (default: false, re-extract all)}';

    protected $description = 'Pre-compute features based on annotation CSVs. Processes unique images/annotations.';

    private const FEATURE_CLS_ID_COL_INDEX = 0;
    private const FEATURE_DET_ID_COL_INDEX = 0;

    public function __construct(protected FeatureExtractionService $featureExtractor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $typeOption    = strtolower($this->option('type'));
        $setOption     = strtolower($this->option('set'));
        $isIncremental = $this->option('incremental');

        if (! in_array($typeOption, ['all', 'classifier', 'detector'])) {
            $this->error("Invalid type specified: {$typeOption}. Use 'classifier', 'detector', or 'all'.");
            return self::FAILURE;
        }

        $mode = $isIncremental ? 'Incremental (append new/missing)' : 'Full (overwrite all)';
        $this->line("EVENT_STATUS: START, Memulai Ekstraksi Fitur ({$mode})...");
        $this->info("🚀 Starting Feature Pre-computation ({$mode})");
        $this->line("Type: <fg=cyan>{$typeOption}</>, Set: <fg=cyan>{$setOption}</>");

        $setsToProcess = ($setOption === 'all') ? DatasetService::DATASET_SETS : [$setOption];
        if (! empty(array_diff($setsToProcess, DatasetService::DATASET_SETS))) {
            $invalidSets = implode(', ', array_diff($setsToProcess, DatasetService::DATASET_SETS));
            $this->error("Invalid set(s) specified: {$invalidSets}. Use 'train', 'valid', 'test', or 'all'.");
            $this->line("EVENT_STATUS: ERROR, Invalid set(s) specified: {$invalidSets}.");
            return self::FAILURE;
        }

        $startTime                      = microtime(true);
        $totalFeaturesWrittenClassifier = 0;
        $totalFeaturesWrittenDetector   = 0;
        $totalSkippedClassifier         = 0;
        $totalSkippedDetector           = 0;
        $totalErrors                    = 0;
        $totalImagesProcessedOverall    = 0;

        foreach ($setsToProcess as $set) {
            $this->info("\n--- Processing Set: <fg=yellow>{$set}</> ---");
            $this->line("EVENT_LOG: Processing Set: {$set}...");
            $result = $this->processSetForFeatures($set, $typeOption, $isIncremental);

            if ($result !== null) {
                $totalImagesProcessedOverall += $result['images_processed'];
                $totalFeaturesWrittenClassifier += $result['written_classifier_rows'];
                $totalFeaturesWrittenDetector += $result['written_detector_rows'];
                $totalSkippedClassifier += $result['skipped_classifier'];
                $totalSkippedDetector += $result['skipped_detector'];
                $totalErrors += $result['errors'];
            } else {
                $this->error("Processing failed critically for set '{$set}'. Check logs.");
                $this->line("EVENT_LOG: ERROR - Processing failed critically for set '{$set}'.");
                $totalErrors++;
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $this->info("\n✅ Feature Pre-computation Finished! ({$mode})");
        $this->line("Total Unique Images Checked: <fg=blue>{$totalImagesProcessedOverall}</>");
        $this->line("Classifier Features Written/Appended: <fg=green>{$totalFeaturesWrittenClassifier}</> (Skipped Existing: {$totalSkippedClassifier})");
        $this->line("Detector Features Written/Appended: <fg=green>{$totalFeaturesWrittenDetector}</> (Skipped Existing: {$totalSkippedDetector})");
        $this->line("Total Errors/Skipped Items (Extraction): <fg=red>{$totalErrors}</>");
        $this->line("Duration: {$duration} seconds");

        $finalMessage = $totalErrors > 0
        ? "Ekstraksi Fitur selesai dengan {$totalErrors} error. Durasi: {$duration}s."
        : "Ekstraksi Fitur selesai. Durasi: {$duration}s.";
        $this->line("EVENT_STATUS: DONE, " . $finalMessage);

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processSetForFeatures(string $set, string $type, bool $incremental): ?array
    {
        $s3AnnotationCsvPath        = AnnotationService::S3_ANNOTATION_DIR . '/' . $set . '_annotations.csv';
        $s3FeatureCsvPathClassifier = FeatureExtractionService::S3_FEATURE_DIR . '/' . $set . '_classifier_features.csv';
        $s3FeatureCsvPathDetector   = FeatureExtractionService::S3_FEATURE_DIR . '/' . $set . '_detector_features.csv';

        if (! Storage::disk('s3')->exists($s3AnnotationCsvPath)) {
            $this->warn("Annotation CSV not found for set '{$set}'. Skipping.");
            $this->line("EVENT_LOG: WARNING - Annotation CSV for set '{$set}' not found. Skipping set.");
            return ['images_processed' => 0, 'written_classifier_rows' => 0, 'written_detector_rows' => 0, 'skipped_classifier' => 0, 'skipped_detector' => 0, 'errors' => 1];
        }

        $annotationsByImage = $this->groupAnnotationsByImage($s3AnnotationCsvPath);
        if ($annotationsByImage === null) {
            $this->error("Failed to read or parse annotation file: {$s3AnnotationCsvPath}");
            $this->line("EVENT_LOG: ERROR - Failed to parse annotation file: {$s3AnnotationCsvPath}");
            return null;
        }
        if (empty($annotationsByImage)) {
            $this->warn("No valid annotation data found in {$s3AnnotationCsvPath}.");
            $this->line("EVENT_LOG: WARNING - No valid annotations in {$s3AnnotationCsvPath}.");
            return ['images_processed' => 0, 'written_classifier_rows' => 0, 'written_detector_rows' => 0, 'skipped_classifier' => 0, 'skipped_detector' => 0, 'errors' => 0];
        }
        $uniqueImageCount = count($annotationsByImage);
        $this->info("Found {$uniqueImageCount} unique images with annotations in '{$set}' set.");
        $this->line("EVENT_LOG: Found {$uniqueImageCount} unique images in '{$set}'.");

        $existingClassifierKeys = [];
        $existingDetectorKeys   = [];
        $classifierS3FileExists = Storage::disk('s3')->exists($s3FeatureCsvPathClassifier);
        $detectorS3FileExists   = Storage::disk('s3')->exists($s3FeatureCsvPathDetector);

        if ($incremental) {
            if (($type === 'classifier' || $type === 'all') && $classifierS3FileExists) {
                $existingClassifierKeys = $this->loadExistingFeatureKeys($s3FeatureCsvPathClassifier, self::FEATURE_CLS_ID_COL_INDEX);
                $this->line("Loaded " . count($existingClassifierKeys) . " existing classifier feature keys for incremental mode.");
            }
            if (($type === 'detector' || $type === 'all') && $detectorS3FileExists) {
                $existingDetectorKeys = $this->loadExistingFeatureKeys($s3FeatureCsvPathDetector, self::FEATURE_DET_ID_COL_INDEX);
                $this->line("Loaded " . count($existingDetectorKeys) . " existing detector feature keys for incremental mode.");
            }
        }

        $localTempClassifierCsvPath = null;
        $outputHandleClassifier     = null;
        $localTempDetectorCsvPath   = null;
        $outputHandleDetector       = null;
        $writtenClassifierRows      = 0;
        $writtenDetectorRows        = 0;
        $skippedClassifier          = 0;
        $skippedDetector            = 0;
        $errors                     = 0;
        $imagesProcessedInThisSet   = 0;

        try {
            if ($type === 'classifier' || $type === 'all') {
                $localTempClassifierCsvPath = tempnam(sys_get_temp_dir(), "cls_feat_temp_s3_");
                if (! $localTempClassifierCsvPath) {throw new RuntimeException("Gagal membuat file temporary classifier.");}
                $writeHeaderCls = true;
                if ($incremental && $classifierS3FileExists) {
                    $s3Content = Storage::disk('s3')->get($s3FeatureCsvPathClassifier);
                    if ($s3Content !== null && ! empty(trim($s3Content))) {
                        file_put_contents($localTempClassifierCsvPath, $s3Content);
                        $writeHeaderCls = false;
                        $this->line("Appending to existing classifier features from S3: {$s3FeatureCsvPathClassifier}");
                    } else { $this->line("Classifier S3 feature file is empty or unreadable, creating new: {$s3FeatureCsvPathClassifier}");}
                }
                $outputHandleClassifier = new SplFileObject($localTempClassifierCsvPath, $writeHeaderCls ? 'w' : 'a');
                $outputHandleClassifier->setCsvControl(',');
                if ($writeHeaderCls) {
                    $headerCls = ['annotation_id', 'label'];
                    for ($i = 1; $i <= FeatureExtractionService::CLASSIFIER_FEATURE_COUNT; $i++) {$headerCls[] = "cf{$i}";}
                    $outputHandleClassifier->fputcsv($headerCls);
                    $this->line("Writing new classifier features to temp: " . $localTempClassifierCsvPath);
                }
            }

            if ($type === 'detector' || $type === 'all') {
                $localTempDetectorCsvPath = tempnam(sys_get_temp_dir(), "det_feat_temp_s3_");
                if (! $localTempDetectorCsvPath) {throw new RuntimeException("Gagal membuat file temporary detector.");}
                $writeHeaderDet = true;
                if ($incremental && $detectorS3FileExists) {
                    $s3Content = Storage::disk('s3')->get($s3FeatureCsvPathDetector);
                    if ($s3Content !== null && ! empty(trim($s3Content))) {
                        file_put_contents($localTempDetectorCsvPath, $s3Content);
                        $writeHeaderDet = false;
                        $this->line("Appending to existing detector features from S3: {$s3FeatureCsvPathDetector}");
                    } else { $this->line("Detector S3 feature file is empty or unreadable, creating new: {$s3FeatureCsvPathDetector}");}
                }
                $outputHandleDetector = new SplFileObject($localTempDetectorCsvPath, $writeHeaderDet ? 'w' : 'a');
                $outputHandleDetector->setCsvControl(',');
                if ($writeHeaderDet) {
                    $headerDet = ['filename', 'label'];
                    for ($i = 1; $i <= FeatureExtractionService::DETECTOR_FEATURE_COUNT; $i++) {$headerDet[] = "df{$i}";}
                    $outputHandleDetector->fputcsv($headerDet);
                    $this->line("Writing new detector features to temp: " . $localTempDetectorCsvPath);
                }
            }

            $bar = $this->output->createProgressBar($uniqueImageCount);
            $bar->setFormat(" {$set} Images [%bar%] %percent:3s%% (%current%/%max%) | Err:%errors% | SkipCls:%skip_cls% | SkipDet:%skip_det% | Mem:%memory:6s%");
            $bar->start();
            $processedItemCountForProgress = 0;

            foreach ($annotationsByImage as $imageRelativePath => $annotations) {
                $imagesProcessedInThisSet++;
                $processedItemCountForProgress++;
                $bar->advance();
                $bar->setMessage((string) $errors, 'errors');
                $bar->setMessage((string) $skippedClassifier, 'skip_cls');
                $bar->setMessage((string) $skippedDetector, 'skip_det');

                $firstAnnotation  = reset($annotations);
                $imagePathFromCsv = $imageRelativePath;
                $baseDatasetDir   = DatasetService::S3_DATASET_BASE_DIR;
                if (Str::startsWith($imagePathFromCsv, $baseDatasetDir . '/')) {
                    $imagePathCleaned = Str::after($imagePathFromCsv, $baseDatasetDir . '/');
                } else {
                    $imagePathCleaned = $imagePathFromCsv;
                }
                $s3ImageToProcess      = $baseDatasetDir . '/' . $imagePathCleaned;
                $s3ImageToProcess      = preg_replace('#/+#', '/', $s3ImageToProcess);
                $overallDetectionClass = strtolower(trim($firstAnnotation['detection_class'] ?? 'unknown'));

                if (! Storage::disk('s3')->exists($s3ImageToProcess)) {
                    Log::warning("Image file not found on S3, skipping.", ['s3_path' => $s3ImageToProcess]);
                    $this->line("EVENT_LOG: Image not found: {$s3ImageToProcess} (Skipping)");
                    $errors++;
                    continue;
                }

                try {
                    if ($outputHandleDetector) {
                        $detectorKey   = str_replace('\\', '/', $imageRelativePath);
                        $detectorLabel = in_array($overallDetectionClass, ['melon', 'non_melon']) ? $overallDetectionClass : null;
                        if ($detectorLabel !== null) {
                            if ($incremental && isset($existingDetectorKeys[$detectorKey])) {
                                $skippedDetector++;
                            } else {
                                $detectorFeatures = $this->featureExtractor->extractDetectionFeatures($s3ImageToProcess);
                                if ($detectorFeatures && count($detectorFeatures) === FeatureExtractionService::DETECTOR_FEATURE_COUNT) {
                                    $outputHandleDetector->fputcsv(array_merge([$imageRelativePath, $detectorLabel], $detectorFeatures));
                                    $writtenDetectorRows++;
                                } else {
                                    Log::warning("Failed to extract detector features or count mismatch.", ['image' => $imageRelativePath]);
                                    $errors++;
                                }
                            }
                        }
                    }
                    if ($outputHandleClassifier && $overallDetectionClass === 'melon') {
                        $bboxIndex = 0;
                        foreach ($annotations as $annotationRow) {
                            $bboxIndex++;
                            $ripenessClass = strtolower(trim($annotationRow['ripeness_class'] ?? ''));
                            $cx            = filter_var($annotationRow['bbox_cx'] ?? null, FILTER_VALIDATE_FLOAT);
                            $cy            = filter_var($annotationRow['bbox_cy'] ?? null, FILTER_VALIDATE_FLOAT);
                            $w             = filter_var($annotationRow['bbox_w'] ?? null, FILTER_VALIDATE_FLOAT);
                            $h             = filter_var($annotationRow['bbox_h'] ?? null, FILTER_VALIDATE_FLOAT);
                            if (in_array($ripenessClass, ['ripe', 'unripe']) && $cx !== false && $cy !== false && $w !== false && $h !== false && $w > 0 && $h > 0) {
                                $annotationId = pathinfo($imageRelativePath, PATHINFO_FILENAME) . "_bbox" . $bboxIndex;
                                if ($incremental && isset($existingClassifierKeys[$annotationId])) {
                                    $skippedClassifier++;
                                } else {
                                    $bboxRel            = ['cx' => $cx, 'cy' => $cy, 'w' => $w, 'h' => $h];
                                    $classifierFeatures = $this->featureExtractor->extractColorFeaturesFromBbox($s3ImageToProcess, $bboxRel);
                                    if ($classifierFeatures && count($classifierFeatures) === FeatureExtractionService::CLASSIFIER_FEATURE_COUNT) {
                                        $outputHandleClassifier->fputcsv(array_merge([$annotationId, $ripenessClass], $classifierFeatures));
                                        $writtenClassifierRows++;
                                    } else {
                                        Log::warning("Failed to extract classifier features or count mismatch.", ['image' => $imageRelativePath, 'bbox_idx' => $bboxIndex]);
                                        $errors++;
                                    }
                                }
                            } else {
                                Log::warning("Skipping invalid bbox/ripeness for classifier features.", ['image' => $imageRelativePath, 'bbox_idx' => $bboxIndex]);
                                $errors++;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Log::error("Error processing image for feature extraction", ['image' => $s3ImageToProcess, 'error' => $e->getMessage()]);
                    $this->line("EVENT_LOG: ERROR processing {$s3ImageToProcess}: " . Str::limit($e->getMessage(), 50));
                    $errors++;
                }
                if ($processedItemCountForProgress % 5 == 0 || $processedItemCountForProgress == $uniqueImageCount) {
                    $progressPercentage = ($uniqueImageCount > 0) ? round(($processedItemCountForProgress / $uniqueImageCount) * 100) : 0;
                    $this->line("PROGRESS_UPDATE: {$progressPercentage}% (Set {$set}: {$processedItemCountForProgress}/{$uniqueImageCount})");
                }
            }
            $bar->finish();
            $this->line("");
            if ($outputHandleClassifier) {$outputHandleClassifier = null;}
            if ($outputHandleDetector) {$outputHandleDetector = null;}

            if (($type === 'classifier' || $type === 'all') && $localTempClassifierCsvPath && file_exists($localTempClassifierCsvPath)) {
                $classifierContent = file_get_contents($localTempClassifierCsvPath);
                if (Storage::disk('s3')->put($s3FeatureCsvPathClassifier, $classifierContent ?: '', 'private')) {
                    $this->info("Classifier features (from temp) saved to S3: {$s3FeatureCsvPathClassifier}");
                } else {
                    Log::error("Failed to save classifier features to S3.", ['s3_path' => $s3FeatureCsvPathClassifier]);
                    $errors++;
                }
            }
            if (($type === 'detector' || $type === 'all') && $localTempDetectorCsvPath && file_exists($localTempDetectorCsvPath)) {
                $detectorContent = file_get_contents($localTempDetectorCsvPath);
                if (Storage::disk('s3')->put($s3FeatureCsvPathDetector, $detectorContent ?: '', 'private')) {
                    $this->info("Detector features (from temp) saved to S3: {$s3FeatureCsvPathDetector}");
                } else {
                    Log::error("Failed to save detector features to S3.", ['s3_path' => $s3FeatureCsvPathDetector]);
                    $errors++;
                }
            }
        } catch (Throwable $e) {
            $this->error("Critical error during feature extraction or file handling for set '{$set}': " . $e->getMessage());
            Log::critical("Feature Extraction Command Exception (S3)", ['set' => $set, 'exception' => $e]);
            $this->line("EVENT_LOG: CRITICAL ERROR for set '{$set}': " . Str::limit($e->getMessage(), 100));
            if (isset($outputHandleClassifier) && $outputHandleClassifier instanceof SplFileObject) {$outputHandleClassifier = null;}
            if (isset($outputHandleDetector) && $outputHandleDetector instanceof SplFileObject) {$outputHandleDetector = null;}
            return null;
        } finally {
            if ($localTempClassifierCsvPath && file_exists($localTempClassifierCsvPath)) {@unlink($localTempClassifierCsvPath);}
            if ($localTempDetectorCsvPath && file_exists($localTempDetectorCsvPath)) {@unlink($localTempDetectorCsvPath);}
        }
        $this->line("EVENT_LOG: Set '{$set}' processing complete. Written Cls:{$writtenClassifierRows}, Det:{$writtenDetectorRows}, Errors:{$errors}");
        return [
            'images_processed'        => $imagesProcessedInThisSet,
            'written_classifier_rows' => $writtenClassifierRows,
            'written_detector_rows'   => $writtenDetectorRows,
            'skipped_classifier'      => $skippedClassifier,
            'skipped_detector'        => $skippedDetector,
            'errors'                  => $errors,
        ];
    }

    private function loadExistingFeatureKeys(string $s3CsvPath, int $keyColumnIndex): array
    {
        $existingKeys     = [];
        $localTempCsvPath = null;
        try {
            $csvS3Content = Storage::disk('s3')->get($s3CsvPath);
            if ($csvS3Content === null || empty(trim($csvS3Content))) {return [];}
            $localTempCsvPath = tempnam(sys_get_temp_dir(), "s3_exist_feat_csv_");
            if ($localTempCsvPath === false || file_put_contents($localTempCsvPath, $csvS3Content) === false) {
                if ($localTempCsvPath && file_exists($localTempCsvPath)) {@unlink($localTempCsvPath);}return [];
            }
            $fileHandle = new SplFileObject($localTempCsvPath, 'r');
            $fileHandle->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
            $fileHandle->setCsvControl(',');
            $header = $fileHandle->fgetcsv();
            if (! $header || ! $fileHandle->valid()) {$fileHandle = null;return [];}
            while (! $fileHandle->eof() && ($row = $fileHandle->fgetcsv())) {
                if (is_array($row) && isset($row[$keyColumnIndex])) {
                    $keyRaw = trim($row[$keyColumnIndex]);
                    if (! empty($keyRaw)) {
                        $key                = ($keyColumnIndex === self::FEATURE_DET_ID_COL_INDEX) ? str_replace('\\', '/', $keyRaw) : $keyRaw;
                        $existingKeys[$key] = true;
                    }
                }
            }
            $fileHandle = null;
        } catch (Throwable $e) {
            Log::warning("Error reading existing feature file from S3", ['path' => $s3CsvPath, 'error' => $e->getMessage()]);
            if (isset($fileHandle)) {$fileHandle = null;}return [];
        } finally {
            if ($localTempCsvPath && file_exists($localTempCsvPath)) {@unlink($localTempCsvPath);}
        }
        return $existingKeys;
    }

    private function groupAnnotationsByImage(string $s3CsvPath): ?array
    {
        $annotationsByImage = [];
        $localTempCsvPath   = null;
        if (! Storage::disk('s3')->exists($s3CsvPath)) {return [];}
        try {
            $csvS3Content = Storage::disk('s3')->get($s3CsvPath);
            if ($csvS3Content === null) {return null;}
            if (empty(trim($csvS3Content))) {return [];}
            $localTempCsvPath = tempnam(sys_get_temp_dir(), "s3_group_anno_csv_");
            if ($localTempCsvPath === false || file_put_contents($localTempCsvPath, $csvS3Content) === false) {
                if ($localTempCsvPath && file_exists($localTempCsvPath)) {@unlink($localTempCsvPath);}return null;
            }
            $fileHandle = new SplFileObject($localTempCsvPath, 'r');
            $fileHandle->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
            $fileHandle->setCsvControl(',');
            $header = $fileHandle->fgetcsv();
            if (! $header || empty($header[0]) || count(array_diff(FeatureExtractionService::CSV_HEADER, array_map('trim', $header))) > 0) {
                return null;
            }
            $header = array_map('trim', $header);
            while (! $fileHandle->eof() && ($row = $fileHandle->fgetcsv())) {
                if (! is_array($row) || count($row) !== count($header)) {continue;}
                $rowData = @array_combine($header, $row);
                if ($rowData === false) {continue;}
                $filePath = trim($rowData['filename'] ?? '');
                if (! empty($filePath)) {
                    $fileKey                    = str_replace('\\', '/', $filePath);
                    $rowData['detection_class'] = strtolower(trim($rowData['detection_class'] ?? ''));
                    $rowData['ripeness_class']  = strtolower(trim($rowData['ripeness_class'] ?? ''));
                    $rowData['set']             = strtolower(trim($rowData['set'] ?? ''));
                    if (! in_array($rowData['set'], DatasetService::DATASET_SETS)) {
                        Log::warning("Invalid 'set' value in annotation row.", ['filename' => $filePath, 'set' => $rowData['set']]);
                    }
                    $annotationsByImage[$fileKey][] = $rowData;
                }
            }
            $fileHandle = null;return $annotationsByImage;
        } catch (Throwable $e) {
            Log::error("Error reading or grouping annotations from S3 CSV", ['s3_path' => $s3CsvPath, 'error' => $e->getMessage()]);
            if (isset($fileHandle)) {$fileHandle = null;}return null;
        } finally {
            if ($localTempCsvPath && file_exists($localTempCsvPath)) {@unlink($localTempCsvPath);}
        }
    }
}
