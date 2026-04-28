-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 28, 2026 at 12:03 PM
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
-- Database: `finance_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `budgets`
--

CREATE TABLE `budgets` (
  `budget_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `amount_limit` decimal(15,2) DEFAULT NULL,
  `current_spent` decimal(15,2) DEFAULT 0.00,
  `month` year(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `budgets`
--

INSERT INTO `budgets` (`budget_id`, `user_id`, `category`, `amount_limit`, `current_spent`, `month`) VALUES
(2, 11, 'Food', 5000.00, 7277.00, NULL),
(3, 5, 'Food', 5000.00, 0.00, NULL),
(4, 11, 'Food', 1000.00, 6777.00, NULL),
(5, 4, 'Food', 5000.00, 0.00, NULL),
(6, 4, 'Transport', 2000.00, 0.00, NULL),
(7, 4, 'Utilities', 4500.00, 0.00, NULL),
(8, 4, 'Entertainment', 3000.00, 0.00, NULL),
(9, 4, 'Shopping', 4500.00, 0.00, NULL),
(10, 4, 'Health', 1000.00, 0.00, NULL),
(11, 4, 'Other', 2000.00, 0.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(10) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `date` date NOT NULL,
  `is_deleted_by_user` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `user_id`, `type`, `amount`, `category`, `date`, `is_deleted_by_user`, `created_at`, `description`) VALUES
(16, 5, 'income', 60000.00, 'salary', '2026-04-14', 0, '2026-04-18 15:16:53', NULL),
(17, 5, 'expense', 5600.00, 'rent', '2026-04-15', 0, '2026-04-18 15:16:53', NULL),
(18, 5, 'expense', 3500.00, 'car', '2026-04-15', 0, '2026-04-18 15:16:53', NULL),
(19, 5, 'expense', 6757.00, 'entertainment ', '2026-04-15', 0, '2026-04-18 15:16:53', NULL),
(20, 5, 'debt', 7000.00, 'loan', '2026-04-15', 0, '2026-04-18 15:16:53', NULL),
(21, 5, 'investment', 20000.00, 'stocks', '2026-04-15', 0, '2026-04-18 15:16:53', NULL),
(22, 5, 'investment', 20000.00, 'stocks', '2026-04-15', 0, '2026-04-18 15:16:53', NULL),
(23, 11, 'expense', 5000.00, 'entertainment ', '2026-04-18', 0, '2026-04-18 15:16:53', NULL),
(24, 11, 'expense', 5000.00, 'entertainment ', '0000-00-00', 0, '2026-04-18 15:16:53', NULL),
(25, 11, 'Expense', 500.00, 'Food', '0000-00-00', 0, '2026-04-18 15:26:07', NULL),
(26, 11, 'Expense', 1500.00, 'Shopping', '0000-00-00', 0, '2026-04-18 15:29:20', NULL),
(27, 11, 'Expense', 6777.00, 'Food', '0000-00-00', 0, '2026-04-18 15:39:32', NULL),
(28, 11, 'income', 50000.00, 'Salary', '2026-04-18', 0, '2026-04-18 16:12:13', NULL),
(29, 11, 'expense', 5000.00, 'Traveling', '0000-00-00', 0, '2026-04-18 16:53:40', NULL),
(30, 11, 'expense', 500.00, 'Traveling', '0000-00-00', 0, '2026-04-18 17:55:03', NULL),
(31, 11, 'expense', 677.00, 'laptop', '0000-00-00', 0, '2026-04-18 20:47:41', NULL),
(32, 11, 'income', 40000.00, 'salary', '0000-00-00', 0, '2026-04-18 22:05:19', NULL),
(33, 11, 'income', 5000.00, 'salary', '0000-00-00', 0, '2026-04-20 21:03:19', NULL),
(34, 4, 'income', 100.00, 'Salary', '0000-00-00', 0, '2026-04-24 08:06:21', NULL),
(35, 4, 'income', 500.00, 'Other', '0000-00-00', 0, '2026-04-23 21:00:00', 'received from friend'),
(36, 4, 'income', 500.00, 'Other', '0000-00-00', 0, '2026-04-23 21:00:00', 'received from friend'),
(37, 4, 'income', 500.00, 'Other', '0000-00-00', 0, '2026-04-23 21:00:00', 'received from friend'),
(38, 4, 'expense', 500.00, 'Entertainment', '0000-00-00', 0, '2026-04-24 21:00:00', 'movies'),
(39, 4, 'expense', 500.00, 'Entertainment', '0000-00-00', 0, '2026-04-24 21:00:00', 'movies'),
(40, 4, 'expense', 500.00, 'Entertainment', '0000-00-00', 0, '2026-04-24 21:00:00', 'movies'),
(41, 4, 'expense', 500.00, 'Entertainment', '0000-00-00', 0, '2026-04-24 21:00:00', 'movies'),
(42, 4, 'expense', 500.00, 'Entertainment', '0000-00-00', 0, '2026-04-24 21:00:00', 'movies'),
(43, 4, 'expense', 500.00, 'Entertainment', '0000-00-00', 0, '2026-04-24 21:00:00', 'movies'),
(44, 4, 'expense', 500.00, 'Entertainment', '0000-00-00', 0, '2026-04-24 21:00:00', 'movies'),
(45, 4, 'income', 5000.00, 'Shares', '0000-00-00', 0, '2026-04-24 21:00:00', 'Telkom shares');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `otp_code` varchar(10) DEFAULT NULL,
  `role` varchar(10) DEFAULT 'user',
  `status` varchar(10) DEFAULT 'active',
  `warnings` int(11) DEFAULT 0,
  `status_message` text DEFAULT NULL,
  `profile_pic` varchar(255) DEFAULT 'default.png',
  `is_verified` int(11) DEFAULT 0,
  `is_active` int(11) DEFAULT 0,
  `language` varchar(10) DEFAULT 'en',
  `theme` varchar(10) DEFAULT 'auto'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `username`, `name`, `email`, `phone`, `password`, `otp_code`, `role`, `status`, `warnings`, `status_message`, `profile_pic`, `is_verified`, `is_active`, `language`, `theme`) VALUES
(4, '', NULL, 'STEVE MUSYOKI', 'stephenmusyoki792@gmail.com', NULL, '$2y$10$aXpDLb84.EzFfuXszFn5aOPy4V1oK.XjfiMga9pKn9WkKpFQRs1xe', NULL, 'admin', 'active', 0, NULL, 'default.png', 0, 0, 'en', 'auto'),
(5, '', NULL, 'ANN MUSYOKI', 'Annmusyoki2323@gmail.com', '0748850990', 'Angel mwende21', NULL, 'user', 'active', 0, NULL, 'default.png', 1, 0, 'en', 'auto'),
(10, '', NULL, 'ANN MUSYOKI', 'stevhbjhvfg@gmail.com', '0748850990', '$2y$10$VSpygP1BluhwMjLVVo4XouCWBBf7QtQKG/2O5BzdQmM87qVPrzPCK', '488183', 'user', 'active', 0, NULL, 'default.png', 0, 0, 'en', 'auto'),
(11, 'will smith', NULL, '', 'marleymusyoki@gmail.com', '0748850990', '$2y$10$iimOY9V0.lEOfkbbuKNBselGFxd./B68miOQARAH3rcrbAAoOJp6.', NULL, 'user', 'active', 0, NULL, '1776540987_New Project (2).jpg', 0, 0, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`budget_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `budget_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `budgets`
--
ALTER TABLE `budgets`
  ADD CONSTRAINT `budgets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
