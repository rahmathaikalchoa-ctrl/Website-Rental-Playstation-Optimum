-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: gamezone
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `bookings`
--

DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `room_id` int(11) NOT NULL,
  `duration` int(11) NOT NULL,
  `start_time` bigint(20) NOT NULL,
  `end_time` bigint(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `room_id` (`room_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
INSERT INTO `bookings` VALUES (20,8,'Rahmat Haikal Choa','chanpororo547@gmail.com','081270763036',9,2,1777816800,1777824000,'2026-05-03 07:58:33'),(21,8,'SELINA','donny@gmail.com','081212121212121',12,1,1777816800,1777820400,'2026-05-03 09:02:10'),(22,8,'Rahmat Haika Choa','chanpororo547@gmail.com','081270763036',9,1,1777816800,1777820400,'2026-05-03 09:02:36'),(23,8,'Rahmat Haika Choa','chanpororo547@gmail.com','081270763036',10,1,1777802400,1777806000,'2026-05-03 09:08:15'),(24,24,'Sela','sela@gmail.com','08123456789',12,4,1777899600,1777914000,'2026-05-04 12:39:27'),(25,23,'Wilian Ng Lim','wilian@gmail.com','081270763036',12,1,1778162400,1778166000,'2026-05-07 13:35:53'),(26,22,'Haikal','wwwww@gmail.com','081270763036',12,2,1778306400,1778313600,'2026-05-09 05:22:58'),(28,25,'ARDI','ardi@gmail.com','08121212121212',12,2,1778392800,1778400000,'2026-05-10 05:58:14');
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `game_consoles`
--

DROP TABLE IF EXISTS `game_consoles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `game_consoles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `game_id` int(11) NOT NULL,
  `console_type` enum('PS3','PS4','PS5') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `game_id` (`game_id`),
  CONSTRAINT `game_consoles_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `game_consoles`
--

LOCK TABLES `game_consoles` WRITE;
/*!40000 ALTER TABLE `game_consoles` DISABLE KEYS */;
INSERT INTO `game_consoles` VALUES (36,15,'PS4'),(37,15,'PS5'),(38,16,'PS3'),(39,16,'PS4'),(40,16,'PS5'),(41,17,'PS4'),(42,17,'PS5'),(45,19,'PS4'),(46,19,'PS5'),(47,20,'PS3'),(48,20,'PS4'),(49,20,'PS5'),(50,21,'PS4'),(51,21,'PS5'),(52,22,'PS3'),(53,22,'PS4'),(54,23,'PS3'),(55,23,'PS4'),(56,24,'PS5'),(57,25,'PS5'),(58,26,'PS3'),(59,26,'PS4'),(65,14,'PS4'),(66,14,'PS5'),(67,27,'PS5');
/*!40000 ALTER TABLE `game_consoles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `games`
--

DROP TABLE IF EXISTS `games`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `games` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `genre` varchar(50) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `cover_image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `games`
--

LOCK TABLES `games` WRITE;
/*!40000 ALTER TABLE `games` DISABLE KEYS */;
INSERT INTO `games` VALUES (14,'Resident Evil 7: Biohazard','Action','game_69f70ca75e965.webp',1,'2026-05-03 08:51:51',NULL),(15,'Resident Evil Village','Action','game_69f70ccb62b74.jpg',1,'2026-05-03 08:52:27',NULL),(16,'EFOOTBALL 2026','Sports','game_69f70ce0887cc.jpg',1,'2026-05-03 08:52:48',NULL),(17,'FC26','Sports','game_69f70cec1ce58.jpg',1,'2026-05-03 08:53:00',NULL),(19,'Resident Evil Requiem','Action','game_69f70d26175f3.webp',1,'2026-05-03 08:53:58',NULL),(20,'Devil May Cry 5','Action','game_69f70d4508541.webp',1,'2026-05-03 08:54:29',NULL),(21,'Final Fantasy VII Remake','Adventure','game_69f70d85e0591.png',1,'2026-05-03 08:55:33',NULL),(22,'F1 2021','Racing','game_69f70dca438e2.jpg',1,'2026-05-03 08:56:42',NULL),(23,'Persona 5','Puzzle',NULL,1,'2026-05-03 15:23:49',NULL),(24,'NBA 2K26','Sports',NULL,1,'2026-05-03 16:20:39',NULL),(25,'NBA 2K','Sports',NULL,1,'2026-05-03 16:30:51',NULL),(26,'FC25','Sports','game_69f779b1eff47.jpg',1,'2026-05-03 16:37:06',NULL),(27,'GTA VI','Roleplay',NULL,1,'2026-05-07 14:40:47',NULL);
/*!40000 ALTER TABLE `games` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu_items`
--

DROP TABLE IF EXISTS `menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` enum('makanan','minuman') NOT NULL,
  `price` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_items`
--

LOCK TABLES `menu_items` WRITE;
/*!40000 ALTER TABLE `menu_items` DISABLE KEYS */;
INSERT INTO `menu_items` VALUES (1,'Indomie Goreng','makanan',12000,'-',NULL,1,'2026-05-03 11:03:18'),(2,'Indomie Rebus','makanan',12000,'-',NULL,1,'2026-05-03 11:03:34'),(3,'Indomie Goreng Jumbo','makanan',18000,'-',NULL,1,'2026-05-03 11:03:51'),(5,'Indomie Rebus Jumbo','makanan',18000,'-',NULL,1,'2026-05-03 11:04:37'),(6,'Nasi Goreng','makanan',15000,'-',NULL,1,'2026-05-03 11:04:51'),(7,'Air Mineral','minuman',5000,'-',NULL,1,'2026-05-03 11:05:14'),(8,'Coca Cola','minuman',8000,'-',NULL,0,'2026-05-03 11:05:28');
/*!40000 ALTER TABLE `menu_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu_orders`
--

DROP TABLE IF EXISTS `menu_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `note` text DEFAULT NULL,
  `status` enum('pending','selesai') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `menu_orders_ibfk_2` (`item_id`),
  CONSTRAINT `menu_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `menu_orders_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_orders`
--

LOCK TABLES `menu_orders` WRITE;
/*!40000 ALTER TABLE `menu_orders` DISABLE KEYS */;
INSERT INTO `menu_orders` VALUES (1,8,1,1,'','selesai','2026-05-03 11:05:54'),(2,24,3,2,'','selesai','2026-05-04 12:40:13'),(3,24,8,4,'','selesai','2026-05-04 12:40:27'),(4,23,1,1,'TIDAK PEDAS','selesai','2026-05-07 13:25:09'),(5,22,7,1,'','selesai','2026-05-09 18:47:04'),(6,25,3,1,'Pedas','selesai','2026-05-10 05:59:25');
/*!40000 ALTER TABLE `menu_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rooms`
--

DROP TABLE IF EXISTS `rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `console_type` varchar(50) NOT NULL,
  `price` int(11) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('available','unavailable') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rooms`
--

LOCK TABLES `rooms` WRITE;
/*!40000 ALTER TABLE `rooms` DISABLE KEYS */;
INSERT INTO `rooms` VALUES (9,'Reguler - Room 2','PS3',25000,NULL,'Ruangan Nyaman 2 Orang','available','2026-02-03 15:43:10'),(10,'Reguler - Room 1','PS3',25000,NULL,'Ruangan Nyaman 2 Orang','available','2026-05-03 08:41:59'),(11,'Premium - Room 2','PS4',40000,NULL,'Ruangan Nyaman 4 Orang  dengan pelayanan Premium','available','2026-05-03 08:42:46'),(12,'VIP - Room 3','PS5',55000,NULL,'Ruangan Nyaman dengan pelayanan eksklusif','available','2026-05-03 08:43:26');
/*!40000 ALTER TABLE `rooms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (8,'DONNY','user','$2y$10$FyOZFIzqKxU7hk2nH9dnhuUnTQGZtKLA0aqBORoCSKq.rw/C23eWG','2026-01-07 15:02:46','2026-07-25 17:06:40'),(16,'WILLIAN','user','$2y$10$NI4CkXtAnsCL4QoBgbhgJ.lrWMCuOy0Odvnj3yqloeK/mk11la016','2026-01-15 14:12:55','2026-01-18 16:49:43'),(17,'haziq','user','$2y$10$H0T7pVuWnfd1.TQam1XaT.fWmqsGe8Ggl3Yky6oacoibtYXqk5Hwi','2026-01-17 10:43:25','2026-01-17 17:43:35'),(18,'sela','user','$2y$10$nmp9uMVJeCgVc3ZvjZV9nebgkERmonT6KGWRNHDTMdgvHc2hH/S/u','2026-01-17 10:49:46','2026-01-17 17:50:00'),(19,'CELA','user','$2y$10$EUfq.eZKFxL20SelTcfxre1CR/Vo8eiNS/k7OmouKToFZIIihctJW','2026-01-18 09:53:19','2026-01-18 20:26:05'),(20,'DEVID','user','$2y$10$s0O0jaWztSKy2SPxEvPVKOHvdgkV6RNnQr1ZNzdyqDnmYYkAct8Pi','2026-01-22 12:38:12',NULL),(21,'SELINA','user','$2y$10$XXHiXgZDDZr8/Adlwns7..ozuRlf2AkMpkVbtyxjxLgQ7TdKQegb.','2026-01-25 09:56:00','2026-01-25 16:56:30'),(22,'HAIKAL','admin','$2y$10$zUUv/QVEZNzRwGYVDT5odeAsIQunU/sAjplfjdNUlwH8eAh9w42D6','2026-05-03 10:00:36','2026-05-21 23:37:13'),(23,'WILIAN','user','$2y$10$1Z1pkIKTDMARQvCsw4j4kegSASQVBMwV1/C2SLS29vhenyP1OXcrG','2026-05-03 15:01:16','2026-05-09 13:40:13'),(24,'SelaCantik','user','$2y$10$JTAr1Xl56NYxAb2fGP7Qv.tnxCTJ6zayNHlOc4QF09z2VSlzyqSe.','2026-05-04 12:37:49','2026-05-04 19:39:35'),(25,'ARDI','user','$2y$10$64vfk2iQ.kxsqH/5R.olr.4g/ENKt84q.dk5CXGOqndVqzjAm3E/S','2026-05-10 05:57:13','2026-05-10 13:16:23'),(26,'JEREMY','user','$2y$10$/sMqP.r6iMReSwGCeod5QeXZK6iCjB7ks3f0DWNtSkhLJLtnAtjAm','2026-05-14 06:01:35','2026-05-21 22:48:45');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'gamezone'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-25 17:24:57
