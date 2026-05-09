-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 03, 2026 at 07:32 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `gamezone`
--

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `room_id` int(11) NOT NULL,
  `duration` int(11) NOT NULL,
  `start_time` bigint(20) NOT NULL,
  `end_time` bigint(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bookings`
--

INSERT INTO `bookings` (`id`, `user_id`, `customer_name`, `email`, `phone`, `room_id`, `duration`, `start_time`, `end_time`, `created_at`) VALUES
(20, 8, 'Rahmat Haikal Choa', 'chanpororo547@gmail.com', '081270763036', 9, 2, 1777816800, 1777824000, '2026-05-03 07:58:33'),
(21, 8, 'SELINA', 'donny@gmail.com', '081212121212121', 12, 1, 1777816800, 1777820400, '2026-05-03 09:02:10'),
(22, 8, 'Rahmat Haika Choa', 'chanpororo547@gmail.com', '081270763036', 9, 1, 1777816800, 1777820400, '2026-05-03 09:02:36'),
(23, 8, 'Rahmat Haika Choa', 'chanpororo547@gmail.com', '081270763036', 10, 1, 1777802400, 1777806000, '2026-05-03 09:08:15');

-- --------------------------------------------------------

--
-- Table structure for table `games`
--

CREATE TABLE `games` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `genre` varchar(50) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cover_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `games`
--

INSERT INTO `games` (`id`, `title`, `genre`, `image`, `is_active`, `created_at`, `cover_image`) VALUES
(14, 'Resident Evil 7: Biohazard', 'Action', 'game_69f70ca75e965.webp', 1, '2026-05-03 08:51:51', NULL),
(15, 'Resident Evil Village', 'Action', 'game_69f70ccb62b74.jpg', 1, '2026-05-03 08:52:27', NULL),
(16, 'EFOOTBALL 2026', 'Sports', 'game_69f70ce0887cc.jpg', 1, '2026-05-03 08:52:48', NULL),
(17, 'FC26', 'Sports', 'game_69f70cec1ce58.jpg', 1, '2026-05-03 08:53:00', NULL),
(19, 'Resident Evil Requiem', 'Action', 'game_69f70d26175f3.webp', 1, '2026-05-03 08:53:58', NULL),
(20, 'Devil May Cry 5', 'Action', 'game_69f70d4508541.webp', 1, '2026-05-03 08:54:29', NULL),
(21, 'Final Fantasy VII Remake', 'Adventure', 'game_69f70d85e0591.png', 1, '2026-05-03 08:55:33', NULL),
(22, 'F1 2021', 'Racing', 'game_69f70dca438e2.jpg', 1, '2026-05-03 08:56:42', NULL),
(23, 'Persona 5', 'Puzzle', NULL, 1, '2026-05-03 15:23:49', NULL),
(24, 'NBA 2K26', 'Sports', NULL, 1, '2026-05-03 16:20:39', NULL),
(25, 'NBA 2K', 'Sports', NULL, 1, '2026-05-03 16:30:51', NULL),
(26, 'FC25', 'Sports', 'game_69f779b1eff47.jpg', 1, '2026-05-03 16:37:06', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `game_consoles`
--

CREATE TABLE `game_consoles` (
  `id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `console_type` enum('PS3','PS4','PS5') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `game_consoles`
--

INSERT INTO `game_consoles` (`id`, `game_id`, `console_type`) VALUES
(36, 15, 'PS4'),
(37, 15, 'PS5'),
(38, 16, 'PS3'),
(39, 16, 'PS4'),
(40, 16, 'PS5'),
(41, 17, 'PS4'),
(42, 17, 'PS5'),
(45, 19, 'PS4'),
(46, 19, 'PS5'),
(47, 20, 'PS3'),
(48, 20, 'PS4'),
(49, 20, 'PS5'),
(50, 21, 'PS4'),
(51, 21, 'PS5'),
(52, 22, 'PS3'),
(53, 22, 'PS4'),
(54, 23, 'PS3'),
(55, 23, 'PS4'),
(56, 24, 'PS5'),
(57, 25, 'PS5'),
(58, 26, 'PS3'),
(59, 26, 'PS4'),
(62, 14, 'PS3'),
(63, 14, 'PS4'),
(64, 14, 'PS5');

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` enum('makanan','minuman') NOT NULL,
  `price` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_items`
--

INSERT INTO `menu_items` (`id`, `name`, `category`, `price`, `description`, `image`, `is_available`, `created_at`) VALUES
(1, 'Indomie Goreng', 'makanan', 12000, '-', NULL, 1, '2026-05-03 11:03:18'),
(2, 'Indomie Rebus', 'makanan', 12000, '-', NULL, 1, '2026-05-03 11:03:34'),
(3, 'Indomie Goreng Jumbo', 'makanan', 18000, '-', NULL, 1, '2026-05-03 11:03:51'),
(5, 'Indomie Rebus Jumbo', 'makanan', 18000, '-', NULL, 1, '2026-05-03 11:04:37'),
(6, 'Nasi Goreng', 'makanan', 15000, '-', NULL, 1, '2026-05-03 11:04:51'),
(7, 'Air Mineral', 'minuman', 5000, '-', NULL, 1, '2026-05-03 11:05:14'),
(8, 'Coca Cola', 'minuman', 8000, '-', NULL, 1, '2026-05-03 11:05:28');

-- --------------------------------------------------------

--
-- Table structure for table `menu_orders`
--

CREATE TABLE `menu_orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `note` text DEFAULT NULL,
  `status` enum('pending','selesai') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_orders`
--

INSERT INTO `menu_orders` (`id`, `user_id`, `item_id`, `quantity`, `note`, `status`, `created_at`) VALUES
(1, 8, 1, 1, '', 'selesai', '2026-05-03 11:05:54');

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `console_type` varchar(50) NOT NULL,
  `price` int(11) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('available','unavailable') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`id`, `title`, `console_type`, `price`, `image`, `description`, `status`, `created_at`) VALUES
(9, 'Reguler - Room 2', 'PS3', 25000, NULL, 'Ruangan Nyaman 2 Orang', 'available', '2026-02-03 15:43:10'),
(10, 'Reguler - Room 1', 'PS3', 25000, NULL, 'Ruangan Nyaman 2 Orang', 'available', '2026-05-03 08:41:59'),
(11, 'Premium - Room 2', 'PS4', 40000, NULL, 'Ruangan Nyaman 4 Orang  dengan pelayanan Premium', 'available', '2026-05-03 08:42:46'),
(12, 'VIP - Room 3', 'PS5', 55000, NULL, 'Ruangan Nyaman dengan pelayanan eksklusif', 'available', '2026-05-03 08:43:26');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `role`, `password`, `created_at`, `last_activity`) VALUES
(8, 'DONNY', 'user', '$2y$10$FyOZFIzqKxU7hk2nH9dnhuUnTQGZtKLA0aqBORoCSKq.rw/C23eWG', '2026-01-07 15:02:46', '2026-05-03 23:59:46'),
(16, 'WILLIAN', 'user', '$2y$10$NI4CkXtAnsCL4QoBgbhgJ.lrWMCuOy0Odvnj3yqloeK/mk11la016', '2026-01-15 14:12:55', '2026-01-18 16:49:43'),
(17, 'haziq', 'user', '$2y$10$H0T7pVuWnfd1.TQam1XaT.fWmqsGe8Ggl3Yky6oacoibtYXqk5Hwi', '2026-01-17 10:43:25', '2026-01-17 17:43:35'),
(18, 'sela', 'user', '$2y$10$nmp9uMVJeCgVc3ZvjZV9nebgkERmonT6KGWRNHDTMdgvHc2hH/S/u', '2026-01-17 10:49:46', '2026-01-17 17:50:00'),
(19, 'CELA', 'user', '$2y$10$EUfq.eZKFxL20SelTcfxre1CR/Vo8eiNS/k7OmouKToFZIIihctJW', '2026-01-18 09:53:19', '2026-01-18 20:26:05'),
(20, 'DEVID', 'user', '$2y$10$s0O0jaWztSKy2SPxEvPVKOHvdgkV6RNnQr1ZNzdyqDnmYYkAct8Pi', '2026-01-22 12:38:12', NULL),
(21, 'SELINA', 'user', '$2y$10$XXHiXgZDDZr8/Adlwns7..ozuRlf2AkMpkVbtyxjxLgQ7TdKQegb.', '2026-01-25 09:56:00', '2026-01-25 16:56:30'),
(22, 'HAIKAL', 'admin', '$2y$10$zUUv/QVEZNzRwGYVDT5odeAsIQunU/sAjplfjdNUlwH8eAh9w42D6', '2026-05-03 10:00:36', NULL),
(23, 'WILIAN', 'user', '$2y$10$1Z1pkIKTDMARQvCsw4j4kegSASQVBMwV1/C2SLS29vhenyP1OXcrG', '2026-05-03 15:01:16', '2026-05-04 00:06:46');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `room_id` (`room_id`);

--
-- Indexes for table `games`
--
ALTER TABLE `games`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `game_consoles`
--
ALTER TABLE `game_consoles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `game_id` (`game_id`);

--
-- Indexes for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `menu_orders`
--
ALTER TABLE `menu_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `games`
--
ALTER TABLE `games`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `game_consoles`
--
ALTER TABLE `game_consoles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `menu_orders`
--
ALTER TABLE `menu_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`);

--
-- Constraints for table `game_consoles`
--
ALTER TABLE `game_consoles`
  ADD CONSTRAINT `game_consoles_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `menu_orders`
--
ALTER TABLE `menu_orders`
  ADD CONSTRAINT `menu_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `menu_orders_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
