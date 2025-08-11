# Coaching Log Feature

## Overview
The Coaching Log feature allows coaches to track what they taught in their classes, reflect on what went well, identify areas for improvement, and maintain a comprehensive record of their teaching progress.

## Features

### 1. Class Logging
- **Automatic Class Detection**: The system automatically detects classes that coaches have taught but haven't logged yet
- **Manual Entry**: Coaches can manually add log entries for classes not automatically detected
- **Attendance Tracking**: Record the number of students who attended each class

### 2. Teaching Reflection
- **Techniques Taught**: Document what techniques were covered in each class
- **What Went Well**: Reflect on positive aspects of the class (student engagement, energy, etc.)
- **Areas for Improvement**: Identify what could be done better in future classes
- **Additional Notes**: Add any other observations or notes about the class

### 3. History and Review
- **Log History**: View all past coaching logs in chronological order
- **Edit Entries**: Modify existing log entries to update information
- **Delete Entries**: Remove log entries if needed

## Database Structure

### coaching_logs Table
```sql
CREATE TABLE coaching_logs (
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
```

## Setup Instructions

### 1. Database Setup
Run the setup script to create the necessary database tables:
```
http://your-domain/setup_coaching_logs.php
```

This script will:
- Create the `coaching_logs` table
- Create the `training_logs` table (if it doesn't exist)
- Verify that all tables are properly set up

### 2. Access the Feature
Coaches can access the coaching log feature through:
- **Coach Dashboard**: Click on "Coaching Log" card
- **Direct URL**: `http://your-domain/coach/coaching_log.php`

## User Interface

### Coach Dashboard Integration
The coaching log feature is integrated into the coach dashboard with:
- A dedicated card for "Coaching Log"
- Clear description of the feature's purpose
- Direct link to the coaching log page

### Coaching Log Page
The main coaching log page includes:
- **New Classes to Log**: Shows classes that haven't been logged yet
- **Log History**: Displays all past coaching logs
- **Add Manual Entry**: Button to manually add log entries
- **Edit/Delete**: Options to modify existing entries

### Log Entry Modal
When creating or editing a log entry, coaches can fill in:
- **Class Information**: Automatically populated for detected classes
- **Techniques Taught**: What was covered in the class
- **Attendance Count**: Number of students present
- **What Went Well**: Positive aspects of the class
- **Areas for Improvement**: What could be done better
- **Additional Notes**: Any other observations

## Language Support
The coaching log feature supports both English and Chinese:
- **English**: Default language
- **Chinese**: Available through language switcher
- **Bilingual Interface**: All text elements support both languages

## Security
- **Authentication Required**: Only logged-in coaches can access the feature
- **Role-Based Access**: Restricted to users with 'coach' or 'admin' roles
- **Data Isolation**: Coaches can only view and edit their own logs
- **Input Validation**: All form inputs are validated and sanitized

## Benefits for Coaches

### 1. Teaching Improvement
- **Self-Reflection**: Regular reflection on teaching effectiveness
- **Progress Tracking**: Monitor improvement over time
- **Best Practices**: Document what works well for future reference

### 2. Class Planning
- **Technique History**: Review what techniques have been taught recently
- **Student Engagement**: Track what keeps students engaged
- **Class Structure**: Identify effective class formats

### 3. Professional Development
- **Teaching Portfolio**: Build a record of teaching experience
- **Performance Review**: Data for coaching evaluations
- **Skill Development**: Focus on areas that need improvement

## Technical Implementation

### Files Created/Modified
1. **`coach/coaching_log.php`**: Main coaching log page
2. **`coach/coach_dashboard.php`**: Added coaching log card
3. **`setup_coaching_logs.php`**: Database setup script
4. **`coaching_logs_setup.sql`**: SQL table creation script
5. **`COACHING_LOG_FEATURE.md`**: This documentation

### AJAX Endpoints
- `get_unlogged_classes`: Fetch classes that need to be logged
- `get_log_history`: Retrieve coaching log history
- `save_log_entry`: Create or update log entries
- `delete_log_entry`: Remove log entries
- `get_class_attendance`: Get attendance count for a class

### Database Relationships
- **coaching_logs.coach_id** → **users.id**: Links logs to coaches
- **coaching_logs.class_id** → **classes.id**: Links logs to specific classes
- **bookings.class_id** → **classes.id**: Used to detect attended classes

## Future Enhancements
Potential improvements for the coaching log feature:
1. **Analytics Dashboard**: Visual charts showing teaching trends
2. **Student Feedback Integration**: Include student feedback in logs
3. **Photo/Video Upload**: Allow coaches to attach media to logs
4. **Export Functionality**: Export logs to PDF or CSV
5. **Collaborative Logging**: Allow multiple coaches to contribute to logs
6. **Template System**: Pre-defined templates for common class types
7. **Reminder System**: Notifications for unlogged classes
8. **Advanced Filtering**: Filter logs by date range, class type, etc.

## Support
For technical support or feature requests, please contact the development team.
