-- Database Setup Script for Kaohsiung BJJ
-- Run this script in phpMyAdmin to create the database structure

-- Create the database
CREATE DATABASE IF NOT EXISTS kaohsiungbjj CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kaohsiungbjj;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone_number VARCHAR(20),
    belt_color VARCHAR(50),
    member_type ENUM('student', 'adult', 'child') DEFAULT 'student',
    role ENUM('member', 'coach', 'admin', 'parent') DEFAULT 'member',
    profile_picture_url VARCHAR(500),
    dob DATE,
    line_id VARCHAR(100),
    address TEXT,
    chinese_name VARCHAR(100),
    default_language ENUM('en', 'zh') DEFAULT 'en',
    old_card VARCHAR(100),
    parent_id INT,
    is_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(255),
    last_announcement_viewed_id INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Membership plans table
CREATE TABLE membership_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_name VARCHAR(100) NOT NULL,
    plan_name_zh VARCHAR(100),
    category VARCHAR(50),
    duration_days INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    description TEXT,
    description_zh TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Memberships table
CREATE TABLE memberships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    membership_type VARCHAR(100) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    class_credits INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Classes table
CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_zh VARCHAR(100),
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    capacity INT DEFAULT 20,
    age ENUM('all', 'adult', 'child') DEFAULT 'all',
    coach_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Bookings table
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    class_id INT NOT NULL,
    booking_date DATE NOT NULL,
    status ENUM('confirmed', 'cancelled', 'waitlist') DEFAULT 'confirmed',
    attended BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    UNIQUE KEY unique_booking (user_id, class_id, booking_date)
);

-- Waitlist table
CREATE TABLE waitlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    class_id INT NOT NULL,
    booking_date DATE NOT NULL,
    request_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Events table
CREATE TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    title_zh VARCHAR(200),
    description TEXT,
    description_zh TEXT,
    event_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    location VARCHAR(200),
    capacity INT,
    price DECIMAL(10,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Event registrations table
CREATE TABLE event_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('confirmed', 'cancelled') DEFAULT 'confirmed',
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_registration (event_id, user_id)
);

-- Messages table
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    subject VARCHAR(200),
    message TEXT NOT NULL,
    thread_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (thread_id) REFERENCES messages(id) ON DELETE CASCADE
);

-- Message recipients table
CREATE TABLE message_recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    recipient_id INT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Password resets table
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) UNIQUE NOT NULL,
    expires TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Site content table
CREATE TABLE site_content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200),
    title_zh VARCHAR(200),
    content TEXT,
    content_zh TEXT,
    image_url VARCHAR(500),
    publish_date DATE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Coach availability table
CREATE TABLE coach_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    coach_id INT NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    price DECIMAL(10,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE
);

-- One-to-one bookings table
CREATE TABLE one_to_one_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    coach_id INT NOT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') DEFAULT 'pending',
    confirmation_token VARCHAR(255),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (coach_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Training log table
CREATE TABLE training_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    log_date DATE NOT NULL,
    technique_name VARCHAR(200),
    technique_name_zh VARCHAR(200),
    notes TEXT,
    notes_zh TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Insert default admin user (password: admin123)
INSERT INTO users (first_name, last_name, email, password_hash, role, is_verified) 
VALUES ('Admin', 'User', 'admin@kaohsiungbjj.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', TRUE);

-- Insert sample membership plans
INSERT INTO membership_plans (plan_name, plan_name_zh, category, duration_days, price, description, description_zh) VALUES
('Monthly Adult', '成人月費', 'adult', 30, 2500.00, 'Monthly membership for adults', '成人月費會員'),
('Monthly Child', '兒童月費', 'child', 30, 2000.00, 'Monthly membership for children', '兒童月費會員'),
('Quarterly Adult', '成人季費', 'adult', 90, 6500.00, 'Quarterly membership for adults', '成人季費會員'),
('Quarterly Child', '兒童季費', 'child', 90, 5000.00, 'Quarterly membership for children', '兒童季費會員'),
('Annual Adult', '成人年費', 'adult', 365, 22000.00, 'Annual membership for adults', '成人年費會員'),
('Annual Child', '兒童年費', 'child', 365, 18000.00, 'Annual membership for children', '兒童年費會員');

-- Insert sample classes
INSERT INTO classes (name, name_zh, day_of_week, start_time, end_time, is_active, capacity, age) VALUES
('Adult BJJ', '成人巴西柔術', 'Monday', '19:00:00', '20:30:00', TRUE, 20, 'adult'),
('Adult BJJ', '成人巴西柔術', 'Wednesday', '19:00:00', '20:30:00', TRUE, 20, 'adult'),
('Adult BJJ', '成人巴西柔術', 'Friday', '19:00:00', '20:30:00', TRUE, 20, 'adult'),
('Kids BJJ', '兒童巴西柔術', 'Tuesday', '17:00:00', '18:00:00', TRUE, 15, 'child'),
('Kids BJJ', '兒童巴西柔術', 'Thursday', '17:00:00', '18:00:00', TRUE, 15, 'child'),
('Kids BJJ', '兒童巴西柔術', 'Saturday', '10:00:00', '11:00:00', TRUE, 15, 'child');
