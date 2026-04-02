-- ============================================================
-- HabitatIQ — Complete MySQL Database Schema
-- Version: 2.0 | Full Stack Implementation
-- Charset: utf8mb4 (supports Swahili, emoji, full unicode)
-- ============================================================

CREATE DATABASE IF NOT EXISTS habitatiq
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE habitatiq;

-- ============================================================
-- USERS — Central auth table for all roles
-- ============================================================
CREATE TABLE users (
  id           VARCHAR(30)  NOT NULL PRIMARY KEY,
  name         VARCHAR(100) NOT NULL,
  email        VARCHAR(100) NOT NULL UNIQUE,
  password     VARCHAR(255) NOT NULL,            -- plain text for testing, hash in prod
  role         ENUM('admin','landlord','tenant') NOT NULL,
  status       ENUM('active','pending_approval','suspended','rejected') DEFAULT 'active',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email_role (email, role),
  INDEX idx_role (role),
  INDEX idx_status (status)
) ENGINE=InnoDB;

-- ============================================================
-- LANDLORDS — Landlord profile & business details
-- ============================================================
CREATE TABLE landlords (
  id           VARCHAR(30)  NOT NULL PRIMARY KEY,
  user_id      VARCHAR(30)  NOT NULL UNIQUE,
  company_name VARCHAR(100) NOT NULL,
  phone        VARCHAR(20),
  kra_pin      VARCHAR(20),
  business_reg VARCHAR(50),
  address      TEXT,
  joined_at    DATE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- ============================================================
-- LANDLORD APPLICATIONS — Admin approval queue
-- ============================================================
CREATE TABLE landlord_applications (
  id           VARCHAR(30)  NOT NULL PRIMARY KEY,
  landlord_id  VARCHAR(30)  NOT NULL,
  status       ENUM('pending','approved','rejected','suspended') DEFAULT 'pending',
  reject_reason TEXT,
  reviewed_by  VARCHAR(30),
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_at   TIMESTAMP NULL,
  FOREIGN KEY (landlord_id) REFERENCES landlords(id),
  INDEX idx_status (status),
  INDEX idx_landlord (landlord_id)
) ENGINE=InnoDB;

-- ============================================================
-- PROPERTIES — Rental properties
-- ============================================================
CREATE TABLE properties (
  id             VARCHAR(30)  NOT NULL PRIMARY KEY,
  landlord_id    VARCHAR(30)  NOT NULL,
  name           VARCHAR(100) NOT NULL,
  location       VARCHAR(200) NOT NULL,
  property_type  ENUM('Apartment','Bedsitter','Studio','Townhouse','Commercial','Mixed Use') DEFAULT 'Apartment',
  total_units    INT UNSIGNED NOT NULL DEFAULT 0,
  occupied_units INT UNSIGNED NOT NULL DEFAULT 0,
  rent_per_unit  DECIMAL(12,2) NOT NULL DEFAULT 0,
  monthly_income DECIMAL(12,2) GENERATED ALWAYS AS (total_units * rent_per_unit) STORED,
  caretaker      VARCHAR(100),
  unit_naming    ENUM('alpha','numeric','floor') DEFAULT 'alpha',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (landlord_id) REFERENCES landlords(id),
  INDEX idx_landlord (landlord_id)
) ENGINE=InnoDB;

-- ============================================================
-- UNITS — Individual units within properties
-- ============================================================
CREATE TABLE units (
  id          VARCHAR(50)  NOT NULL PRIMARY KEY,
  property_id VARCHAR(30)  NOT NULL,
  label       VARCHAR(20)  NOT NULL,    -- e.g. "Unit A", "1A", "Unit 1"
  floor_no    INT UNSIGNED DEFAULT 0,
  status      ENUM('vacant','occupied','pending','maintenance') DEFAULT 'vacant',
  rent        DECIMAL(12,2) NOT NULL DEFAULT 0,
  notes       TEXT,
  FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
  INDEX idx_property_status (property_id, status),
  UNIQUE KEY unique_unit (property_id, label)
) ENGINE=InnoDB;

-- ============================================================
-- TENANTS — Tenant profiles
-- ============================================================
CREATE TABLE tenants (
  id               VARCHAR(30)  NOT NULL PRIMARY KEY,
  user_id          VARCHAR(30)  NOT NULL UNIQUE,
  name             VARCHAR(100) NOT NULL,
  national_id      VARCHAR(8)   NOT NULL,
  phone            VARCHAR(20),
  email            VARCHAR(100),
  gender           ENUM('Male','Female','Prefer not to say'),
  dob              DATE,
  property_id      VARCHAR(30),
  unit_id          VARCHAR(50),
  status           ENUM('active','cleared','flagged','pending_approval') DEFAULT 'pending_approval',
  kyc_status       ENUM('pending','submitted','verified','rejected') DEFAULT 'pending',
  kyc_submitted_at DATE,
  kyc_reviewed_at  DATE,
  kyc_reviewed_by  VARCHAR(30),
  kyc_reject_reason TEXT,
  arrears          DECIMAL(12,2) DEFAULT 0,
  balance          DECIMAL(12,2) DEFAULT 0,
  clearance_cert   VARCHAR(30),
  lease_end        DATE,
  lease_months     INT UNSIGNED DEFAULT 12,
  movein_date      DATE,
  emergency_contact VARCHAR(200),
  caretaker        VARCHAR(100),
  region           VARCHAR(50),
  monthly_rent     DECIMAL(12,2) DEFAULT 0,
  message          TEXT,             -- message to landlord during application
  prev_tenant_id   VARCHAR(30),      -- for returning tenants
  registered_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (property_id)  REFERENCES properties(id) ON DELETE SET NULL,
  FOREIGN KEY (unit_id)      REFERENCES units(id)      ON DELETE SET NULL,
  UNIQUE KEY unique_national_id (national_id),
  INDEX idx_status (status),
  INDEX idx_kyc (kyc_status),
  INDEX idx_property (property_id),
  INDEX idx_user (user_id)
) ENGINE=InnoDB;

-- ============================================================
-- TENANT APPLICATIONS — Landlord approval queue
-- ============================================================
CREATE TABLE tenant_applications (
  id           VARCHAR(30)  NOT NULL PRIMARY KEY,
  tenant_id    VARCHAR(30)  NOT NULL,
  property_id  VARCHAR(30)  NOT NULL,
  unit_id      VARCHAR(50)  NOT NULL,
  landlord_id  VARCHAR(30),
  kyc_path     ENUM('new_full_kyc','returning_verified') DEFAULT 'new_full_kyc',
  message      TEXT,
  status       ENUM('pending','approved','rejected') DEFAULT 'pending',
  reject_reason TEXT,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  decided_at   TIMESTAMP NULL,
  FOREIGN KEY (tenant_id)   REFERENCES tenants(id),
  FOREIGN KEY (property_id) REFERENCES properties(id),
  FOREIGN KEY (unit_id)     REFERENCES units(id),
  INDEX idx_landlord_status (landlord_id, status),
  INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB;

-- ============================================================
-- KYC DOCUMENTS — Uploaded verification files
-- ============================================================
CREATE TABLE kyc_documents (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id    VARCHAR(30)  NOT NULL,
  doc_type     ENUM('id_front','id_back','selfie') NOT NULL,
  file_path    VARCHAR(500) NOT NULL,    -- path relative to uploads/kyc/
  file_name    VARCHAR(200),
  file_size    INT UNSIGNED,
  mime_type    VARCHAR(50),
  uploaded_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  UNIQUE KEY unique_doc (tenant_id, doc_type),
  INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB;

-- ============================================================
-- KYC VERIFICATION RESULTS — Face match & data match scores
-- ============================================================
CREATE TABLE kyc_verifications (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id         VARCHAR(30)  NOT NULL,
  face_match_score  DECIMAL(5,2) DEFAULT 0,    -- 0.00 to 100.00
  name_match_score  DECIMAL(5,2) DEFAULT 0,
  id_match_score    DECIMAL(5,2) DEFAULT 0,
  overall_score     DECIMAL(5,2) DEFAULT 0,
  face_match_result ENUM('match','no_match','uncertain') DEFAULT 'uncertain',
  name_match_result ENUM('match','partial','no_match','uncertain') DEFAULT 'uncertain',
  id_match_result   ENUM('match','no_match','uncertain') DEFAULT 'uncertain',
  verified_by       VARCHAR(30),     -- landlord or admin user_id
  verified_at       TIMESTAMP NULL,
  notes             TEXT,
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
  INDEX idx_tenant (tenant_id)
) ENGINE=InnoDB;

-- ============================================================
-- PAYMENTS — Rent and other payments
-- ============================================================
CREATE TABLE payments (
  id          VARCHAR(30)  NOT NULL PRIMARY KEY,
  tenant_id   VARCHAR(30)  NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  method      ENUM('mpesa','bank','cash') NOT NULL,
  reference   VARCHAR(100),
  type        ENUM('rent','arrears','deposit','utility','penalty') DEFAULT 'rent',
  status      ENUM('pending','confirmed','failed','reversed') DEFAULT 'pending',
  description VARCHAR(200),
  paid_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  INDEX idx_tenant_status (tenant_id, status),
  INDEX idx_paid_at (paid_at)
) ENGINE=InnoDB;

-- ============================================================
-- NOTICES — Communications from landlord/admin to tenants
-- ============================================================
CREATE TABLE notices (
  id          VARCHAR(30)  NOT NULL PRIMARY KEY,
  from_user   VARCHAR(30)  NOT NULL,
  to_tenant   VARCHAR(30)  NOT NULL,
  type        ENUM('reminder','arrears','eviction','lease_renewal','general','kyc') NOT NULL,
  message     TEXT NOT NULL,
  channel     ENUM('system','sms','whatsapp','both') DEFAULT 'system',
  status      ENUM('pending','sent','failed') DEFAULT 'pending',
  amount      DECIMAL(12,2) DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sent_at     TIMESTAMP NULL,
  FOREIGN KEY (from_user)  REFERENCES users(id),
  FOREIGN KEY (to_tenant)  REFERENCES tenants(id),
  INDEX idx_tenant (to_tenant),
  INDEX idx_from (from_user)
) ENGINE=InnoDB;

-- ============================================================
-- MAINTENANCE — Property repair requests
-- ============================================================
CREATE TABLE maintenance_requests (
  id          VARCHAR(30)  NOT NULL PRIMARY KEY,
  property_id VARCHAR(30)  NOT NULL,
  unit_id     VARCHAR(50),
  tenant_id   VARCHAR(30),
  issue       TEXT NOT NULL,
  priority    ENUM('low','medium','high','emergency') DEFAULT 'medium',
  status      ENUM('pending','in_progress','resolved','cancelled') DEFAULT 'pending',
  notes       TEXT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL,
  FOREIGN KEY (property_id) REFERENCES properties(id),
  FOREIGN KEY (unit_id)     REFERENCES units(id) ON DELETE SET NULL,
  FOREIGN KEY (tenant_id)   REFERENCES tenants(id) ON DELETE SET NULL,
  INDEX idx_property_status (property_id, status),
  INDEX idx_priority (priority, status)
) ENGINE=InnoDB;

-- ============================================================
-- AUDIT LOGS — System-wide activity trail
-- ============================================================
CREATE TABLE audit_logs (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    VARCHAR(30),
  user_name  VARCHAR(100),
  role       ENUM('admin','landlord','tenant','system'),
  action     VARCHAR(100) NOT NULL,
  entity     VARCHAR(50),
  detail     TEXT,
  ip_address VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id),
  INDEX idx_action (action),
  INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- CLEARANCE REGISTRY — National clearance ledger
-- ============================================================
CREATE TABLE clearance_registry (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id      VARCHAR(30) NOT NULL,
  national_id    VARCHAR(8)  NOT NULL,
  property_id    VARCHAR(30),
  cert_number    VARCHAR(30) UNIQUE,
  status         ENUM('cleared','pending','flagged') DEFAULT 'pending',
  issued_by      VARCHAR(30),
  issued_at      TIMESTAMP NULL,
  notes          TEXT,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  INDEX idx_national_id (national_id),
  INDEX idx_status (status)
) ENGINE=InnoDB;

-- ============================================================
-- SEED DATA — Demo users and sample records
-- ============================================================

-- Admin user (password: admin123)
INSERT INTO users (id, name, email, password, role, status) VALUES
('U001', 'John Mwangi', 'admin@habitatiq.co.ke', 'admin123', 'admin', 'active');

-- Landlord users
INSERT INTO users (id, name, email, password, role, status) VALUES
('LL001', 'Wanjiku Holdings Ltd', 'wanjiku@holdings.co.ke', 'demo123', 'landlord', 'active'),
('LL002', 'Coast Investments', 'coast@investments.co.ke', 'demo123', 'landlord', 'active'),
('LL003', 'Lakeshore Properties', 'lakeshore@properties.co.ke', 'demo123', 'landlord', 'active');

-- Landlord profiles
INSERT INTO landlords (id, user_id, company_name, phone, kra_pin, joined_at) VALUES
('LL001', 'LL001', 'Wanjiku Holdings Ltd', '0701234567', 'A123456789B', '2023-06-15'),
('LL002', 'LL002', 'Coast Investments', '0702345678', 'A234567890C', '2023-08-22'),
('LL003', 'LL003', 'Lakeshore Properties', '0703456789', 'A345678901D', '2023-10-10');

-- Landlord approvals
INSERT INTO landlord_applications (id, landlord_id, status, reviewed_by, decided_at) VALUES
('LA001', 'LL001', 'approved', 'U001', '2023-06-16 10:00:00'),
('LA002', 'LL002', 'approved', 'U001', '2023-08-23 10:00:00'),
('LA003', 'LL003', 'approved', 'U001', '2023-10-11 10:00:00');

-- Properties
INSERT INTO properties (id, landlord_id, name, location, property_type, total_units, occupied_units, rent_per_unit, caretaker, unit_naming) VALUES
('P001', 'LL001', 'Greenpark Apartments', 'Westlands, Nairobi', 'Apartment', 24, 20, 25000, 'James Mwita', 'alpha'),
('P002', 'LL002', 'Sunrise Estate', 'Nyali, Mombasa', 'Apartment', 16, 14, 20000, 'Jane Njeri', 'alpha'),
('P003', 'LL003', 'Valley View Residences', 'Milimani, Kisumu', 'Apartment', 12, 9, 15000, 'Peter Omondi', 'alpha'),
('P004', 'LL001', 'Hilltop Towers', 'Kileleshwa, Nairobi', 'Apartment', 32, 29, 30000, 'James Mwita', 'numeric');

-- Units for Greenpark (P001) — 24 units, A-X, 20 occupied 4 vacant
INSERT INTO units (id, property_id, label, status, rent) VALUES
('P001-A','P001','Unit A','occupied',25000), ('P001-B','P001','Unit B','occupied',25000),
('P001-C','P001','Unit C','occupied',25000), ('P001-D','P001','Unit D','occupied',25000),
('P001-E','P001','Unit E','occupied',25000), ('P001-F','P001','Unit F','occupied',25000),
('P001-G','P001','Unit G','occupied',25000), ('P001-H','P001','Unit H','occupied',25000),
('P001-I','P001','Unit I','occupied',25000), ('P001-J','P001','Unit J','occupied',25000),
('P001-K','P001','Unit K','occupied',25000), ('P001-L','P001','Unit L','occupied',25000),
('P001-M','P001','Unit M','occupied',25000), ('P001-N','P001','Unit N','occupied',25000),
('P001-O','P001','Unit O','occupied',25000), ('P001-P','P001','Unit P','occupied',25000),
('P001-Q','P001','Unit Q','occupied',25000), ('P001-R','P001','Unit R','occupied',25000),
('P001-S','P001','Unit S','occupied',25000), ('P001-T','P001','Unit T','occupied',25000),
('P001-U','P001','Unit U','vacant',25000),   ('P001-V','P001','Unit V','vacant',25000),
('P001-W','P001','Unit W','vacant',25000),   ('P001-X','P001','Unit X','vacant',25000);

-- Units for Sunrise (P002) — 16 units
INSERT INTO units (id, property_id, label, status, rent) VALUES
('P002-A','P002','Unit A','occupied',20000), ('P002-B','P002','Unit B','occupied',20000),
('P002-C','P002','Unit C','occupied',20000), ('P002-D','P002','Unit D','occupied',20000),
('P002-E','P002','Unit E','occupied',20000), ('P002-F','P002','Unit F','occupied',20000),
('P002-G','P002','Unit G','occupied',20000), ('P002-H','P002','Unit H','occupied',20000),
('P002-I','P002','Unit I','occupied',20000), ('P002-J','P002','Unit J','occupied',20000),
('P002-K','P002','Unit K','occupied',20000), ('P002-L','P002','Unit L','occupied',20000),
('P002-M','P002','Unit M','occupied',20000), ('P002-N','P002','Unit N','occupied',20000),
('P002-O','P002','Unit O','vacant',20000),   ('P002-P','P002','Unit P','vacant',20000);

-- Units for Valley View (P003) — 12 units
INSERT INTO units (id, property_id, label, status, rent) VALUES
('P003-A','P003','Unit A','occupied',15000), ('P003-B','P003','Unit B','occupied',15000),
('P003-C','P003','Unit C','occupied',15000), ('P003-D','P003','Unit D','occupied',15000),
('P003-E','P003','Unit E','occupied',15000), ('P003-F','P003','Unit F','occupied',15000),
('P003-G','P003','Unit G','occupied',15000), ('P003-H','P003','Unit H','occupied',15000),
('P003-I','P003','Unit I','occupied',15000), ('P003-J','P003','Unit J','vacant',15000),
('P003-K','P003','Unit K','vacant',15000),   ('P003-L','P003','Unit L','vacant',15000);

-- Units for Hilltop (P004) — 32 units numeric
INSERT INTO units (id, property_id, label, status, rent) VALUES
('P004-1','P004','Unit 1','occupied',30000), ('P004-2','P004','Unit 2','occupied',30000),
('P004-3','P004','Unit 3','occupied',30000), ('P004-4','P004','Unit 4','occupied',30000),
('P004-5','P004','Unit 5','occupied',30000), ('P004-6','P004','Unit 6','occupied',30000),
('P004-7','P004','Unit 7','occupied',30000), ('P004-8','P004','Unit 8','occupied',30000),
('P004-9','P004','Unit 9','occupied',30000), ('P004-10','P004','Unit 10','occupied',30000),
('P004-11','P004','Unit 11','occupied',30000),('P004-12','P004','Unit 12','occupied',30000),
('P004-13','P004','Unit 13','occupied',30000),('P004-14','P004','Unit 14','occupied',30000),
('P004-15','P004','Unit 15','occupied',30000),('P004-16','P004','Unit 16','occupied',30000),
('P004-17','P004','Unit 17','occupied',30000),('P004-18','P004','Unit 18','occupied',30000),
('P004-19','P004','Unit 19','occupied',30000),('P004-20','P004','Unit 20','occupied',30000),
('P004-21','P004','Unit 21','occupied',30000),('P004-22','P004','Unit 22','occupied',30000),
('P004-23','P004','Unit 23','occupied',30000),('P004-24','P004','Unit 24','occupied',30000),
('P004-25','P004','Unit 25','occupied',30000),('P004-26','P004','Unit 26','occupied',30000),
('P004-27','P004','Unit 27','occupied',30000),('P004-28','P004','Unit 28','occupied',30000),
('P004-29','P004','Unit 29','occupied',30000),('P004-30','P004','Unit 30','vacant',30000),
('P004-31','P004','Unit 31','vacant',30000), ('P004-32','P004','Unit 32','vacant',30000);

-- Tenant users (password: tenant123)
INSERT INTO users (id, name, email, password, role, status) VALUES
('T001', 'Alice Wanjiru',   'alice@gmail.com',  'tenant123', 'tenant', 'active'),
('T002', 'Brian Otieno',    'brian@gmail.com',  'tenant123', 'tenant', 'active'),
('T003', 'Catherine Kamau', 'cathy@gmail.com',  'tenant123', 'tenant', 'active'),
('T004', 'David Njoroge',   'david@gmail.com',  'tenant123', 'tenant', 'active'),
('T005', 'Esther Mutua',    'esther@gmail.com', 'tenant123', 'tenant', 'active'),
('T006', 'Felix Kimani',    'felix@gmail.com',  'tenant123', 'tenant', 'active');

-- Tenant profiles
INSERT INTO tenants (id, user_id, name, national_id, phone, email, property_id, unit_id, status, kyc_status, arrears, clearance_cert, lease_end, caretaker, region, monthly_rent, kyc_reviewed_at, kyc_reviewed_by, registered_at) VALUES
('T001','T001','Alice Wanjiru','25431289','0712345678','alice@gmail.com','P001','P001-A','cleared','verified',0,'CL-2024-001','2025-06-30','James Mwita','Nairobi',25000,'2024-01-12','U001','2024-01-15 00:00:00'),
('T002','T002','Brian Otieno','31876543','0723456789','brian@gmail.com','P002','P002-B','flagged','verified',45000,NULL,'2024-12-31','Jane Njeri','Mombasa',20000,'2024-03-21','U001','2024-03-22 00:00:00'),
('T003','T003','Catherine Kamau','18924567','0734567890','cathy@gmail.com','P003','P003-C','active','submitted',12000,NULL,'2025-03-31','Peter Omondi','Kisumu',15000,NULL,NULL,'2024-05-10 00:00:00'),
('T004','T004','David Njoroge','42156789','0745678901','david@gmail.com','P004','P004-5','cleared','verified',0,'CL-2023-118','2025-09-30','James Mwita','Nairobi',30000,'2023-11-07','U001','2023-11-08 00:00:00'),
('T005','T005','Esther Mutua','29834512','0756789012','esther@gmail.com','P001','P001-E','active','pending',0,NULL,'2025-12-31','Mary Atieno','Nakuru',25000,NULL,NULL,'2024-07-01 00:00:00'),
('T006','T006','Felix Kimani','35612478','0767890123','felix@gmail.com','P002','P002-C','flagged','rejected',78000,NULL,'2024-09-30','Peter Omondi','Eldoret',20000,'2024-02-16','U001','2024-02-18 00:00:00');

-- Update units to match tenant assignments
UPDATE units SET status='occupied' WHERE id IN ('P001-A','P001-E');
UPDATE units SET status='occupied' WHERE id IN ('P002-B','P002-C');
UPDATE units SET status='occupied' WHERE id IN ('P003-C');
UPDATE units SET status='occupied' WHERE id IN ('P004-5');

-- Sample payments
INSERT INTO payments (id, tenant_id, amount, method, reference, type, status, paid_at) VALUES
('PAY001','T001',25000,'mpesa','QA12345678','rent','confirmed','2025-03-01 10:12:00'),
('PAY002','T005',25000,'mpesa','QB98765432','rent','confirmed','2025-03-01 11:00:00'),
('PAY003','T004',30000,'bank','BTR-003','rent','confirmed','2025-03-02 09:00:00'),
('PAY004','T002',15000,'mpesa','QC11223344','rent','pending','2025-02-28 14:00:00'),
('PAY005','T003',12000,'mpesa','QD55667788','arrears','pending','2025-03-05 16:00:00');

-- Sample notices
INSERT INTO notices (id, from_user, to_tenant, type, message, channel, status, amount, created_at) VALUES
('N001','LL002','T002','arrears','Your rent arrears of KES 45,000 are overdue. Please settle immediately.','system','sent',45000,'2025-02-15 00:00:00'),
('N002','LL002','T006','eviction','Formal eviction notice issued for non-payment of KES 78,000.','system','sent',78000,'2025-01-20 00:00:00'),
('N003','LL003','T003','reminder','Your rent of KES 15,000 is due on the 1st. Please ensure timely payment.','system','pending',15000,'2025-03-10 00:00:00');

-- Sample maintenance
INSERT INTO maintenance_requests (id, property_id, unit_id, tenant_id, issue, priority, status, created_at) VALUES
('M001','P001','P001-A','T001','Leaking pipe in bathroom ceiling','high','in_progress','2025-03-08 00:00:00'),
('M002','P004','P004-5','T004','Broken window latch — security concern','medium','pending','2025-03-10 00:00:00'),
('M003','P002','P002-B','T002','Electrical fault in kitchen — sparking sockets','high','resolved','2025-03-05 00:00:00'),
('M004','P003','P003-C','T003','Door lock malfunction — cannot lock unit','low','pending','2025-03-12 00:00:00');

-- KYC documents placeholders for verified tenants
INSERT INTO kyc_documents (tenant_id, doc_type, file_path, file_name, uploaded_at) VALUES
('T001','id_front','kyc/T001/id_front.jpg','alice_id_front.jpg','2024-01-10 00:00:00'),
('T001','id_back','kyc/T001/id_back.jpg','alice_id_back.jpg','2024-01-10 00:00:00'),
('T001','selfie','kyc/T001/selfie.jpg','alice_selfie.jpg','2024-01-10 00:00:00'),
('T002','id_front','kyc/T002/id_front.jpg','brian_id_front.jpg','2024-03-20 00:00:00'),
('T002','id_back','kyc/T002/id_back.jpg','brian_id_back.jpg','2024-03-20 00:00:00'),
('T002','selfie','kyc/T002/selfie.jpg','brian_selfie.jpg','2024-03-20 00:00:00'),
('T003','id_front','kyc/T003/id_front.jpg','cathy_id_front.jpg','2024-05-12 00:00:00'),
('T003','id_back','kyc/T003/id_back.jpg','cathy_id_back.jpg','2024-05-12 00:00:00'),
('T004','id_front','kyc/T004/id_front.jpg','david_id_front.jpg','2023-11-05 00:00:00'),
('T004','id_back','kyc/T004/id_back.jpg','david_id_back.jpg','2023-11-05 00:00:00'),
('T004','selfie','kyc/T004/selfie.jpg','david_selfie.jpg','2023-11-05 00:00:00'),
('T006','id_front','kyc/T006/id_front.jpg','felix_id_front.jpg','2024-02-15 00:00:00'),
('T006','id_back','kyc/T006/id_back.jpg','felix_id_back.jpg','2024-02-15 00:00:00'),
('T006','selfie','kyc/T006/selfie.jpg','felix_selfie.jpg','2024-02-15 00:00:00');

-- KYC submission dates
UPDATE tenants SET kyc_submitted_at='2024-01-10' WHERE id IN ('T001','T002','T003','T004','T006');
UPDATE tenants SET kyc_reject_reason='National ID photo does not match selfie. Document appears tampered.' WHERE id='T006';

-- Clearance registry
INSERT INTO clearance_registry (tenant_id, national_id, property_id, cert_number, status, issued_by, issued_at) VALUES
('T001','25431289','P001','CL-2024-001','cleared','U001','2024-01-12 00:00:00'),
('T004','42156789','P004','CL-2023-118','cleared','U001','2023-11-07 00:00:00');

-- Seed audit log
INSERT INTO audit_logs (user_id, user_name, role, action, entity, detail) VALUES
('U001','John Mwangi','admin','login','auth','Admin logged in'),
('U001','John Mwangi','admin','approve_landlord','landlords','Approved Wanjiku Holdings Ltd'),
('T001','Alice Wanjiru','tenant','kyc_submitted','kyc','Alice submitted KYC documents'),
('U001','John Mwangi','admin','kyc_approved','kyc','KYC approved for Alice Wanjiru'),
('U001','John Mwangi','admin','issue_clearance','clearance','Issued CL-2024-001 for Alice Wanjiru');

-- ============================================================
-- VIEWS — Useful aggregated views
-- ============================================================

CREATE OR REPLACE VIEW v_tenant_summary AS
SELECT
  t.id, t.name, t.national_id, t.phone, t.email,
  t.status, t.kyc_status, t.arrears, t.clearance_cert,
  t.lease_end, t.monthly_rent, t.registered_at,
  p.name  AS property_name,
  p.id    AS property_id,
  u.label AS unit_label,
  u.id    AS unit_id,
  l.id    AS landlord_id,
  l.company_name AS landlord_name
FROM tenants t
LEFT JOIN properties p  ON t.property_id = p.id
LEFT JOIN units u       ON t.unit_id = u.id
LEFT JOIN landlords l   ON p.landlord_id = l.id;

CREATE OR REPLACE VIEW v_property_summary AS
SELECT
  p.*,
  l.company_name AS landlord_name,
  (SELECT COUNT(*) FROM units WHERE property_id=p.id AND status='vacant') AS vacant_units,
  (SELECT COUNT(*) FROM units WHERE property_id=p.id AND status='maintenance') AS maintenance_units
FROM properties p
LEFT JOIN landlords l ON p.landlord_id = l.id;
