<?php
// Informasi tempat yang tampil di website (Beranda & footer).
// GANTI nilai contoh di bawah dengan data asli Optimum Playzone.

const SITE_NAME     = 'Optimum Playzone';
const SITE_HOURS    = 'Setiap hari, 11:00 – 24:00';
const SITE_ADDRESS  = 'Jl. Contoh Alamat No. 1, Kota Anda';           // ganti dengan alamat asli
const SITE_MAPS_URL = 'https://maps.google.com/?q=Optimum+Playzone';    // ganti dengan link Google Maps
const SITE_WA       = '6281234567890';                                  // nomor WhatsApp format 62xxx
const SITE_WA_SHOW  = '0812-3456-7890';                                 // nomor yang ditampilkan

function siteWaLink($text = 'Halo Optimum Playzone, saya mau tanya ketersediaan ruangan.') {
  return 'https://wa.me/' . SITE_WA . '?text=' . rawurlencode($text);
}
