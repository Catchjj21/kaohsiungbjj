-- Fix Payment Database Schema
-- Run this script in phpMyAdmin to add all missing payment fields

-- Add payment fields to memberships table
ALTER TABLE memberships 
ADD COLUMN payment_due_date DATE NULL AFTER end_date;

ALTER TABLE memberships 
ADD COLUMN payment_status ENUM('paid', 'pending', 'overdue') DEFAULT 'pending' AFTER payment_due_date;

ALTER TABLE memberships 
ADD COLUMN last_payment_date DATE NULL AFTER payment_status;

ALTER TABLE memberships 
ADD COLUMN payment_amount DECIMAL(10,2) NULL AFTER last_payment_date;

-- Add payment fields to users table
ALTER TABLE users 
ADD COLUMN payment_status ENUM('active', 'suspended', 'overdue') DEFAULT 'active' AFTER is_verified;

ALTER TABLE users 
ADD COLUMN last_payment_date DATE NULL AFTER payment_status;

-- Create payment_history table
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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (membership_id) REFERENCES memberships(id) ON DELETE SET NULL
);

-- Create indexes for better performance
CREATE INDEX idx_memberships_payment_status ON memberships(payment_status, payment_due_date);
CREATE INDEX idx_users_payment_status ON users(payment_status);
CREATE INDEX idx_payment_history_user_date ON payment_history(user_id, payment_date);

-- Set default values for existing records
UPDATE memberships SET payment_status = 'pending' WHERE payment_status IS NULL;
UPDATE users SET payment_status = 'active' WHERE payment_status IS NULL;

-- Show confirmation
SELECT 'Payment database setup completed successfully!' as status;
