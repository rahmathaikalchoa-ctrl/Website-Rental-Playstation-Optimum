<?php
// Informasi tempat yang tampil di website (Beranda & footer).
// Jam operasional untuk aturan booking ada di booking_lib.php (BOOKING_OPEN_SEC / BOOKING_CLOSE_SEC).

const SITE_NAME     = 'Optimum Playzone';
const SITE_HOURS    = 'Setiap hari, 10:00 – 22:00';
const SITE_ADDRESS  = 'Ruko Greenland Blok M No. 3A, Teluk Tering, Batam Kota, Batam, Kepulauan Riau 29461';
const SITE_MAPS_URL = 'https://share.google/ykIgS5mqs2e0vyDJt';
const SITE_WA       = '6282285575076';   // nomor WhatsApp format 62xxx
const SITE_WA_SHOW  = '0822-8557-5076';  // nomor yang ditampilkan

function siteWaLink($text = 'Halo Optimum Playzone, saya mau tanya ketersediaan ruangan.') {
  return 'https://wa.me/' . SITE_WA . '?text=' . rawurlencode($text);
}
