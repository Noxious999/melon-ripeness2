<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Best Models
    |--------------------------------------------------------------------------
    |
    | Tentukan kunci (nama file tanpa ekstensi) untuk model detektor
    | dan classifier yang dianggap terbaik secara default.
    | Nilai ini dibaca dari variabel environment.
    |
    */

    'best_detector' => env('BEST_DETECTOR_MODEL', 'classification_tree_detector'), // Default jika .env tidak diset

    'best_classifier' => env('BEST_CLASSIFIER_MODEL', 'classification_tree_classifier'), // Default jika .env tidak diset

    /*
    |--------------------------------------------------------------------------
    | Base Model Keys
    |--------------------------------------------------------------------------
    |
    | Daftar kunci dasar model yang digunakan untuk iterasi
    | (misalnya saat menjalankan semua model lain).
    |
    */
    'base_keys' => [
        'gaussian_nb',
        'classification_tree',
        'logistic_regression',
        'ada_boost',
        'k_nearest_neighbors',
        'logit_boost',
        'multilayer_perceptron',
        'random_forest',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scaler Names
    |--------------------------------------------------------------------------
    |
    | Nama file (tanpa ekstensi) untuk scaler yang disimpan.
    | Pastikan nama ini konsisten saat menyimpan dan memuat.
    |
    */
    'detector_scaler_name' => 'detector_scaler', // Contoh: disimpan sebagai detector_scaler.phpdata
    'classifier_scaler_name' => 'classifier_scaler', // Contoh: disimpan sebagai classifier_scaler.phpdata

];
