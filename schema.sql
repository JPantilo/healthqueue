-- HealthQueue schema (MySQL). Import via phpMyAdmin.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS centers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) DEFAULT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  hours VARCHAR(100) DEFAULT 'Mon-Fri 8:00 AM - 5:00 PM'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  google_id VARCHAR(64) NOT NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  picture VARCHAR(255) DEFAULT NULL,
  role ENUM('patient','worker','admin') NOT NULL DEFAULT 'patient',
  center_id INT DEFAULT NULL,
  google_refresh_token TEXT DEFAULT NULL,
  email_verified TINYINT(1) NOT NULL DEFAULT 1,
  verification_token VARCHAR(64) DEFAULT NULL,
  verification_expires DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS services (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS appointments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  center_id INT NOT NULL,
  service_id INT NOT NULL,
  appt_date DATE NOT NULL,
  appt_time TIME NOT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  queue_no INT NOT NULL,
  status ENUM('pending','waiting','in_consultation','done','no_show','cancelled') NOT NULL DEFAULT 'pending',
  gcal_event_id VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (center_id) REFERENCES centers(id),
  FOREIGN KEY (service_id) REFERENCES services(id),
  INDEX idx_day (center_id, appt_date),
  UNIQUE KEY uq_queue (center_id, appt_date, queue_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Departments a worker handles (a worker can have several).
CREATE TABLE IF NOT EXISTS worker_services (
  user_id INT NOT NULL,
  service_id INT NOT NULL,
  PRIMARY KEY (user_id, service_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Caches OpenWeatherMap forecasts for 30 minutes to stay within the free API quota.
CREATE TABLE IF NOT EXISTS weather_cache (
  center_id INT PRIMARY KEY,
  payload MEDIUMTEXT NOT NULL,
  fetched_at DATETIME NOT NULL,
  FOREIGN KEY (center_id) REFERENCES centers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Services offered at Iligan City health centers.
INSERT INTO services (name) VALUES
 ('General Consultation'),
 ('Prenatal Checkup'),
 ('Child Immunization'),
 ('Family Planning'),
 ('TB / DOTS'),
 ('Blood Pressure and Diabetes Check'),
 ('Dental');

-- Iligan City centers. Coordinates are approximate (barangay level):
-- confirm each pin on OpenStreetMap and edit lat/lng and hours with the real values.
INSERT INTO centers (name, address, lat, lng, hours) VALUES
 ('City Health Office - Iligan', 'Don Gregorio T. Lluch Ave. (Quezon Ave.), Pala-o, Iligan City', 8.2290000, 124.2480000, 'Mon-Fri 8:00 AM - 5:00 PM'),
 ('Tubod Health Center', 'Brgy. Tubod, Iligan City', 8.2120000, 124.2330000, 'Mon-Fri 8:00 AM - 5:00 PM'),
 ('Tibanga Health Center', 'Brgy. Tibanga, Iligan City', 8.2410000, 124.2560000, 'Mon-Fri 8:00 AM - 5:00 PM'),
 ('Santiago Health Center', 'Brgy. Santiago, Iligan City', 8.2370000, 124.2610000, 'Mon-Fri 8:00 AM - 5:00 PM');
