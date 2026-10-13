<?php
// info tempat buat website (Beranda & footer)
// jam operasional booking ada di booking_lib.php

const SITE_NAME     = 'Optimum Playzone';
const SITE_HOURS    = 'Setiap hari, 10:00 – 22:00';
const SITE_ADDRESS  = 'Ruko Greenland Blok M No. 3A, Teluk Tering, Batam Kota, Batam, Kepulauan Riau 29461';
const SITE_MAPS_URL = 'https://share.google/ykIgS5mqs2e0vyDJt';
const SITE_WA       = '6282285575076';   // nomor WA format 62xxx
const SITE_WA_SHOW  = '0822-8557-5076';  // nomor yg ditampilin
const SITE_INSTAGRAM = 'optimum.playstation'; // akun IG (syarat paket pelajar)

// buat pertanyaan umum, cek ruangan & booking lewat website
function siteWaLink($text = 'Halo Optimum Playzone, saya mau bertanya.') {
  return 'https://wa.me/' . SITE_WA . '?text=' . rawurlencode($text);
}
