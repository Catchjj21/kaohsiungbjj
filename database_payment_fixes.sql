-- Payment System Fixes for Kaohsiung BJJ
-- Add payment tracking fields to existing tables

-- Add payment fields to memberships table
ALTER TABLE memberships 
ADD COLUMN payment_due_date DATE NULL AFTER end_date,
ADD COLUMN payment_status ENUM('paid', 'pending', 'overdue') DEFAULT 'pending' AFTER payment_due_date,
ADD COLUMN last_payment_date DATE NULL AFTER payment_status,
ADD COLUMN payment_amount DECIMAL(10,2) NULL AFTER last_payment_date;

-- Add payment fields to users table for general payment tracking
ALTER TABLE users 
ADD COLUMN payment_status ENUM('active', 'suspended', 'overdue') DEFAULT 'active' AFTER is_verified,
ADD COLUMN last_payment_date DATE NULL AFTER payment_status;

-- Create payment_history table for tracking all payments
CREATE TABLE payment_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id INT NOT NULL,
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
