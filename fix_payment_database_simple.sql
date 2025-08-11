-- Simple Payment Database Fix
-- This script doesn't require information_schema access

-- Add payment fields to memberships table (with error handling)
ALTER TABLE memberships ADD COLUMN payment_due_date DATE NULL AFTER end_date;
ALTER TABLE memberships ADD COLUMN payment_status ENUM('paid', 'pending', 'overdue') DEFAULT 'pending' AFTER payment_due_date;
ALTER TABLE memberships ADD COLUMN last_payment_date DATE NULL AFTER payment_status;
ALTER TABLE memberships ADD COLUMN payment_amount DECIMAL(10,2) NULL AFTER last_payment_date;

-- Add payment fields to users table
ALTER TABLE users ADD COLUMN payment_status ENUM('active', 'suspended', 'overdue') DEFAULT 'active' AFTER is_verified;
ALTER TABLE users ADD COLUMN last_payment_date DATE NULL AFTER payment_status;

-- Drop payment_history table if it exists
DROP TABLE IF EXISTS payment_history;

-- Create payment_history table without foreign key constraints
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

-- Create performance indexes
CREATE INDEX idx_memberships_payment_status ON memberships(payment_status, payment_due_date);
CREATE INDEX idx_users_payment_status ON users(payment_status);

-- Set default values for existing records
UPDATE memberships SET payment_status = 'pending' WHERE payment_status IS NULL;
UPDATE users SET payment_status = 'active' WHERE payment_status IS NULL;

-- Show completion message
SELECT 'Payment database setup completed successfully!' as status;
