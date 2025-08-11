-- Fixed Payment Database Schema
-- This script handles foreign key data type compatibility issues

-- First, let's check the data types of existing columns
SELECT 
    TABLE_NAME, 
    COLUMN_NAME, 
    DATA_TYPE, 
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
AND TABLE_NAME IN ('users', 'memberships')
AND COLUMN_NAME IN ('id', 'user_id')
ORDER BY TABLE_NAME, COLUMN_NAME;

-- Check and add payment fields to memberships table (only if they don't exist)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'memberships' 
     AND COLUMN_NAME = 'payment_due_date') = 0,
    'ALTER TABLE memberships ADD COLUMN payment_due_date DATE NULL AFTER end_date',
    'SELECT "payment_due_date already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'memberships' 
     AND COLUMN_NAME = 'payment_status') = 0,
    'ALTER TABLE memberships ADD COLUMN payment_status ENUM("paid", "pending", "overdue") DEFAULT "pending" AFTER payment_due_date',
    'SELECT "payment_status already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'memberships' 
     AND COLUMN_NAME = 'last_payment_date') = 0,
    'ALTER TABLE memberships ADD COLUMN last_payment_date DATE NULL AFTER payment_status',
    'SELECT "last_payment_date already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'memberships' 
     AND COLUMN_NAME = 'payment_amount') = 0,
    'ALTER TABLE memberships ADD COLUMN payment_amount DECIMAL(10,2) NULL AFTER last_payment_date',
    'SELECT "payment_amount already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add payment fields to users table (only if they don't exist)
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'payment_status') = 0,
    'ALTER TABLE users ADD COLUMN payment_status ENUM("active", "suspended", "overdue") DEFAULT "active" AFTER is_verified',
    'SELECT "users.payment_status already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND COLUMN_NAME = 'last_payment_date') = 0,
    'ALTER TABLE users ADD COLUMN last_payment_date DATE NULL AFTER payment_status',
    'SELECT "users.last_payment_date already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop payment_history table if it exists (to recreate with correct data types)
DROP TABLE IF EXISTS payment_history;

-- Create payment_history table with compatible data types
CREATE TABLE payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    membership_id INT NULL,
    payment_amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50),
    payment_status ENUM('completed', 'pending', 'failed') DEFAULT 'completed',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_membership_id (membership_id),
    INDEX idx_payment_date (payment_date)
);

-- Create indexes only if they don't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'memberships' 
     AND INDEX_NAME = 'idx_memberships_payment_status') = 0,
    'CREATE INDEX idx_memberships_payment_status ON memberships(payment_status, payment_due_date)',
    'SELECT "idx_memberships_payment_status already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'users' 
     AND INDEX_NAME = 'idx_users_payment_status') = 0,
    'CREATE INDEX idx_users_payment_status ON users(payment_status)',
    'SELECT "idx_users_payment_status already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set default values for existing records (safe updates)
UPDATE memberships SET payment_status = 'pending' WHERE payment_status IS NULL;
UPDATE users SET payment_status = 'active' WHERE payment_status IS NULL;

-- Show final status
SELECT 'Payment database setup completed successfully! All fields and tables are now ready.' as status;
