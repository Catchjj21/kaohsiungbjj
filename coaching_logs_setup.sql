-- Coaching Logs Table Setup
-- Run this script in phpMyAdmin to create the coaching_logs table

USE kaohsiungbjj;

-- Create coaching_logs table
CREATE TABLE IF NOT EXISTS coaching_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coach_id INT NOT NULL,
    class_id INT,
    class_date DATE NOT NULL,
    techniques_taught TEXT,
    attendance_count INT DEFAULT 0,
    what_went_well TEXT,
    improvements_needed TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    INDEX idx_coach_date (coach_id, class_date),
    INDEX idx_class_date (class_id, class_date)
);

-- Create training_logs table if it doesn't exist (for consistency with training_log.php)
CREATE TABLE IF NOT EXISTS training_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    booking_id INT,
    session_date DATE NOT NULL,
    type VARCHAR(50) NOT NULL,
    topic_covered TEXT,
    partners TEXT,
    rating INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    INDEX idx_user_date (user_id, session_date),
    INDEX idx_booking (booking_id)
);
