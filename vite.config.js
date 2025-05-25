import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { VitePWA } from 'vite-plugin-pwa'; // Import plugin

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/annotation.css',
                'resources/css/evaluate.css',
                'resources/js/app.js',
                'resources/js/annotation.js',
                'resources/js/evaluate.js',
            ],
            refresh: true,
        }),
        VitePWA({ // Tambahkan konfigurasi PWA
            registerType: 'autoUpdate', // Otomatis update service worker saat ada versi baru
            injectRegister: 'autoUpdate', // Kita akan register manual di app.js jika perlu kustomisasi
            // Atau set ke 'script' jika ingin plugin menyuntikkan kode registrasi
            workbox: {
                globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2,ttf,eot}'], // Aset yang akan di-precache oleh Workbox
                // runtimeCaching bisa ditambahkan di sini untuk strategi cache yang lebih kompleks (misal untuk API)
            },
            manifest: { // Konfigurasi manifest.json Anda
                name: "Sistem Prediksi Kematangan Melon",
                short_name: "MelonPrediksi",
                description: "Aplikasi untuk memprediksi kematangan buah melon menggunakan Machine Learning.",
                theme_color: "#3b71ca", // Sesuaikan dengan --ap-primary Anda
                background_color: "#ffffff",
                display: "standalone",
                scope: "/",
                start_url: "/", // Atau "/melon" jika itu halaman utama Anda
                icons: [ // Sediakan ikon di folder public/images/icons/ (atau path lain yang Anda tentukan)
                    { src: "/images/icons/icon-72x72.png", sizes: "72x72", type: "image/png" },
                    { src: "/images/icons/icon-96x96.png", sizes: "96x96", type: "image/png" },
                    { src: "/images/icons/icon-128x128.png", sizes: "128x128", type: "image/png" },
                    { src: "/images/icons/icon-144x144.png", sizes: "144x144", type: "image/png" },
                    { src: "/images/icons/icon-152x152.png", sizes: "152x152", type: "image/png" },
                    { src: "/images/icons/icon-192x192.png", sizes: "192x192", type: "image/png", purpose: "any maskable" },
                    { src: "/images/icons/icon-384x384.png", sizes: "384x384", type: "image/png" },
                    { src: "/images/icons/icon-512x512.png", sizes: "512x512", type: "image/png" }
                ]
            }
        })
    ],
});
