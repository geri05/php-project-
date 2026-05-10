-- Table to store user data (can be clients or administrators)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY, -- Unique auto-incrementing ID for the user
    first_name VARCHAR(50), -- User's first name
    last_name VARCHAR(50), -- User's last name
    email VARCHAR(100) UNIQUE NOT NULL, -- Unique email address used for login
    password VARCHAR(255) NOT NULL, -- Hashed password for security
    role VARCHAR(20) DEFAULT 'customer', -- User role in the system ('customer' or 'admin')
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP -- Timestamp of when the account was created
);

-- Table holding all physical parking spots and their current status
CREATE TABLE IF NOT EXISTS parking_spots (
    id SERIAL PRIMARY KEY, -- Unique ID for the parking spot
    spot_number VARCHAR(10) UNIQUE NOT NULL, -- Identifier for the spot (e.g., A1, B5)
    status VARCHAR(20) DEFAULT 'available' -- Current state: 'available', 'occupied', or 'reserved'
);

-- Table for registered vehicles linked to customers
CREATE TABLE IF NOT EXISTS vehicles (
    id SERIAL PRIMARY KEY, -- Unique ID for the vehicle
    license_plate VARCHAR(20) UNIQUE NOT NULL, -- License plate number of the vehicle (translated from 'targa')
    user_id INT REFERENCES users(id) ON DELETE CASCADE -- Link to the owner (if user is deleted, their vehicles are deleted)
);

-- Main table for business logic. Tracks every entry/exit and the final fee.
CREATE TABLE IF NOT EXISTS parking_sessions (
    id SERIAL PRIMARY KEY, -- Unique ID for the parking session
    vehicle_id INT REFERENCES vehicles(id) ON DELETE SET NULL, -- The specific vehicle that entered the parking
    spot_id INT REFERENCES parking_spots(id) ON DELETE SET NULL, -- The specific spot where the vehicle is parked
    entry_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Exact time the vehicle entered the parking lot
    exit_time TIMESTAMP, -- Exact time the vehicle left the parking lot
    total_fee NUMERIC(10, 2) DEFAULT 0.00, -- The total amount of money to be paid for the duration of the stay
    status VARCHAR(20) DEFAULT 'active' -- Status of the session ('active' while parked, 'completed' when left)
);

-- Table for reservations, when someone books a spot before arriving
CREATE TABLE IF NOT EXISTS reservations (
    id SERIAL PRIMARY KEY, -- Unique ID for the reservation
    user_id INT REFERENCES users(id) ON DELETE CASCADE, -- The user who made the reservation
    spot_id INT REFERENCES parking_spots(id) ON DELETE CASCADE, -- The specific spot that was reserved
    vehicle_id INT REFERENCES vehicles(id) ON DELETE CASCADE, -- The vehicle expected to arrive
    reservation_start TIMESTAMP, -- The time the reservation begins
    reservation_end TIMESTAMP, -- The time the reservation expires or ends
    status VARCHAR(20) DEFAULT 'active' -- Tracks the state: 'active', 'completed', 'no-show', or 'cancelled'
);

-- Table for tracking all payments (The central financial ledger)
CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY, -- Unique ID for the financial transaction
    session_id INT REFERENCES parking_sessions(id) ON DELETE CASCADE, -- Link to the parking session being paid for
    reservation_id INT REFERENCES reservations(id) ON DELETE CASCADE, -- Link to a specific booking (if paying a reservation fee)
    amount NUMERIC(10, 2) NOT NULL, -- The actual amount of money paid
    payment_method VARCHAR(20) NOT NULL, -- Method of payment used ('cash' or 'card')
    payment_type VARCHAR(20) NOT NULL, -- Purpose of payment ('reservation_fee' or 'stay_fee')
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Exact time the transaction was processed
    
    -- Constraint to ensure a payment belongs to EITHER a session OR a reservation, but not both/neither
    CONSTRAINT chk_payment_link CHECK (
        (session_id IS NOT NULL AND reservation_id IS NULL) OR
        (session_id IS NULL AND reservation_id IS NOT NULL)
    )
);


-- Generate 18 parking spots for Sector A (A1, A2... A18)
INSERT INTO parking_spots (spot_number)
SELECT 'A' || i FROM generate_series(1, 18) AS i;

-- Generate 18 parking spots for Sector B (B1, B2... B18)
INSERT INTO parking_spots (spot_number)
SELECT 'B' || i FROM generate_series(1, 18) AS i;

-- Generate 18 parking spots for Sector C (C1, C2... C18)
INSERT INTO parking_spots (spot_number)
SELECT 'C' || i FROM generate_series(1, 18) AS i;


-- Remove the requirement for a password (Google users do not have a local password)
ALTER TABLE users ALTER COLUMN password DROP NOT NULL;

-- Add a column to track the registration method ('local' for email/password, 'google' for OAuth)
ALTER TABLE users ADD COLUMN IF NOT EXISTS auth_provider VARCHAR(20) DEFAULT 'local';

-- Store the unique Google account ID to prevent fake profiles and link accounts correctly
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) UNIQUE;

-- Add phone number column
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_number VARCHAR(20);

-- Add profile image URL column
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_image_url VARCHAR(255);