-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 11, 2026 at 05:47 PM
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
-- Table structure for table `assets`
--

CREATE TABLE `assets` (
  `asset_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'other',
  `value` decimal(15,2) NOT NULL DEFAULT 0.00,
  `note` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assets`
--

INSERT INTO `assets` (`asset_id`, `user_id`, `name`, `category`, `value`, `note`, `created_at`, `updated_at`) VALUES
(1, 4, 'Equity bank savings', 'cash', 5000000.00, 'Account last updated on 30/05/2026', '2026-05-07 09:31:21', '2026-05-07 09:31:21');

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL COMMENT 'e.g. transaction.create, budget.delete',
  `target_type` varchar(50) DEFAULT NULL COMMENT 'e.g. transaction, budget, goal',
  `target_id` int(11) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`log_id`, `user_id`, `action`, `target_type`, `target_id`, `old_values`, `new_values`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 4, 'export.pdf', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":0}', '::1', NULL, '2026-05-11 12:00:18'),
(2, 4, 'export.pdf', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":0}', '::1', NULL, '2026-05-11 12:00:50'),
(3, 4, 'export.csv', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":0}', '::1', NULL, '2026-05-11 12:00:55'),
(4, 4, 'export.csv', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":0}', '::1', NULL, '2026-05-11 13:20:33'),
(5, 4, 'export.pdf', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":0}', '::1', NULL, '2026-05-11 13:30:19'),
(6, 4, 'export.pdf', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":5}', '::1', NULL, '2026-05-11 13:47:12'),
(7, 4, 'export.csv', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":5}', '::1', NULL, '2026-05-11 13:49:49'),
(8, 4, 'export.pdf', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":5}', '::1', NULL, '2026-05-11 13:49:51'),
(9, 4, 'export.pdf', 'transactions', NULL, NULL, '{\"from\":\"2026-04-23\",\"to\":\"2026-05-11\",\"count\":19}', '::1', NULL, '2026-05-11 13:50:10'),
(10, 4, 'export.pdf', 'transactions', NULL, NULL, '{\"from\":\"2026-04-23\",\"to\":\"2026-05-11\",\"count\":19}', '::1', NULL, '2026-05-11 14:04:06'),
(11, 4, 'export.pdf', 'transactions', NULL, NULL, '{\"from\":\"2026-05-01\",\"to\":\"2026-05-11\",\"count\":5}', '::1', NULL, '2026-05-11 14:04:21');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `month` year(4) DEFAULT NULL,
  `daily_limit` decimal(15,2) DEFAULT NULL COMMENT 'optional daily spending cap',
  `monthly_limit` decimal(15,2) DEFAULT NULL COMMENT 'optional monthly spending cap (replaces amount_limit usage)',
  `yearly_limit` decimal(15,2) DEFAULT NULL COMMENT 'optional yearly spending cap'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `budgets`
--

INSERT INTO `budgets` (`budget_id`, `user_id`, `category`, `amount_limit`, `current_spent`, `month`, `daily_limit`, `monthly_limit`, `yearly_limit`) VALUES
(2, 11, 'Food', 5000.00, 7277.00, NULL, NULL, NULL, NULL),
(3, 5, 'Food', 5000.00, 0.00, NULL, NULL, NULL, NULL),
(4, 11, 'Food', 1000.00, 6777.00, NULL, NULL, NULL, NULL),
(5, 4, 'Food', 5000.00, 0.00, NULL, NULL, NULL, NULL),
(6, 4, 'Transport', 2000.00, 0.00, NULL, NULL, NULL, NULL),
(7, 4, 'Utilities', 4500.00, 0.00, NULL, NULL, NULL, NULL),
(8, 4, 'Entertainment', 3000.00, 0.00, NULL, NULL, NULL, NULL),
(9, 4, 'Shopping', 4500.00, 0.00, NULL, NULL, NULL, NULL),
(10, 4, 'Health', 1000.00, 0.00, NULL, NULL, NULL, NULL),
(11, 4, 'Other', 2000.00, 0.00, NULL, NULL, NULL, NULL),
(12, 4, 'Food', 5000.00, 0.00, NULL, NULL, NULL, NULL),
(13, 4, 'Transport', 2000.00, 0.00, NULL, NULL, NULL, NULL),
(14, 4, 'Utilities', 4500.00, 0.00, NULL, NULL, NULL, NULL),
(15, 4, 'Entertainment', 3000.00, 0.00, NULL, NULL, NULL, NULL),
(16, 4, 'Shopping', 4500.00, 0.00, NULL, NULL, NULL, NULL),
(17, 4, 'Health', 1000.00, 0.00, NULL, NULL, NULL, NULL),
(18, 4, 'Other', 2000.00, 0.00, NULL, NULL, NULL, NULL),
(19, 4, 'Food', 5000.00, 0.00, NULL, NULL, NULL, NULL),
(20, 4, 'Transport', 2000.00, 0.00, NULL, NULL, NULL, NULL),
(21, 4, 'Utilities', 4500.00, 0.00, NULL, NULL, NULL, NULL),
(22, 4, 'Entertainment', 3000.00, 0.00, NULL, NULL, NULL, NULL),
(23, 4, 'Shopping', 4500.00, 0.00, NULL, NULL, NULL, NULL),
(24, 4, 'Health', 1000.00, 0.00, NULL, NULL, NULL, NULL),
(25, 4, 'Other', 2000.00, 0.00, NULL, NULL, NULL, NULL),
(26, 4, 'Food', 5000.00, 0.00, NULL, NULL, NULL, NULL),
(27, 4, 'Transport', 2000.00, 0.00, NULL, NULL, NULL, NULL),
(28, 4, 'Utilities', 4500.00, 0.00, NULL, NULL, NULL, NULL),
(29, 4, 'Entertainment', 3000.00, 0.00, NULL, NULL, NULL, NULL),
(30, 4, 'Shopping', 4500.00, 0.00, NULL, NULL, NULL, NULL),
(31, 4, 'Health', 1000.00, 0.00, NULL, NULL, NULL, NULL),
(32, 4, 'Other', 2000.00, 0.00, NULL, NULL, NULL, NULL),
(33, 4, 'Food', 5000.00, 0.00, NULL, 100.00, NULL, NULL),
(34, 4, 'Transport', 2000.00, 0.00, NULL, 200.00, NULL, NULL),
(35, 4, 'Utilities', 4500.00, 0.00, NULL, 100.00, NULL, NULL),
(36, 4, 'Entertainment', 3000.00, 0.00, NULL, 100.00, NULL, NULL),
(37, 4, 'Shopping', 4500.00, 0.00, NULL, 100.00, NULL, NULL),
(38, 4, 'Health', 1000.00, 0.00, NULL, 150.00, NULL, NULL),
(39, 4, 'Other', 2000.00, 0.00, NULL, 100.00, NULL, NULL),
(40, 4, 'Food', 5000.00, 0.00, NULL, 100.00, NULL, NULL),
(41, 4, 'Transport', 2000.00, 0.00, NULL, 200.00, NULL, NULL),
(42, 4, 'Utilities', 4500.00, 0.00, NULL, 100.00, NULL, NULL),
(43, 4, 'Entertainment', 3000.00, 0.00, NULL, 100.00, NULL, NULL),
(44, 4, 'Shopping', 4500.00, 0.00, NULL, 100.00, NULL, NULL),
(45, 4, 'Health', 1000.00, 0.00, NULL, 150.00, NULL, NULL),
(46, 4, 'Other', 2000.00, 0.00, NULL, 100.00, NULL, NULL),
(47, 4, 'Food', 5000.00, 0.00, NULL, 100.00, NULL, NULL),
(48, 4, 'Transport', 2000.00, 0.00, NULL, 200.00, NULL, NULL),
(49, 4, 'Utilities', 4500.00, 0.00, NULL, 100.00, NULL, NULL),
(50, 4, 'Entertainment', 3000.00, 0.00, NULL, 100.00, NULL, NULL),
(51, 4, 'Shopping', 4500.00, 0.00, NULL, 100.00, NULL, NULL),
(52, 4, 'Health', 1000.00, 0.00, NULL, 150.00, NULL, NULL),
(53, 4, 'Other', 2000.00, 0.00, NULL, 100.00, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `budget_alerts`
--

CREATE TABLE `budget_alerts` (
  `alert_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `budget_id` int(11) NOT NULL,
  `threshold` tinyint(3) UNSIGNED DEFAULT 80 COMMENT 'percentage e.g. 80 = alert at 80%',
  `sent_at` datetime DEFAULT NULL COMMENT 'last time alert was sent',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `budget_over_notifications`
--

CREATE TABLE `budget_over_notifications` (
  `id` int(11) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `category` varchar(100) NOT NULL,
  `period_type` enum('daily','monthly','yearly') NOT NULL,
  `period_key` varchar(20) NOT NULL COMMENT 'YYYY-MM-DD / YYYY-MM / YYYY',
  `limit_amount` decimal(15,2) NOT NULL,
  `spent_amount` decimal(15,2) NOT NULL,
  `notified_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `debts_loans`
--

CREATE TABLE `debts_loans` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `record_type` enum('debt','loan') NOT NULL COMMENT 'debt = I owe someone, loan = someone owes me',
  `title` varchar(255) NOT NULL,
  `counterparty` varchar(255) NOT NULL COMMENT 'lender name (debt) or borrower name (loan)',
  `principal` decimal(15,2) NOT NULL,
  `amount_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `interest_rate` decimal(5,2) DEFAULT NULL COMMENT 'annual % — NULL = interest-free',
  `due_date` date DEFAULT NULL,
  `status` enum('active','paid','overdue','written_off') NOT NULL DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `debts_loans`
--

INSERT INTO `debts_loans` (`id`, `user_id`, `record_type`, `title`, `counterparty`, `principal`, `amount_paid`, `interest_rate`, `due_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(1, 4, 'debt', 'car loan', 'Barclays bank', 1500000.00, 1260000.00, 9.00, '2026-10-10', 'active', '', '2026-04-30 11:12:49', '2026-05-11 07:16:22'),
(2, 4, 'loan', 'House decor', 'Fred', 250000.00, 55000.00, 6.00, '2026-07-24', 'active', '', '2026-04-30 11:14:58', '2026-04-30 11:14:58'),
(5, 4, 'debt', 'House decor', 'Barclays bank', 4500000.00, 4500000.00, 4.00, NULL, 'paid', '', '2026-04-30 11:27:19', '2026-04-30 11:27:19');

-- --------------------------------------------------------

--
-- Table structure for table `debt_payments`
--

CREATE TABLE `debt_payments` (
  `payment_id` int(11) NOT NULL,
  `debt_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `paid_on` date NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `debt_payments`
--

INSERT INTO `debt_payments` (`payment_id`, `debt_id`, `user_id`, `amount`, `paid_on`, `note`, `created_at`) VALUES
(1, 1, 4, 500000.00, '2026-05-07', 'cash', '2026-05-07 06:38:42'),
(2, 1, 4, 10000.00, '2026-05-11', '', '2026-05-11 07:16:22');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `email` varchar(100) NOT NULL,
  `attempt_count` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `last_attempt` datetime NOT NULL DEFAULT current_timestamp(),
  `locked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `net_worth_snapshots`
--

CREATE TABLE `net_worth_snapshots` (
  `snapshot_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `snapshot_date` date NOT NULL,
  `total_assets` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_liabilities` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_worth` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `net_worth_snapshots`
--

INSERT INTO `net_worth_snapshots` (`snapshot_id`, `user_id`, `snapshot_date`, `total_assets`, `total_liabilities`, `net_worth`) VALUES
(1, 4, '2026-05-07', 5001000.00, 945000.00, 4056000.00),
(3, 4, '2026-05-11', 5001000.00, 445000.00, 4556000.00);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `plan_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(5) DEFAULT 'KES',
  `provider` enum('paystack','stripe') NOT NULL,
  `provider_ref` varchar(255) NOT NULL,
  `status` enum('pending','success','failed') DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recurring_transactions`
--

CREATE TABLE `recurring_transactions` (
  `recurring_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` varchar(10) NOT NULL DEFAULT 'expense',
  `category` varchar(50) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `frequency` enum('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
  `next_due` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `recurring_transactions`
--

INSERT INTO `recurring_transactions` (`recurring_id`, `user_id`, `name`, `type`, `category`, `amount`, `frequency`, `next_due`, `description`, `is_active`, `created_at`) VALUES
(1, 4, 'ANN MUSYOKI', 'expense', 'Netflix', 350.00, 'monthly', '2026-05-30', '', 1, '2026-04-30 08:32:04');

-- --------------------------------------------------------

--
-- Table structure for table `savings_goals`
--

CREATE TABLE `savings_goals` (
  `goal_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `emoji` varchar(10) DEFAULT '?',
  `target_amount` decimal(15,2) NOT NULL,
  `current_saved` decimal(15,2) DEFAULT 0.00,
  `deadline` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `savings_goals`
--

INSERT INTO `savings_goals` (`goal_id`, `user_id`, `name`, `emoji`, `target_amount`, `current_saved`, `deadline`, `created_at`) VALUES
(1, 4, 'HOUSE DECOR', '🏠', 4000.00, 1000.00, '2026-10-20', '2026-04-29 13:33:18');

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
(24, 11, 'expense', 5000.00, 'entertainment ', '2026-04-18', 0, '2026-04-18 15:16:53', NULL),
(25, 11, 'Expense', 500.00, 'Food', '2026-04-18', 0, '2026-04-18 15:26:07', NULL),
(26, 11, 'Expense', 1500.00, 'Shopping', '2026-04-18', 0, '2026-04-18 15:29:20', NULL),
(27, 11, 'Expense', 6777.00, 'Food', '2026-04-18', 0, '2026-04-18 15:39:32', NULL),
(28, 11, 'income', 50000.00, 'Salary', '2026-04-18', 0, '2026-04-18 16:12:13', NULL),
(29, 11, 'expense', 5000.00, 'Traveling', '2026-04-18', 0, '2026-04-18 16:53:40', NULL),
(30, 11, 'expense', 500.00, 'Traveling', '2026-04-18', 0, '2026-04-18 17:55:03', NULL),
(31, 11, 'expense', 677.00, 'laptop', '2026-04-18', 0, '2026-04-18 20:47:41', NULL),
(32, 11, 'income', 40000.00, 'salary', '2026-04-19', 0, '2026-04-18 22:05:19', NULL),
(33, 11, 'income', 5000.00, 'salary', '2026-04-21', 0, '2026-04-20 21:03:19', NULL),
(34, 4, 'income', 100.00, 'Salary', '2026-04-24', 0, '2026-04-24 08:06:21', NULL),
(35, 4, 'income', 500.00, 'Other', '2026-04-24', 0, '2026-04-23 21:00:00', 'received from friend'),
(36, 4, 'income', 500.00, 'Other', '2026-04-24', 0, '2026-04-23 21:00:00', 'received from friend'),
(37, 4, 'income', 500.00, 'Other', '2026-04-24', 0, '2026-04-23 21:00:00', 'received from friend'),
(38, 4, 'expense', 500.00, 'Entertainment', '2026-04-25', 0, '2026-04-24 21:00:00', 'movies'),
(39, 4, 'expense', 500.00, 'Entertainment', '2026-04-25', 0, '2026-04-24 21:00:00', 'movies'),
(40, 4, 'expense', 500.00, 'Entertainment', '2026-04-25', 0, '2026-04-24 21:00:00', 'movies'),
(41, 4, 'expense', 500.00, 'Entertainment', '2026-04-25', 0, '2026-04-24 21:00:00', 'movies'),
(42, 4, 'expense', 500.00, 'Entertainment', '2026-04-25', 0, '2026-04-24 21:00:00', 'movies'),
(43, 4, 'expense', 500.00, 'Entertainment', '2026-04-25', 0, '2026-04-24 21:00:00', 'movies'),
(44, 4, 'expense', 500.00, 'Entertainment', '2026-04-25', 0, '2026-04-24 21:00:00', 'movies'),
(45, 4, 'income', 5000.00, 'Shares', '2026-04-25', 0, '2026-04-24 21:00:00', 'Telkom shares'),
(46, 4, 'income', 15000.00, 'Salary', '2026-04-28', 0, '2026-04-27 21:00:00', 'web design'),
(47, 4, 'expense', 2500.00, 'Home Decor', '2026-04-29', 0, '2026-04-28 21:00:00', 'Plants'),
(48, 4, 'income', 55000.00, 'Salary', '0000-00-00', 0, '2026-05-10 21:00:00', ''),
(49, 4, 'expense', 350.00, 'Eating Out', '0000-00-00', 0, '2026-05-10 21:00:00', ''),
(50, 4, 'expense', 3000.00, 'Household Supplies', '0000-00-00', 0, '2026-05-10 21:00:00', ''),
(51, 4, 'income', 233.00, 'Salary', '0000-00-00', 0, '2026-05-10 21:00:00', ''),
(52, 4, 'expense', 250.00, 'Eating Out', '0000-00-00', 0, '2026-05-10 21:00:00', '');

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

INSERT INTO `users` (`user_id`, `full_name`, `username`, `name`, `email`, `phone`, `password`, `role`, `status`, `warnings`, `status_message`, `profile_pic`, `is_verified`, `is_active`, `language`, `theme`) VALUES
(4, 'STEVE MUSYOKI', NULL, 'STEVE MUSYOKI', 'stephenmusyoki792@gmail.com', '0788909090', '$2y$10$aXpDLb84.EzFfuXszFn5aOPy4V1oK.XjfiMga9pKn9WkKpFQRs1xe', 'admin', 'active', 0, '', '1777446965_4.jpeg', 0, 0, 'en', 'dark'),
(5, '', NULL, 'ANN MUSYOKI', 'Annmusyoki2323@gmail.com', '0748850990', '$2y$12$78m2lbpobPFVOkMXda6aNuhvJGagfi6z4xoOxpdrYG3zQonV4xsXm', 'user', 'active', 0, NULL, 'default.png', 1, 0, 'en', 'auto'),
(10, '', NULL, 'ANN MUSYOKI', 'stevhbjhvfg@gmail.com', '0748850990', '$2y$10$VSpygP1BluhwMjLVVo4XouCWBBf7QtQKG/2O5BzdQmM87qVPrzPCK', 'user', 'active', 0, NULL, 'default.png', 0, 0, 'en', 'auto'),
(11, 'will smith', NULL, '', 'marleymusyoki@gmail.com', '0748850990', '$2y$10$iimOY9V0.lEOfkbbuKNBselGFxd./B68miOQARAH3rcrbAAoOJp6.', 'user', 'active', 0, NULL, '1776540987_New Project (2).jpg', 0, 0, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assets`
--
ALTER TABLE `assets`
  ADD PRIMARY KEY (`asset_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user_action` (`user_id`,`action`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_user_created` (`user_id`,`created_at`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`budget_id`),
  ADD KEY `idx_user_category` (`user_id`,`category`);

--
-- Indexes for table `budget_alerts`
--
ALTER TABLE `budget_alerts`
  ADD PRIMARY KEY (`alert_id`),
  ADD UNIQUE KEY `uk_user_budget` (`user_id`,`budget_id`),
  ADD KEY `idx_user` (`user_id`);

--
-- Indexes for table `budget_over_notifications`
--
ALTER TABLE `budget_over_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notify` (`user_id`,`category`,`period_type`,`period_key`);

--
-- Indexes for table `debts_loans`
--
ALTER TABLE `debts_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_user_status` (`user_id`,`status`);

--
-- Indexes for table `debt_payments`
--
ALTER TABLE `debt_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `debt_id` (`debt_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `net_worth_snapshots`
--
ALTER TABLE `net_worth_snapshots`
  ADD PRIMARY KEY (`snapshot_id`),
  ADD UNIQUE KEY `uq_user_date` (`user_id`,`snapshot_date`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_ref` (`provider_ref`);

--
-- Indexes for table `recurring_transactions`
--
ALTER TABLE `recurring_transactions`
  ADD PRIMARY KEY (`recurring_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_user_active` (`user_id`,`is_active`);

--
-- Indexes for table `savings_goals`
--
ALTER TABLE `savings_goals`
  ADD PRIMARY KEY (`goal_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `idx_user_date` (`user_id`,`date`),
  ADD KEY `idx_user_type` (`user_id`,`type`);

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
-- AUTO_INCREMENT for table `assets`
--
ALTER TABLE `assets`
  MODIFY `asset_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `budget_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `budget_alerts`
--
ALTER TABLE `budget_alerts`
  MODIFY `alert_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `budget_over_notifications`
--
ALTER TABLE `budget_over_notifications`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `debts_loans`
--
ALTER TABLE `debts_loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
;

--
-- AUTO_INCREMENT for table `net_worth_snapshots`
--
ALTER TABLE `net_worth_snapshots`
  MODIFY `snapshot_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recurring_transactions`
--
ALTER TABLE `recurring_transactions`
  MODIFY `recurring_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `savings_goals`
--
ALTER TABLE `savings_goals`
  MODIFY `goal_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

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
-- Constraints for table `savings_goals`
--
ALTER TABLE `savings_goals`
  ADD CONSTRAINT `goals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
