<?php

// File: app/Console/Kernel.php
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        // Daftarkan command untuk training model classifier
        \App\Console\Commands\TrainMelonClassifierModel::class,
        // Daftarkan command untuk training model detector
        \App\Console\Commands\TrainMelonDetectorModel::class,
        // Daftarkan command untuk ekstraksi fitur
        \App\Console\Commands\ExtractFeaturesCommand::class,
        // Tambahkan command custom lainnya di sini jika ada
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        // $schedule->command('inspire')->hourly();
        // Contoh: Jika ingin menjalankan ekstraksi fitur setiap malam
        // $schedule->command('dataset:extract-features --incremental')->daily()->at('02:00');
        // Contoh: Jika ingin menjalankan training ulang setiap minggu (hati-hati resource)
        // $schedule->command('train:melon-classifier --with-test')->weekly()->sundays()->at('03:00');
        // $schedule->command('train:melon-detector --with-test')->weekly()->sundays()->at('04:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands(): void
    {
        // Muat command dari direktori Commands (standar Laravel)
        $this->load(__DIR__ . '/Commands');

        // Muat route dari file console.php (standar Laravel)
        require base_path('routes/console.php');
    }
}
