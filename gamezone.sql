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
-- Table structure for table `booking_payments`
--

DROP TABLE IF EXISTS `booking_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `amount` int(11) NOT NULL,
  `paid_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `paid_at` (`paid_at`),
  CONSTRAINT `booking_payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_payments`
--

LOCK TABLES `booking_payments` WRITE;
/*!40000 ALTER TABLE `booking_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `booking_queue`
--

DROP TABLE IF EXISTS `booking_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source` enum('online','walkin') NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `room_class` varchar(40) NOT NULL DEFAULT '',
  `duration` int(11) NOT NULL,
  `with_nintendo` tinyint(1) NOT NULL DEFAULT 0,
  `is_student` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('waiting','assigned','cancelled') NOT NULL DEFAULT 'waiting',
  `booking_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status_console` (`status`,`created_at`),
  KEY `user_id` (`user_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `booking_queue_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_queue_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking_queue`
--

LOCK TABLES `booking_queue` WRITE;
/*!40000 ALTER TABLE `booking_queue` DISABLE KEYS */;
/*!40000 ALTER TABLE `booking_queue` ENABLE KEYS */;
UNLOCK TABLES;

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
  `with_nintendo` tinyint(1) NOT NULL DEFAULT 0,
  `is_student` tinyint(1) NOT NULL DEFAULT 0,
  `start_time` bigint(20) NOT NULL,
  `end_time` bigint(20) NOT NULL,
  `total_price` int(11) NOT NULL DEFAULT 0,
  `amount_paid` int(11) NOT NULL DEFAULT 0,
  `payment_status` enum('unpaid','paid','cancelled') NOT NULL DEFAULT 'unpaid',
  `source` enum('online','walkin') NOT NULL DEFAULT 'online',
  `order_code` varchar(40) DEFAULT NULL,
  `expires_at` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_code` (`order_code`),
  KEY `room_id` (`room_id`),
  KEY `user_id` (`user_id`),
  KEY `room_time` (`room_id`,`start_time`,`end_time`),
  KEY `expires_at` (`expires_at`),
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bookings`
--

LOCK TABLES `bookings` WRITE;
/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
INSERT INTO `bookings` VALUES (20,8,'Rahmat Haikal Choa','chanpororo547@gmail.com','081270763036',9,2,0,0,1777816800,1777824000,50000,0,'unpaid','online','GZ-20',NULL,'2026-05-03 07:58:33'),(21,8,'SELINA','donny@gmail.com','081212121212121',12,1,0,0,1777816800,1777820400,55000,0,'unpaid','online','GZ-21',NULL,'2026-05-03 09:02:10'),(22,8,'Rahmat Haika Choa','chanpororo547@gmail.com','081270763036',9,1,0,0,1777816800,1777820400,25000,0,'unpaid','online','GZ-22',NULL,'2026-05-03 09:02:36'),(23,8,'Rahmat Haika Choa','chanpororo547@gmail.com','081270763036',10,1,0,0,1777802400,1777806000,25000,0,'unpaid','online','GZ-23',NULL,'2026-05-03 09:08:15'),(24,24,'Sela','sela@gmail.com','08123456789',12,4,0,0,1777899600,1777914000,220000,0,'unpaid','online','GZ-24',NULL,'2026-05-04 12:39:27'),(25,23,'Wilian Ng Lim','wilian@gmail.com','081270763036',12,1,0,0,1778162400,1778166000,55000,0,'unpaid','online','GZ-25',NULL,'2026-05-07 13:35:53'),(26,22,'Haikal','wwwww@gmail.com','081270763036',12,2,0,0,1778306400,1778313600,110000,0,'unpaid','online','GZ-26',NULL,'2026-05-09 05:22:58'),(28,25,'ARDI','ardi@gmail.com','08121212121212',12,2,0,0,1778392800,1778400000,110000,0,'unpaid','online','GZ-28',NULL,'2026-05-10 05:58:14');
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
  `console_type` enum('PS4','PS5','SWITCH') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `game_id` (`game_id`),
  CONSTRAINT `game_consoles_ibfk_1` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=194 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `game_consoles`
--

LOCK TABLES `game_consoles` WRITE;
/*!40000 ALTER TABLE `game_consoles` DISABLE KEYS */;
INSERT INTO `game_consoles` VALUES (72,28,'PS5'),(73,29,'PS4'),(74,29,'PS5'),(75,30,'PS4'),(76,30,'PS5'),(77,31,'PS5'),(78,32,'PS5'),(79,33,'PS4'),(80,33,'PS5'),(81,34,'PS5'),(82,35,'PS4'),(83,35,'PS5'),(84,36,'PS5'),(85,37,'PS5'),(86,38,'PS4'),(87,39,'PS4'),(88,40,'SWITCH'),(89,41,'SWITCH'),(90,42,'PS5'),(91,43,'PS5'),(92,44,'PS5'),(93,45,'PS4'),(94,45,'PS5'),(95,46,'PS5'),(96,47,'PS5'),(97,48,'PS4'),(98,48,'PS5'),(99,49,'PS5'),(100,50,'SWITCH'),(101,51,'PS5'),(102,52,'PS4'),(103,52,'PS5'),(104,53,'PS5'),(105,54,'PS4'),(106,55,'PS5'),(107,56,'PS4'),(108,56,'PS5'),(109,57,'PS4'),(110,58,'PS4'),(111,59,'SWITCH'),(112,60,'SWITCH'),(113,61,'PS5'),(114,62,'PS4'),(115,62,'PS5'),(116,63,'PS5'),(117,64,'PS5'),(118,65,'PS5'),(119,66,'PS5'),(120,67,'PS5'),(121,68,'PS5'),(122,69,'PS5'),(123,70,'PS5'),(124,71,'PS5'),(125,72,'PS4'),(126,72,'PS5'),(127,73,'PS4'),(128,73,'PS5'),(129,74,'PS5'),(130,74,'SWITCH'),(131,75,'PS4'),(132,76,'PS4'),(133,77,'PS4'),(134,78,'PS4'),(135,79,'SWITCH'),(136,80,'SWITCH'),(137,81,'SWITCH'),(138,82,'SWITCH'),(139,83,'SWITCH'),(140,84,'PS4'),(141,85,'PS5'),(142,86,'PS5'),(143,87,'PS5'),(144,88,'PS4'),(145,88,'PS5'),(146,88,'SWITCH'),(147,89,'PS5'),(148,90,'PS4'),(149,90,'PS5'),(150,91,'PS5'),(151,92,'PS4'),(152,93,'SWITCH'),(153,94,'SWITCH'),(154,95,'SWITCH'),(155,96,'SWITCH'),(156,97,'SWITCH'),(157,98,'SWITCH'),(158,99,'SWITCH'),(159,100,'SWITCH'),(160,101,'SWITCH'),(161,102,'SWITCH'),(162,103,'SWITCH'),(163,104,'PS5'),(164,104,'SWITCH'),(165,105,'PS4'),(166,105,'PS5'),(167,106,'PS5'),(168,107,'PS5'),(169,108,'PS5'),(170,109,'PS5'),(171,110,'PS5'),(172,111,'PS5'),(173,112,'PS5'),(174,113,'PS5'),(175,114,'PS5'),(176,115,'PS5'),(177,116,'PS5'),(178,117,'PS5'),(179,118,'SWITCH'),(180,119,'SWITCH'),(184,121,'PS5'),(185,122,'PS5'),(186,123,'SWITCH'),(187,124,'SWITCH'),(188,125,'SWITCH'),(191,120,'PS4'),(192,120,'PS5'),(193,120,'SWITCH');
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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `games`
--

LOCK TABLES `games` WRITE;
/*!40000 ALTER TABLE `games` DISABLE KEYS */;
INSERT INTO `games` VALUES (28,'FC 26','Sports','cover_fc26.jpg',1,'2026-10-11 17:36:24'),(29,'FC 25','Sports','cover_fc25.jpg',1,'2026-10-11 17:36:24'),(30,'eFootball','Sports','cover_efootball.jpg',1,'2026-10-11 17:36:24'),(31,'NBA 2K26','Sports','cover_nba2k26.jpg',1,'2026-10-11 17:36:24'),(32,'NBA 2K25','Sports','cover_nba2k25.jpg',1,'2026-10-11 17:36:24'),(33,'NBA 2K24','Sports',NULL,1,'2026-10-11 17:36:24'),(34,'WWE 2K25','Sports','cover_wwe2k25.jpg',1,'2026-10-11 17:36:24'),(35,'WWE 2K24','Sports','cover_wwe2k24.jpg',1,'2026-10-11 17:36:24'),(36,'UFC 5','Sports','cover_ufc5.jpg',1,'2026-10-11 17:36:24'),(37,'Rocket League','Sports','cover_rocket_league.jpg',1,'2026-10-11 17:36:24'),(38,'Madden NFL 24','Sports',NULL,1,'2026-10-11 17:36:24'),(39,'Captain Tsubasa: Rise of New Champions','Sports',NULL,1,'2026-10-11 17:36:24'),(40,'Nintendo Switch Sports','Sports','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(41,'Instant Sports','Sports','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(42,'Gran Turismo 7','Racing','cover_gt7.jpg',1,'2026-10-11 17:36:24'),(43,'F1 25','Racing','cover_f1_25.jpg',1,'2026-10-11 17:36:24'),(44,'MotoGP 24','Racing','cover_motogp24.jpg',1,'2026-10-11 17:36:24'),(45,'Crash Team Racing Nitro-Fueled','Racing','cover_ctr.jpg',1,'2026-10-11 17:36:24'),(46,'Sonic Racing: CrossWorlds','Racing','cover_sonic_crossworlds.jpg',1,'2026-10-11 17:36:24'),(47,'Asphalt Legends Unite','Racing','cover_asphalt.jpg',1,'2026-10-11 17:36:24'),(48,'DIRT 5','Racing',NULL,1,'2026-10-11 17:36:24'),(49,'Need for Speed Unbound','Racing',NULL,1,'2026-10-11 17:36:24'),(50,'Mario Kart 8 Deluxe','Racing','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(51,'Tekken 8','Fighting','cover_tekken8.jpg',1,'2026-10-11 17:36:24'),(52,'Tekken 7','Fighting','cover_tekken7.jpg',1,'2026-10-11 17:36:24'),(53,'Mortal Kombat 1','Fighting','cover_mk1.jpg',1,'2026-10-11 17:36:24'),(54,'Mortal Kombat XL','Fighting',NULL,1,'2026-10-11 17:36:24'),(55,'Street Fighter V','Fighting','cover_sf5.jpg',1,'2026-10-11 17:36:24'),(56,'Naruto x Boruto Ultimate Ninja Storm Connections','Fighting','cover_naruto_boruto.jpg',1,'2026-10-11 17:36:24'),(57,'Naruto Shippuden: Ultimate Ninja Storm 4','Fighting',NULL,1,'2026-10-11 17:36:24'),(58,'Dragon Ball FighterZ','Fighting',NULL,1,'2026-10-11 17:36:24'),(59,'Super Smash Bros. Ultimate','Fighting','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(60,'ARMS','Fighting','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(61,'Call of Duty: Black Ops 6','Shooter','cover_cod_bo6.jpg',1,'2026-10-11 17:36:24'),(62,'Call of Duty: Black Ops III','Shooter','cover_cod_bo3.jpg',1,'2026-10-11 17:36:24'),(63,'Battlefield 6','Shooter','cover_battlefield6.jpg',1,'2026-10-11 17:36:24'),(64,'Borderlands 3','Shooter','cover_borderlands3.jpg',1,'2026-10-11 17:36:24'),(65,'Fortnite','Shooter','cover_fortnite.jpg',1,'2026-10-11 17:36:24'),(66,'Back 4 Blood','Shooter','cover_back4blood.jpg',1,'2026-10-11 17:36:24'),(67,'The Ascent','Shooter','cover_the_ascent.jpg',1,'2026-10-11 17:36:24'),(68,'Plants vs. Zombies: Battle for Neighborville','Shooter','cover_pvz.jpg',1,'2026-10-11 17:36:24'),(69,'Ghost of Yotei','Action','cover_ghost_yotei.jpg',1,'2026-10-11 17:36:24'),(70,'Marvel\'s Spider-Man 2','Action','cover_spiderman2.jpg',1,'2026-10-11 17:36:24'),(71,'Assassin\'s Creed Shadows','Action','cover_ac_shadows.jpg',1,'2026-10-11 17:36:24'),(72,'Grand Theft Auto V','Action',NULL,1,'2026-10-11 17:36:24'),(73,'Star Wars Jedi: Fallen Order','Action',NULL,1,'2026-10-11 17:36:24'),(74,'Streets of Rage 4','Action','cover_sor4.jpg',1,'2026-10-11 17:36:24'),(75,'Batman: Arkham Knight','Action',NULL,1,'2026-10-11 17:36:24'),(76,'Batman: Return to Arkham','Action',NULL,1,'2026-10-11 17:36:24'),(77,'Dragon Ball Z: Kakarot','Action',NULL,1,'2026-10-11 17:36:24'),(78,'One Piece: Pirate Warriors 4','Action',NULL,1,'2026-10-11 17:36:24'),(79,'Bayonetta 3','Action','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(80,'Metroid Prime 4: Beyond','Action','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(81,'Monster Hunter Rise: Sunbreak','Action','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(82,'LEGO Star Wars','Action','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(83,'LEGO Marvel Super Heroes','Action','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(84,'Resident Evil 4','Horror',NULL,1,'2026-10-11 17:36:24'),(85,'Resident Evil 3','Horror','cover_re3.jpg',1,'2026-10-11 17:36:24'),(86,'Resident Evil 2','Horror','cover_re2.jpg',1,'2026-10-11 17:36:24'),(87,'The Quarry','Horror','cover_the_quarry.jpg',1,'2026-10-11 17:36:24'),(88,'It Takes Two','Adventure','cover_it_takes_two.jpg',1,'2026-10-11 17:36:24'),(89,'Split Fiction','Adventure','cover_split_fiction.jpg',1,'2026-10-11 17:36:24'),(90,'A Way Out','Adventure','cover_a_way_out.jpg',1,'2026-10-11 17:36:24'),(91,'Hogwarts Legacy','Adventure','cover_hogwarts.jpg',1,'2026-10-11 17:36:24'),(92,'Unravel','Adventure',NULL,1,'2026-10-11 17:36:24'),(93,'The Legend of Zelda: Tears of the Kingdom','Adventure','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(94,'Luigi\'s Mansion 3','Adventure','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(95,'River Tails: Stronger Together','Adventure','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(96,'Pokemon Legends: Z-A','RPG','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(97,'Xenoblade Chronicles X','RPG','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(98,'Fire Emblem Engage','RPG','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(99,'Super Mario Odyssey','Platformer','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(100,'Super Mario 3D World + Bowser\'s Fury','Platformer','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(101,'Donkey Kong Country','Platformer','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(102,'Hollow Knight: Silksong','Platformer','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(103,'Cuphead','Platformer','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(104,'Overcooked! All You Can Eat','Party','cover_overcooked_ayce.jpg',1,'2026-10-11 17:36:24'),(105,'Overcooked! 2','Party','cover_overcooked2.jpg',1,'2026-10-11 17:36:24'),(106,'Moving Out 2','Party','cover_moving_out2.jpg',1,'2026-10-11 17:36:24'),(107,'Moving Out','Party',NULL,1,'2026-10-11 17:36:24'),(108,'Gang Beasts','Party','cover_gang_beasts.jpg',1,'2026-10-11 17:36:24'),(109,'Human: Fall Flat','Party','cover_human_fall_flat.jpg',1,'2026-10-11 17:36:24'),(110,'Fall Guys','Party','cover_fall_guys.jpg',1,'2026-10-11 17:36:24'),(111,'Stumble Guys','Party','cover_stumble_guys.jpg',1,'2026-10-11 17:36:24'),(112,'LEGO Party!','Party','cover_lego_party.jpg',1,'2026-10-11 17:36:24'),(113,'Tricky Towers','Party','cover_tricky_towers.jpg',1,'2026-10-11 17:36:24'),(114,'Rubber Bandits','Party','cover_rubber_bandits.jpg',1,'2026-10-11 17:36:24'),(115,'Get Packed','Party','cover_get_packed.jpg',1,'2026-10-11 17:36:24'),(116,'Crossy Road Castle','Party','cover_crossy_road.jpg',1,'2026-10-11 17:36:24'),(117,'Hamster Playground','Party','cover_hamster.jpg',1,'2026-10-11 17:36:24'),(118,'1-2-Switch','Party','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(119,'Tools Up!','Party','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(120,'Minecraft','Sandbox','cover_minecraft.jpg',1,'2026-10-11 17:36:24'),(121,'Roblox','Sandbox','cover_roblox.jpg',1,'2026-10-11 17:36:24'),(122,'Farm Together 2','Simulation','cover_farm_together2.jpg',1,'2026-10-11 17:36:24'),(123,'Puyo Puyo Tetris','Puzzle','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(124,'Taiko no Tatsujin','Rhythm','cover_switch_games.jpg',1,'2026-10-11 17:36:24'),(125,'Just Dance','Rhythm','cover_switch_games.jpg',1,'2026-10-11 17:36:24');
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
  `category` enum('makanan','snack','minuman') NOT NULL,
  `price` int(11) NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_items`
--

LOCK TABLES `menu_items` WRITE;
/*!40000 ALTER TABLE `menu_items` DISABLE KEYS */;
INSERT INTO `menu_items` VALUES (1,'Indomie Goreng Polos','makanan',12000,NULL,NULL,1,'2026-05-03 11:03:18'),(2,'Mie Kuah','makanan',12000,NULL,NULL,1,'2026-05-03 11:03:34'),(3,'Indomie Goreng Extra','makanan',20000,NULL,NULL,1,'2026-05-03 11:03:51'),(5,'Mie Kuah Extra','makanan',20000,NULL,NULL,1,'2026-05-03 11:04:37'),(6,'Nasi Goreng Ayam','makanan',25000,NULL,NULL,1,'2026-05-03 11:04:51'),(7,'Air Mineral','minuman',5000,NULL,NULL,1,'2026-05-03 11:05:14'),(8,'Minuman Kaleng','minuman',10000,'Sprite / Coca Cola / Fanta',NULL,1,'2026-05-03 11:05:28'),(9,'Indomie Goreng Special','makanan',20000,NULL,NULL,1,'2026-10-11 17:35:47'),(10,'Indomie Goreng Special Extra','makanan',28000,NULL,NULL,1,'2026-10-11 17:35:47'),(11,'Pop Mie','makanan',10000,'Ayam Bawang / Soto Ayam / Kari',NULL,1,'2026-10-11 17:35:47'),(12,'Mie Sedaap Goreng Cup','makanan',10000,NULL,NULL,1,'2026-10-11 17:35:47'),(13,'Nasi Goreng Ayam Extra','makanan',30000,NULL,NULL,1,'2026-10-11 17:35:47'),(14,'Nasi Telur Pontianak','makanan',12000,NULL,NULL,1,'2026-10-11 17:35:47'),(15,'Nasi Telur Pontianak Extra','makanan',15000,NULL,NULL,1,'2026-10-11 17:35:47'),(16,'French Fries','snack',20000,NULL,NULL,1,'2026-10-11 17:35:47'),(17,'Nugget','snack',22000,NULL,NULL,1,'2026-10-11 17:35:47'),(18,'Sosis','snack',20000,NULL,NULL,1,'2026-10-11 17:35:47'),(19,'Karage','snack',45000,NULL,NULL,1,'2026-10-11 17:35:47'),(20,'Egg','snack',5000,NULL,NULL,1,'2026-10-11 17:35:47'),(21,'French Fries + Nugget','snack',22000,NULL,NULL,1,'2026-10-11 17:35:47'),(22,'French Fries + Nugget + Sosis','snack',22000,NULL,NULL,1,'2026-10-11 17:35:47'),(23,'French Fries + Karage','snack',33000,NULL,NULL,1,'2026-10-11 17:35:47'),(24,'Burger Telur','snack',10000,NULL,NULL,1,'2026-10-11 17:35:47'),(25,'Burger Telur + Kentang','snack',20000,NULL,NULL,1,'2026-10-11 17:35:47'),(26,'Burger Ayam','snack',15000,NULL,NULL,1,'2026-10-11 17:35:47'),(27,'Burger Ayam + Kentang','snack',25000,NULL,NULL,1,'2026-10-11 17:35:47'),(28,'Mojito','minuman',25000,'Strawberry / Mangga / Lychee',NULL,1,'2026-10-11 17:35:47'),(29,'Pop Ice','minuman',8000,'Permen Karet / Anggur / Strawberry / Vanilla / Coklat, dll.',NULL,1,'2026-10-11 17:35:47'),(30,'Cappuccino','minuman',12000,NULL,NULL,1,'2026-10-11 17:35:47'),(31,'Teh Pucuk','minuman',8000,NULL,NULL,1,'2026-10-11 17:35:47'),(32,'Pocari Sweat','minuman',13000,NULL,NULL,1,'2026-10-11 17:35:47'),(33,'NU Green Tea','minuman',10000,NULL,NULL,1,'2026-10-11 17:35:47'),(34,'You C1000 Orange Water','minuman',15000,NULL,NULL,1,'2026-10-11 17:35:47'),(35,'Kuku Bima Susu','minuman',8000,NULL,NULL,1,'2026-10-11 17:35:47'),(36,'Lemon Tea','minuman',18000,NULL,NULL,1,'2026-10-11 17:35:47'),(37,'Nipis Madu Soda','minuman',8000,NULL,NULL,1,'2026-10-11 17:35:47'),(38,'Lidah Buaya','minuman',10000,NULL,NULL,1,'2026-10-11 17:35:47'),(39,'Milo','minuman',18000,NULL,NULL,1,'2026-10-11 17:35:47'),(40,'Mizone','minuman',10000,NULL,NULL,1,'2026-10-11 17:35:47'),(41,'Teh Obeng','minuman',6000,NULL,NULL,1,'2026-10-11 17:35:47'),(42,'Teh O','minuman',6000,NULL,NULL,1,'2026-10-11 17:35:47'),(43,'Hot Cappuccino','minuman',12000,NULL,NULL,1,'2026-10-11 17:35:47'),(44,'Hot Milo','minuman',18000,NULL,NULL,1,'2026-10-11 17:35:47'),(45,'Hot Lemon Tea','minuman',18000,NULL,NULL,1,'2026-10-11 17:35:47');
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
  `booking_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` int(11) NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL,
  `status` enum('pending','selesai','batal') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `menu_orders_ibfk_2` (`item_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `menu_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `menu_orders_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`id`),
  CONSTRAINT `menu_orders_ibfk_3` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_orders`
--

LOCK TABLES `menu_orders` WRITE;
/*!40000 ALTER TABLE `menu_orders` DISABLE KEYS */;
INSERT INTO `menu_orders` VALUES (1,8,1,NULL,1,12000,'','selesai','2026-05-03 11:05:54'),(2,24,3,NULL,2,18000,'','selesai','2026-05-04 12:40:13'),(3,24,8,NULL,4,8000,'','selesai','2026-05-04 12:40:27'),(4,23,1,NULL,1,12000,'TIDAK PEDAS','selesai','2026-05-07 13:25:09'),(5,22,7,NULL,1,5000,'','selesai','2026-05-09 18:47:04'),(6,25,3,NULL,1,18000,'Pedas','selesai','2026-05-10 05:59:25');
/*!40000 ALTER TABLE `menu_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rate_limits`
--

DROP TABLE IF EXISTS `rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `rl_key` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `rl_key_time` (`rl_key`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rate_limits`
--

LOCK TABLES `rate_limits` WRITE;
/*!40000 ALTER TABLE `rate_limits` DISABLE KEYS */;
/*!40000 ALTER TABLE `rate_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `room_games`
--

DROP TABLE IF EXISTS `room_games`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `room_games` (
  `room_id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  PRIMARY KEY (`room_id`,`game_id`),
  KEY `game_id` (`game_id`),
  CONSTRAINT `room_games_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `room_games_ibfk_2` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `room_games`
--

LOCK TABLES `room_games` WRITE;
/*!40000 ALTER TABLE `room_games` DISABLE KEYS */;
INSERT INTO `room_games` VALUES (9,28),(9,29),(9,30),(9,31),(9,34),(9,35),(9,37),(9,42),(9,45),(9,46),(9,47),(9,51),(9,62),(9,64),(9,65),(9,66),(9,67),(9,69),(9,71),(9,74),(9,87),(9,88),(9,104),(9,108),(9,109),(9,110),(9,111),(9,112),(9,113),(9,115),(9,116),(9,117),(9,120),(9,121),(10,28),(10,29),(10,30),(10,31),(10,62),(10,67),(10,69),(10,104),(11,28),(11,31),(11,32),(11,45),(11,51),(11,88),(11,90),(11,104),(11,106),(12,28),(12,32),(12,45),(12,51),(12,88),(12,90),(12,104),(12,106),(13,28),(13,31),(13,32),(13,36),(13,45),(13,52),(13,53),(13,104),(13,106),(14,28),(14,31),(14,32),(14,36),(14,45),(14,52),(14,53),(14,104),(14,106),(15,28),(15,31),(15,36),(15,43),(15,63),(15,88),(15,89),(15,104),(15,108),(15,112),(15,113),(15,114),(15,122),(16,40),(16,41),(16,50),(16,59),(16,60),(16,74),(16,79),(16,80),(16,81),(16,82),(16,83),(16,88),(16,93),(16,94),(16,95),(16,96),(16,97),(16,98),(16,99),(16,100),(16,101),(16,102),(16,103),(16,104),(16,118),(16,119),(16,120),(16,123),(16,124),(16,125),(17,40),(17,41),(17,50),(17,59),(17,60),(17,74),(17,79),(17,80),(17,81),(17,82),(17,83),(17,88),(17,93),(17,94),(17,95),(17,96),(17,97),(17,98),(17,99),(17,100),(17,101),(17,102),(17,103),(17,104),(17,118),(17,119),(17,120),(17,123),(17,124),(17,125),(18,28),(18,31),(18,36),(18,43),(18,63),(18,88),(18,89),(18,104),(18,108),(18,112),(18,113),(18,114),(18,122),(19,28),(19,30),(19,31),(19,32),(19,36),(19,42),(19,44),(19,45),(19,51),(19,53),(19,55),(19,56),(19,61),(19,64),(19,68),(19,70),(19,85),(19,86),(19,88),(19,89),(19,91),(19,105),(19,106),(20,28),(20,30),(20,31),(20,32),(20,36),(20,42),(20,44),(20,45),(20,51),(20,53),(20,55),(20,56),(20,61),(20,64),(20,68),(20,70),(20,85),(20,86),(20,88),(20,89),(20,91),(20,105),(20,106),(21,40),(21,41),(21,50),(21,59),(21,60),(21,74),(21,79),(21,80),(21,81),(21,82),(21,83),(21,88),(21,93),(21,94),(21,95),(21,96),(21,97),(21,98),(21,99),(21,100),(21,101),(21,102),(21,103),(21,104),(21,118),(21,119),(21,120),(21,123),(21,124),(21,125),(22,29),(22,30),(22,33),(22,38),(22,48),(22,56),(22,72),(22,75),(22,84),(22,88),(22,90),(22,105),(22,120),(23,29),(23,30),(23,33),(23,38),(23,48),(23,56),(23,72),(23,75),(23,84),(23,88),(23,90),(23,105),(23,120),(24,28),(24,33),(24,35),(24,48),(24,49),(24,51),(24,72),(24,73),(24,88),(24,105),(24,107),(25,29),(25,30),(25,39),(25,45),(25,48),(25,54),(25,58),(25,62),(25,72),(25,75),(25,78),(25,88),(25,90),(25,105),(26,29),(26,30),(26,45),(26,48),(26,54),(26,62),(26,72),(26,76),(26,78),(26,88),(26,90),(26,105),(27,29),(27,33),(27,48),(27,52),(27,58),(27,73),(27,75),(27,88),(27,90),(27,105),(28,29),(28,33),(28,45),(28,48),(28,52),(28,58),(28,73),(28,75),(28,88),(28,90),(28,105),(29,29),(29,30),(29,33),(29,39),(29,48),(29,54),(29,57),(29,58),(29,72),(29,75),(29,77),(29,88),(29,90),(29,92),(29,105),(30,28),(30,33),(30,48),(30,49),(30,51),(30,56),(30,72),(30,73),(30,88),(30,105),(30,107),(31,29),(31,30),(31,33),(31,35),(31,38),(31,39),(31,48),(31,54),(31,57),(31,72),(31,76),(31,88),(31,90),(31,105);
/*!40000 ALTER TABLE `room_games` ENABLE KEYS */;
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
  `category` enum('private','private_pro','public') NOT NULL DEFAULT 'private',
  `tier` varchar(10) NOT NULL DEFAULT 'VIP',
  `console_type` enum('PS4','PS5','SWITCH') NOT NULL DEFAULT 'PS5',
  `console_model` varchar(30) NOT NULL DEFAULT 'PS5',
  `price` int(11) NOT NULL,
  `weekend_extra` int(11) NOT NULL DEFAULT 0,
  `nintendo_extra` int(11) DEFAULT NULL,
  `max_players` tinyint(4) NOT NULL DEFAULT 2,
  `has_netflix` tinyint(1) NOT NULL DEFAULT 0,
  `image` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `status` enum('available','unavailable') NOT NULL DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rooms`
--

LOCK TABLES `rooms` WRITE;
/*!40000 ALTER TABLE `rooms` DISABLE KEYS */;
INSERT INTO `rooms` VALUES (9,'Room 1','private','VVIP','PS5','PS5',45000,5000,5000,4,1,NULL,'Ruangan VVIP dengan PS5 dan Netflix untuk 4 pemain. Bisa tambah Nintendo Switch.','available','2026-02-03 15:43:10'),(10,'Room 2','private','VVIP','PS5','PS5',40000,5000,NULL,4,1,NULL,'Ruangan VVIP dengan PS5 dan Netflix untuk 4 pemain.','available','2026-05-03 08:41:59'),(11,'Room 3','private','VIP','PS5','PS5',30000,5000,NULL,2,1,NULL,'Ruangan VIP dengan PS5 dan Netflix untuk 2 pemain.','available','2026-05-03 08:42:46'),(12,'Room 4','private','VIP','PS5','PS5',30000,5000,NULL,2,1,NULL,'Ruangan VIP dengan PS5 dan Netflix untuk 2 pemain.','available','2026-05-03 08:43:26'),(13,'Room 5','private','VIP','PS5','PS5',30000,5000,NULL,2,1,NULL,'Ruangan VIP dengan PS5 dan Netflix untuk 2 pemain.','available','2026-10-11 17:35:47'),(14,'Room 6','private','VIP','PS5','PS5',30000,5000,NULL,2,1,NULL,'Ruangan VIP dengan PS5 dan Netflix untuk 2 pemain.','available','2026-10-11 17:35:47'),(15,'Room 7','private_pro','VIP','PS5','PS5 Slim',35000,5000,10000,2,1,NULL,'Ruangan Private Pro dengan PS5 Slim dan Netflix. Bisa tambah Nintendo Switch.','available','2026-10-11 17:35:47'),(16,'Room 8','private_pro','VIP','SWITCH','Nintendo Switch',30000,5000,NULL,2,1,NULL,'Ruangan Private Pro dengan Nintendo Switch dan Netflix.','available','2026-10-11 17:35:47'),(17,'Room 9','private_pro','VIP','SWITCH','Nintendo Switch',30000,5000,NULL,2,1,NULL,'Ruangan Private Pro dengan Nintendo Switch dan Netflix.','available','2026-10-11 17:35:47'),(18,'Room 10','private_pro','VIP','PS5','PS5 Slim',35000,5000,10000,2,1,NULL,'Ruangan Private Pro dengan PS5 Slim dan Netflix. Bisa tambah Nintendo Switch.','available','2026-10-11 17:35:47'),(19,'Room 11','private_pro','VIP','PS5','PS5 Pro',45000,5000,10000,2,1,NULL,'Ruangan Private Pro dengan PS5 Pro dan Netflix. Bisa tambah Nintendo Switch.','available','2026-10-11 17:35:47'),(20,'Room 12','private_pro','VIP','PS5','PS5 Pro',45000,5000,10000,2,1,NULL,'Ruangan Private Pro dengan PS5 Pro dan Netflix. Bisa tambah Nintendo Switch.','available','2026-10-11 17:35:47'),(21,'Room 13','private_pro','VIP','SWITCH','Nintendo Switch',30000,5000,NULL,2,1,NULL,'Ruangan Private Pro dengan Nintendo Switch dan Netflix.','available','2026-10-11 17:35:47'),(22,'Meja A','public','Public','PS4','PS4 Slim',12000,3000,NULL,2,0,NULL,'Meja area public dengan PS4 Slim.','available','2026-10-11 17:35:47'),(23,'Meja B','public','Public','PS4','PS4 Slim',12000,3000,NULL,2,0,NULL,'Meja area public dengan PS4 Slim.','available','2026-10-11 17:35:47'),(24,'Meja C','public','Public','PS5','PS5',25000,3000,NULL,2,0,NULL,'Meja area public dengan PS5.','available','2026-10-11 17:35:47'),(25,'Meja D','public','Public','PS4','PS4 Pro',15000,3000,NULL,2,0,NULL,'Meja area public dengan PS4 Pro.','available','2026-10-11 17:35:47'),(26,'Meja E','public','Public','PS4','PS4 Slim',12000,3000,NULL,2,0,NULL,'Meja area public dengan PS4 Slim.','available','2026-10-11 17:35:47'),(27,'Meja F','public','Public','PS4','PS4 Slim',12000,3000,NULL,2,0,NULL,'Meja area public dengan PS4 Slim.','available','2026-10-11 17:35:47'),(28,'Meja G','public','Public','PS4','PS4 Slim',12000,3000,NULL,2,0,NULL,'Meja area public dengan PS4 Slim.','available','2026-10-11 17:35:47'),(29,'Meja H','public','Public','PS4','PS4 Slim',12000,3000,NULL,2,0,NULL,'Meja area public dengan PS4 Slim.','available','2026-10-11 17:35:47'),(30,'Meja I','public','Public','PS5','PS5',25000,3000,NULL,2,0,NULL,'Meja area public dengan PS5.','available','2026-10-11 17:35:47'),(31,'Meja J','public','Public','PS4','PS4 Pro',15000,3000,NULL,2,0,NULL,'Meja area public dengan PS4 Pro.','available','2026-10-11 17:35:47');
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
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (8,'DONNY','user','$2y$10$FyOZFIzqKxU7hk2nH9dnhuUnTQGZtKLA0aqBORoCSKq.rw/C23eWG','2026-01-07 15:02:46','2026-07-26 07:53:41'),(16,'WILLIAN','user','$2y$10$NI4CkXtAnsCL4QoBgbhgJ.lrWMCuOy0Odvnj3yqloeK/mk11la016','2026-01-15 14:12:55','2026-01-18 16:49:43'),(17,'haziq','user','$2y$10$H0T7pVuWnfd1.TQam1XaT.fWmqsGe8Ggl3Yky6oacoibtYXqk5Hwi','2026-01-17 10:43:25','2026-01-17 17:43:35'),(18,'sela','user','$2y$10$nmp9uMVJeCgVc3ZvjZV9nebgkERmonT6KGWRNHDTMdgvHc2hH/S/u','2026-01-17 10:49:46','2026-01-17 17:50:00'),(19,'CELA','user','$2y$10$EUfq.eZKFxL20SelTcfxre1CR/Vo8eiNS/k7OmouKToFZIIihctJW','2026-01-18 09:53:19','2026-01-18 20:26:05'),(20,'DEVID','user','$2y$10$s0O0jaWztSKy2SPxEvPVKOHvdgkV6RNnQr1ZNzdyqDnmYYkAct8Pi','2026-01-22 12:38:12',NULL),(21,'SELINA','user','$2y$10$XXHiXgZDDZr8/Adlwns7..ozuRlf2AkMpkVbtyxjxLgQ7TdKQegb.','2026-01-25 09:56:00','2026-01-25 16:56:30'),(22,'HAIKAL','admin','$2y$10$zUUv/QVEZNzRwGYVDT5odeAsIQunU/sAjplfjdNUlwH8eAh9w42D6','2026-05-03 10:00:36','2026-05-21 23:37:13'),(23,'WILIAN','user','$2y$10$1Z1pkIKTDMARQvCsw4j4kegSASQVBMwV1/C2SLS29vhenyP1OXcrG','2026-05-03 15:01:16','2026-05-09 13:40:13'),(24,'SelaCantik','user','$2y$10$JTAr1Xl56NYxAb2fGP7Qv.tnxCTJ6zayNHlOc4QF09z2VSlzyqSe.','2026-05-04 12:37:49','2026-05-04 19:39:35'),(25,'ARDI','user','$2y$10$64vfk2iQ.kxsqH/5R.olr.4g/ENKt84q.dk5CXGOqndVqzjAm3E/S','2026-05-10 05:57:13','2026-05-10 13:16:23'),(26,'JEREMY','user','$2y$10$/sMqP.r6iMReSwGCeod5QeXZK6iCjB7ks3f0DWNtSkhLJLtnAtjAm','2026-05-14 06:01:35','2026-05-21 22:48:45');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-12 22:51:59
