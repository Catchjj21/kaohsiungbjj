-- Safe Payment Database Schema Fix
-- This script checks for existing fields before adding them

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

-- Create payment_history table only if it doesn't exist
SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'payment_history') = 0,
    'CREATE TABLE payment_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        membership_id INT NULL,
        payment_amount DECIMAL(10,2) NOT NULL,
        payment_date DATE NOT NULL,
        payment_method VARCHAR(50),
        payment_status ENUM("completed", "pending", "failed") DEFAULT "completed",
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE SET NULL
    )',
    'SELECT "payment_history table already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

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

SET @sql = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
     WHERE TABLE_SCHEMA = DATABASE() 
     AND TABLE_NAME = 'payment_history' 
     AND INDEX_NAME = 'idx_payment_history_user_date') = 0,
    'CREATE INDEX idx_payment_history_user_date ON payment_history(user_id, payment_date)',
    'SELECT "idx_payment_history_user_date already exists" as message'
));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Set default values for existing records (safe updates)
UPDATE memberships SET payment_status = 'pending' WHERE payment_status IS NULL;
UPDATE users SET payment_status = 'active' WHERE payment_status IS NULL;

-- Show final status
SELECT 'Payment database setup completed successfully! All fields and tables are now ready.' as status;
