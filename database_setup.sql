-- ERP Portal Database Schema
-- You can copy and run this directly in phpMyAdmin SQL tab.

-- Create database if it does not already exist, and switch to it
CREATE DATABASE IF NOT EXISTS `erp_portal`;
USE `erp_portal`;

-- 1. Users Table
-- Stores user credentials, roles, departments, statuses, and key stats
CREATE TABLE IF NOT EXISTS `users` (
  `userid` VARCHAR(50) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `role` VARCHAR(50) NOT NULL, -- Roles include: 'employee', 'Hr_admin', 'Technical_admin'
  `department` VARCHAR(100) DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'Active', -- User status: 'Active', 'Inactive', etc.
  `leaves_left` INT DEFAULT 15, -- Available leave days remaining for the employee
  `weekly_hours` FLOAT DEFAULT 0.0, -- Total tracked work hours in the current week
  PRIMARY KEY (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Attendance Table
-- Records user clock-in and clock-out timestamps with computed daily durations
CREATE TABLE IF NOT EXISTS `attendance` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `userid` VARCHAR(50) NOT NULL,
  `date` DATE NOT NULL,
  `punch_in` DATETIME DEFAULT NULL,
  `punch_out` DATETIME DEFAULT NULL,
  `duration` FLOAT DEFAULT 0.0, -- Computed duration in hours
  FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Leaves Table
-- Tracks leave applications made by employees and their current approval status
CREATE TABLE IF NOT EXISTS `leaves` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `userid` VARCHAR(50) NOT NULL,
  `leave_type` VARCHAR(100) NOT NULL,
  `from_date` DATE NOT NULL,
  `to_date` DATE NOT NULL,
  `reason` TEXT NOT NULL,
  `status` VARCHAR(50) DEFAULT 'Pending', -- Statuses: 'Pending', 'Approved', 'Rejected'
  FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Login Logs Table
-- Keeps track of session login and logout events for audit and tracking purposes
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `userid` VARCHAR(50) NOT NULL,
  `login_time` DATETIME NOT NULL,
  `logout_time` DATETIME DEFAULT NULL,
  FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Chat Memory Table
-- Stores message logs between users and the AI chatbot to provide conversational context
CREATE TABLE IF NOT EXISTS `chat_memory` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `userid` VARCHAR(50) NOT NULL,
  `sender` VARCHAR(10) NOT NULL, -- Sender type: 'user' or 'ai'
  `message` TEXT NOT NULL,
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. AI Performance & Logs Table
-- Logs AI-generated responses, developer audits, hallucination metrics, and review statuses
CREATE TABLE IF NOT EXISTS `ai_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `userid` VARCHAR(50) NOT NULL,
  `query` TEXT NOT NULL,
  `response` TEXT NOT NULL,
  `edited_response` TEXT DEFAULT NULL,
  `review_status` VARCHAR(50) DEFAULT 'Original', -- Review flags: 'Original', 'Edited_by_HR', 'Approved_by_Tech'
  `review_notes` TEXT DEFAULT NULL,
  `performance_score` FLOAT DEFAULT 95.0, -- AI output quality metric (percentage)
  `hallucination_detected` TINYINT DEFAULT 0, -- Binary flag indicating if AI hallucinated (0 = No, 1 = Yes)
  `deviation_score` FLOAT DEFAULT 2.0, -- Deviation score relative to expected response (percentage)
  `upvotes` INT DEFAULT 0,   -- Thumbs-up feedback count (denormalised)
  `downvotes` INT DEFAULT 0, -- Thumbs-down feedback count (denormalised)
  `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6b. AI Feedback Table
-- Stores individual thumbs-up/down votes by users for each AI response
CREATE TABLE IF NOT EXISTS `ai_feedback` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `log_id` INT NOT NULL,
  `userid` VARCHAR(50) NOT NULL,
  `feedback` ENUM('up', 'down') NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_feedback (log_id, userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6c. AI Overrides Table
-- Stores HR-approved corrected responses that are embedded in ChromaDB for override retrieval
CREATE TABLE IF NOT EXISTS `ai_overrides` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `log_id` INT NOT NULL,                         -- Reference to the ai_logs entry that was overridden
  `query` TEXT NOT NULL,                          -- Original query this override answers
  `override_response` TEXT NOT NULL,             -- Corrected response approved by Tech Admin
  `override_id` VARCHAR(36) NOT NULL UNIQUE,     -- UUID used as ChromaDB document ID in hr_overrides collection
  `created_by` VARCHAR(50) NOT NULL,             -- Tech Admin userid who pushed the override
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_override_id (override_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Knowledge Base Table
-- Stores reference files and articles uploaded to feed the AI chatbot or assist employees
CREATE TABLE IF NOT EXISTS `knowledge_base` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `status` VARCHAR(50) DEFAULT 'Draft', -- Publication state: 'Draft', 'Submitted', 'Accepted'
  `uploaded_by` VARCHAR(50) NOT NULL,
  `accepted_by` VARCHAR(50) DEFAULT NULL,
  `rag_document_id` VARCHAR(36) DEFAULT NULL, -- UUID of document in RAG DB
  `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`userid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- INSERT DEFAULT DATA FOR TESTING
-- Set up default accounts with the password '123456' for HR, Employee, and Tech roles
INSERT INTO `users` (`userid`, `name`, `password`, `email`, `role`, `department`, `status`, `leaves_left`, `weekly_hours`) VALUES
('1001', 'XYZ', '123456', 'xyz@company.com', 'employee', 'IT', 'Active', 10, 38.5),
('1002', 'HR Manager', '123456', 'hr@company.com', 'Hr_admin', 'Human Resources', 'Active', 12, 40.0),
('1003', 'Tech Admin', '123456', 'tech@company.com', 'Technical_admin', 'Technical Operations', 'Active', 14, 42.0);

-- Insert starter knowledge base entries
INSERT INTO `knowledge_base` (`title`, `file_path`, `status`, `uploaded_by`, `accepted_by`) VALUES
('Leave Policy 2026', 'policies/leave_policy_2026.pdf', 'Accepted', '1002', '1003'),
('IT Security Guidelines', 'policies/it_security_guidelines.pdf', 'Submitted', '1002', NULL),
('Attendance Rules', 'policies/attendance_rules.pdf', 'Draft', '1002', NULL);

