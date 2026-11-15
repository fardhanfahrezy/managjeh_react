---
name: Managjeh
description: Personal OS Mahasiswa yang tenang, fokus, dan terstruktur di atas kanvas hangat dan tipografi Inter.
colors:
  primary: "#059669"
  primary-dark: "#10b981"
  secondary: "#d97706"
  bright-snow: "#fafaf9"
  pure-white: "#ffffff"
  carbon-black: "#1c1917"
  warm-stone: "#f5f5f4"
  hairline-stone: "#e7e5e4"
  muted-graphite: "#57534e"
  mint-wash: "#ecfdf5"
  crimson-alert: "#dc2626"
typography:
  display:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "2rem"
    fontWeight: 600
    lineHeight: 1.15
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.375rem"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "-0.02em"
  title:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "-0.01em"
  body:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.6
    letterSpacing: "0em"
  label:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.35
    letterSpacing: "0.01em"
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.pure-white}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-outline:
    backgroundColor: "{colors.pure-white}"
    textColor: "{colors.carbon-black}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  button-secondary:
    backgroundColor: "{colors.warm-stone}"
    textColor: "{colors.carbon-black}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "36px"
  card-surface:
    backgroundColor: "{colors.pure-white}"
    textColor: "{colors.carbon-black}"
    rounded: "{rounded.xl}"
    padding: "24px"
  input-field:
    backgroundColor: "{colors.pure-white}"
    textColor: "{colors.carbon-black}"
    rounded: "{rounded.md}"
    padding: "4px 12px"
    height: "36px"
---

# Design System: Managjeh

## Overview

**Creative North Star: "The Quiet Desk"**

Managjeh dirancang seperti meja belajar yang rapi, terang, dan bebas distraksi bagi mahasiswa yang harus mengelola banyak tenggat waktu sekaligus. Kanvas hangat menyerupai kertas studio dipadukan dengan tinta karbon yang tegas serta satu aksen utama zamrud untuk aksi prioritas, sehingga mata pengguna langsung tertuju pada pekerjaan yang paling penting hari ini.

Seluruh antarmuka menggunakan keluarga tipografi tunggal **Inter** yang dibundel secara lokal untuk menjaga kecepatan muat dan konsistensi metrik angka pada tenggat waktu, jam kuliah, serta persentase progres proyek. Pemisahan area kerja mengandalkan *tonal layering* dan garis batas tipis yang tenang alih-alih dekorasi berlebih atau bayangan tebal.

**Key Characteristics:**
- Kanvas hangat *Bright Snow* dipadukan permukaan kartu *Pure White* dan teks *Carbon Black* berkonotasi editorial produktivitas.
- Satu aksen primer *Shamrock Emerald* untuk aksi utama serta *Ochre Amber* khusus untuk sinyal perhatian/tenggat mendekat.
- Sistem tipografi tunggal **Inter** (*variable font* lokal) dengan angka tabular untuk jadwal, durasi, dan metrik progres.
- Permukaan datar berlapis dengan border tipis dan elevasi bayangan minimal hanya pada *popover* atau *dialog* aktif.

## Colors

Palet warna Managjeh bertumpu pada netral batu hangat yang tenang agar tugas, jadwal, dan rekomendasi AI tetap menjadi fokus utama.

### Primary
- **Shamrock Emerald** (`#059669`, mode gelap `#10b981`): Digunakan untuk tombol aksi utama (seperti tambah tugas, simpan perubahan, dan pemicu *AI Planner*), indikator status aktif/selesai, serta cincin fokus keyboard.

### Secondary
- **Ochre Amber** (`#d97706`, mode gelap `#f59e0b`): Digunakan secara selektif untuk menandai tugas berprioritas tinggi, tenggat yang mendekat, peringatan konflik jadwal kalender, dan status *quiet hours* / tertunda pada pengingat WhatsApp.

### Neutral
- **Bright Snow** (`#fafaf9`): Latar belakang kanvas utama aplikasi pada mode terang agar nyaman dipandang dalam sesi belajar panjang.
- **Pure White** (`#ffffff`): Permukaan kartu utama, *popover*, *dialog*, dan *sidebar* pada mode terang untuk menciptakan pemisahan lapis yang bersih.
- **Warm Stone** (`#f5f5f4`): Permukaan sekunder untuk state *hover* navigasi, latar input/badge netral, dan sel kalender non-aktif.
- **Hairline Stone** (`#e7e5e4`): Garis batas tipis untuk memisahkan kartu, baris daftar tugas, input form, dan pembatas *sidebar*.
- **Muted Graphite** (`#57534e`): Teks sekunder, label waktu, metadata proyek, dan keterangan pembantu.
- **Carbon Black** (`#1c1917`): Teks utama berkonotasi tajam di mode terang sekaligus menjadi latar kanvas utama pada mode gelap.
- **Mint Wash** (`#ecfdf5`): Latar sorotan halus untuk item terpilih, *chip* aktif, dan penanda halus pada elemen terkait aksi utama.
- **Crimson Alert** (`#dc2626`): Khusus untuk aksi destruktif (hapus permanen) dan penanda tugas terlambat (*overdue*) atau error validasi.

### Named Rules
**The One Accent Rule.** Aksen *Shamrock Emerald* dibatasi hanya untuk aksi primer, status aktif, dan fokus; elemen sekunder wajib tetap berada dalam spektrum netral batu hangat.
**The Signal Restraint Rule.** *Ochre Amber* dan *Crimson Alert* hanya boleh muncul sebagai sinyal status nyata (prioritas tinggi, konflik jadwal, *overdue*, atau error), bukan sebagai hiasan kartu.

## Typography

**Display Font:** Inter (fallback `ui-sans-serif, system-ui, sans-serif`)
**Body Font:** Inter (fallback `ui-sans-serif, system-ui, sans-serif`)
**Label/Mono Font:** Inter dengan fitur `tabular-nums` untuk waktu, tanggal, dan persentase.

**Character:** Bersih, geometris-humanis, dan sangat terbaca pada ukuran kecil sekalipun, membuat daftar tugas padat dan sel kalender tetap lapang serta mudah dipindai.

### Hierarchy
- **Display** (`600`, `2rem`, `1.15`): Judul halaman utama dan sapaan kontekstual di Dashboard.
- **Headline** (`600`, `1.375rem`, `1.25`): Kepala bagian utama seperti *3 Prioritas AI Planner* atau nama proyek pada halaman detail.
- **Title** (`600`, `1rem`, `1.4`): Judul kartu, judul dialog, dan judul tugas utama.
- **Body** (`400`, `0.875rem`, `1.6`): Deskripsi tugas, isi catatan kalender, teks form, dan salinan antarmuka umum (lebar baca optimal `65ch–75ch`).
- **Label** (`500`, `0.75rem`, `1.35`): Badge status/prioritas, label metadata, jam jadwal, dan teks pembantu form.

### Named Rules
**The Single-Family Rule.** Seluruh antarmuka hanya memuat keluarga font lokal **Inter** (*variable font*); font eksternal lain dilarang dimuat agar tidak terjadi pergeseran tata letak atau inkonsistensi metrik.

## Layout

Antarmuka kerja utama menggunakan pola *app shell* dengan *sidebar* navigasi di sisi kiri dan area konten utama di sisi kanan, mendukung tata letak responsif untuk desktop maupun peramban mobile mahasiswa. Pada layar desktop lebar (seperti Dashboard), area konten dapat terbagi menjadi kolom kerja utama dan panel konteks kanan (status WhatsApp, mini kalender, agenda hari ini), sementara pada layar sempit panel konteks menyusun vertikal secara alami.

Ritme spasi mengikuti kelipatan dasar `4px` (`4px`, `8px`, `16px`, `24px`, `32px`): jarak antar elemen rapat menggunakan `8px`, jarak antar komponen dalam kartu menggunakan `16px`, dan jarak antar blok kartu atau padding kartu utama menggunakan `24px`.

## Elevation & Depth

Managjeh menganut pendekatan **tonal layering + border tipis**: kedalaman visual dibangun dari perbedaan warna permukaan (*Bright Snow* kanvas vs *Pure White* kartu) yang dibingkai garis batas tipis *Hairline Stone* (`1px solid`), bukan dari bayangan tebal.

### Shadow Vocabulary
- **Subtle Rest** (`box-shadow: 0 1px 2px 0 rgba(28, 25, 23, 0.05)`): Bayangan nyaris tak terlihat pada kartu dan tombol standar agar tetap terasa membumi.
- **Floating Overlay** (`box-shadow: 0 10px 25px -5px rgba(28, 25, 23, 0.08), 0 4px 10px -6px rgba(28, 25, 23, 0.04)`): Khusus untuk elemen mengambang seperti *dialog*, *dropdown menu*, *toast*, dan *popover* kalender.

### Named Rules
**The Quiet Surface Rule.** Kartu pada posisi diam tidak boleh menggunakan bayangan besar atau efek *glow*; pemisahan utama selalu mengandalkan kontras lapisan dan garis batas `1px`.

## Shapes

Bahasa bentuk memadukan sudut membulat yang ramah namun tetap presisi: radius dasar sistem (`--radius: 0.5rem` / `8px`) digunakan sebagai jangkar konsisten, diturunkan menjadi `6px` untuk kontrol interaktif padat (tombol, input, *badge*) dan `12px` untuk wadah kartu utama dan *dialog*. Tidak ada sudut tajam penuh (`0px`) atau kapsul ekstrem pada kartu kerja.

## Components

Komponen dibangun di atas primitif Radix UI dan Tailwind CSS v4 dengan gaya ringkas, fokus, dan siap diakses melalui keyboard.

### Buttons
- **Shape:** Sudut membulat sedang (`6px`) dengan tinggi standar `36px` (`sm` `32px`, `lg` `40px`).
- **Primary:** Latar *Shamrock Emerald*, teks putih, padding horizontal `16px`, dengan transisi halus pada warna latar saat *hover*.
- **Hover / Focus:** Saat *hover* intensitas warna melembut (`90%` opasitas); saat *focus-visible* menampilkan cincin fokus `3px` berwarna *ring* di luar batas kontrol.
- **Secondary / Outline / Ghost:** *Outline* memakai latar permukaan putih dengan border *Hairline Stone*; *Secondary* memakai *Warm Stone*; *Ghost* transparan dengan sorotan *Warm Stone* saat *hover*.

### Cards / Containers
- **Corner Style:** Sudut membulat lembut (`12px`).
- **Background:** *Pure White* di mode terang dan batu gelap hangat (`#292524`) di mode gelap.
- **Shadow Strategy:** Bayangan minimal (`shadow-sm`) yang mendampingi border `1px` *Hairline Stone*.
- **Border:** `1px solid` menggunakan token `--border`.
- **Internal Padding:** `24px` secara horizontal dan vertikal (`py-6`, `px-6`) dengan jarak antar slot header/konten `24px`.

### Inputs / Fields
- **Style:** Tinggi `36px`, sudut membulat `6px`, border `1px` menggunakan token `--input`, dan teks *placeholder* memakai *Muted Graphite*.
- **Focus:** Border berpindah ke token `--ring` dengan cincin fokus `3px` (`ring/50`).
- **Error / Disabled:** State `aria-invalid` memicu border dan cincin fokus merah (*Crimson Alert*), sedangkan state *disabled* menurunkan opasitas menjadi `50%`.

### Navigation
- **Style:** *Sidebar* vertikal dengan latar *Pure White* (mode terang) / arang gelap `#171412` (mode gelap) dan garis pemisah `1px` di sisi kanan; item aktif ditandai dengan latar *Warm Stone* dan bobot font `500–600`, sedangkan di mobile berubah menjadi *drawer* (`Sheet`).

## Do's and Don'ts

### Do:
- **Do** gunakan `Inter` lokal untuk seluruh teks dan aktifkan `tabular-nums` pada jam, tanggal, dan persentase progres proyek.
- **Do** pertahankan *Shamrock Emerald* (`#059669`) hanya untuk aksi utama, status aktif, dan fokus agar hirarki visual tetap tenang.
- **Do** pisahkan kartu dan panel menggunakan lapisan permukaan + border tipis `1px` (`#e7e5e4`).

### Don't:
- **Don't** memuat kembali font eksternal seperti *Instrument Sans* atau Bunny/Google Fonts di `vite.config.ts` maupun `app.blade.php`.
- **Don't** menggunakan warna *Ochre Amber* (`#d97706`) atau merah sebagai latar kartu penuh atau hiasan dekoratif.
- **Don't** menumpuk bayangan tebal, gradien mencolok, atau efek *glassmorphism* pada kartu kerja harian.
