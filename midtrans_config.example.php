<?php
// Salin file ini menjadi midtrans_config.php lalu isi key dari dashboard Midtrans.
// midtrans_config.php tidak ikut ke git (lihat .gitignore) karena berisi Server Key rahasia.
//
// Cara mendapatkan key Sandbox:
// 1. Daftar/login di https://dashboard.sandbox.midtrans.com
// 2. Buka Settings > Access Keys
// 3. Salin "Server Key" dan "Client Key" (akun baru: Mid-server-... / Mid-client-...,
//    akun lama: SB-Mid-server-... / SB-Mid-client-...)
// JANGAN isi key di file ini (file ini ikut ke git) — isi di midtrans_config.php.

const MIDTRANS_SERVER_KEY    = '';
const MIDTRANS_CLIENT_KEY    = '';
// false = Sandbox (uji coba, tidak ada uang sungguhan). true = Production.
const MIDTRANS_IS_PRODUCTION = false;
