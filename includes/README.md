# 🅿️ Parking Management System - Technical Documentation

This guide provides the necessary steps to configure the environment and understand the database architecture of the Parking Management System.

---

## 🛠️ 1. Initial Setup & Troubleshooting
If your database is not connecting despite correct code, the most common issue is that the PHP driver for PostgreSQL is disabled in your server environment.

### Enable PostgreSQL Extensions
Navigate to your Apache configuration folder:
`C:\wamp64\bin\apache\apache2.4.62.1\bin`

Open the **php.ini** (or the symlink file) and ensure the following lines are **uncommented** (remove the semicolon `;` at the beginning):

```ini
extension=pdo_pgsql
extension=pgsql
```

> **Note:** After making these changes, you **must** restart all services in your WAMP/XAMPP panel.

---

## 🔐 2. Environment Configuration
Create a `.env` file in the root directory of your project. This file stores sensitive credentials and prevents them from being hardcoded.

```env
DB_HOST=localhost
DB_PORT=5432
DB_NAME=parking_db
DB_USER=postgres
DB_PASS="Your_Secret_Password"
```

---

## 🔌 3. Database Connection Snippet
Below is the standard method for establishing a secure connection using the **PDO (PHP Data Objects)** extension.

```php
<?php
// includes/db.php
try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "Lidhja u krye!";
} catch (PDOException $e) {
    die($e->getMessage());
}
```

---

## 📊 4. Database Schema
The system architecture consists of five core tables designed for high performance and data integrity.

### 1. Parking Spots (`parking_spots`)
| Field | Data Type | Constraints / Notes |
| :--- | :--- | :--- |
| **id** | SERIAL | Primary Key |
| **spot_number** | VARCHAR | Unique (e.g., A1, B2, VIP1) |
| **status** | ENUM | 'available', 'occupied', 'maintenance' |

### 2. Vehicles (`vehicles`)
| Field | Data Type | Constraints / Notes |
| :--- | :--- | :--- |
| **id** | SERIAL | Primary Key |
| **license_plate** | VARCHAR | Unique, Indexed for fast search |
| **user_id** | INT | Foreign Key (References Users) |

### 3. Parking Sessions (`parking_sessions`)
| Field | Data Type | Constraints / Notes |
| :--- | :--- | :--- |
| **id** | SERIAL | Primary Key |
| **vehicle_id** | INT | Foreign Key (References Vehicles) |
| **spot_id** | INT | Foreign Key (References Parking Spots) |
| **entry_time** | TIMESTAMP | Default: Current Timestamp |
| **exit_time** | TIMESTAMP | Nullable |
| **total_fee** | NUMERIC | Calculated upon exit |
| **status** | VARCHAR | 'active', 'completed', 'pending' |

### 4. Users (`users`)
| Field | Data Type | Constraints / Notes |
| :--- | :--- | :--- |
| **id** | SERIAL | Primary Key |
| **first_name** | VARCHAR | User's First Name |
| **last_name** | VARCHAR | User's Last Name |
| **email** | VARCHAR | Unique Identification |
| **password** | VARCHAR | Hashed for security |
| **role** | VARCHAR | 'admin' or 'customer' |

### 5. Reservations (`reservations`)
| Field | Data Type | Constraints / Notes |
| :--- | :--- | :--- |
| **id** | SERIAL | Primary Key |
| **user_id** | INT | Foreign Key (References Users) |
| **spot_id** | INT | Foreign Key (References Parking Spots) |
| **start_time** | TIMESTAMP | Reservation start |
| **end_time** | TIMESTAMP | Reservation end |

---