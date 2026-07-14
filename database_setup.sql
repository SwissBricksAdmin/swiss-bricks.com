-- ════════════════════════════════════════════════════════
--  SwissBricks — Database Setup
-- ════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS swissbricks CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE swissbricks;

-- Users
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(80)  NOT NULL,
    last_name  VARCHAR(80)  NOT NULL,
    email      VARCHAR(150) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    phone      VARCHAR(20),
    address    TEXT,
    city       VARCHAR(100),
    avatar     VARCHAR(255) DEFAULT NULL,
    role       ENUM('member','admin','super_admin') DEFAULT 'member',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Categories
CREATE TABLE IF NOT EXISTS categories (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(10)  NOT NULL DEFAULT '🧱'
);

-- Products
CREATE TABLE IF NOT EXISTS products (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    category_id   INT            NOT NULL,
    name          VARCHAR(200)   NOT NULL,
    slug          VARCHAR(200)   NOT NULL UNIQUE,
    description   TEXT,
    price         DECIMAL(10,2)  NOT NULL,
    old_price     DECIMAL(10,2),
    image_url     VARCHAR(500)   NOT NULL DEFAULT '',
    badge         ENUM('NEW','HOT','SALE','') DEFAULT '',
    stock         INT            NOT NULL DEFAULT 100,
    featured      TINYINT(1)     DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- Orders
CREATE TABLE IF NOT EXISTS orders (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    user_id            INT,
    order_number       VARCHAR(60)   NOT NULL UNIQUE,
    total_amount       DECIMAL(10,2) NOT NULL,
    status             ENUM('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
    payment_method     VARCHAR(40)   NOT NULL,
    shipping_address   TEXT          NOT NULL,
    notes              TEXT,
    stripe_session_id  VARCHAR(200)  DEFAULT NULL,
    payment_status     ENUM('unpaid','paid','refunded') DEFAULT 'unpaid',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Order Items
CREATE TABLE IF NOT EXISTS order_items (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    order_id   INT            NOT NULL,
    product_id INT            NOT NULL,
    quantity   INT            NOT NULL,
    price      DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Settings (currency, Stripe keys, etc.)
CREATE TABLE IF NOT EXISTS settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT         NOT NULL,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Default settings
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('active_currency',        'CHF'),
    ('stripe_publishable_key', 'pk_test_REPLACE_WITH_YOUR_KEY'),
    ('stripe_secret_key',      'sk_test_REPLACE_WITH_YOUR_KEY'),
    ('stripe_webhook_secret',  '');

-- ── Seed data ──────────────────────────────────────────────

-- Default super admin (password: Admin@1234)
INSERT IGNORE INTO users (first_name, last_name, email, password, role) VALUES
('Super', 'Admin', 'admin@swissbricks.ch',
 '$2y$10$IHDQwagf1JxAncjpyZj5oefo9utzJ7AQG0bez9kMXj2aEexbnIFBa', 'super_admin');

-- LEGO Categories
INSERT IGNORE INTO categories (name, slug, icon) VALUES
('Star Wars',     'star-wars',    '🚀'),
('Technic',       'technic',      '⚙️'),
('Creator',       'creator',      '🏛️'),
('City',          'city',         '🏙️'),
('Harry Potter',  'harry-potter', '⚡'),
('Icons & Ideas', 'icons',        '🏆');

-- LEGO Products
INSERT INTO products (category_id, name, slug, description, price, old_price, image_url, badge, stock, featured) VALUES
(1, 'Millennium Falcon 75192',       'millennium-falcon-75192',       'The iconic Corellian freighter in stunning detail — 7,541 pieces, Han Solo\'s cockpit, full interior, and detailed exterior panelling. The ultimate Star Wars collector\'s piece.',        949.99,  1099.99, 'https://images.unsplash.com/photo-1555680202-c86f0e12f086?w=600&q=80', 'HOT',  8,  1),
(1, 'AT-AT Walker 75313',            'at-at-walker-75313',            'Towering Imperial All Terrain Armored Transport with 6,785 pieces. Features detailed interior, crew figures, and the iconic side profile from The Empire Strikes Back.',                    849.99,  NULL,    'https://images.unsplash.com/photo-1608734265656-f035d3e7bcbf?w=600&q=80', 'NEW',  5,  1),
(1, 'Imperial Star Destroyer 75252', 'imperial-star-destroyer-75252', 'Dominate your display shelf with this 4,784-piece Imperial Star Destroyer. Complete with two Minifigures, a scaled Tantive IV, and breathtaking detail on every surface.',              699.99,  749.99,  'https://images.unsplash.com/photo-1601814933824-fd0b574dd592?w=600&q=80', 'HOT',  12, 1),
(1, 'The Razor Crest 75292',         'razor-crest-75292',             '1,023-piece replica of the Mandalorian\'s iconic gunship. Cockpit opens, ramp lowers, and includes Mando, Grogu (Baby Yoda), and other characters.',                                            239.99,  279.99,  'https://images.unsplash.com/photo-1566577134770-3d85bb3a9cc4?w=600&q=80', 'SALE', 20, 0),
(1, 'Darth Vader\'s Castle 75251',   'darth-vaders-castle-75251',     'Reconstruct the menacing dark fortress from Rogue One with this 1,060-piece set. Includes iconic characters and a volcano setting.',                                                          449.99,  NULL,    'https://images.unsplash.com/photo-1518770660439-4636190af475?w=600&q=80', '',     15, 0),
(1, 'X-Wing Starfighter 75218',      'x-wing-starfighter-75218',      'Build the legendary Rebel Alliance X-wing fighter. S-foils open and close, cockpit opens for Luke Skywalker minifigure, and R2-D2 fits in the back.',                                         79.99,   NULL,    'https://images.unsplash.com/photo-1526374965328-7f61d4dc18c5?w=600&q=80', '',     40, 0),
(2, 'Bugatti Chiron 42083',          'bugatti-chiron-42083',          'Award-winning 3,599-piece Technic replica of the Bugatti Chiron supercar. Features the iconic W16 engine with moving pistons, rear spoiler, and gear-shift paddles.',                           389.99,  449.99,  'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=600&q=80', 'SALE', 10, 1),
(2, 'Porsche 911 RSR 42096',         'porsche-911-rsr-42096',         'Faithful 1,580-piece recreation of the iconic Porsche 911 RSR race car. Features functional flat-6 engine, aerodynamic wing, and detailed cockpit with racing driver.',                        189.99,  NULL,    'https://images.unsplash.com/photo-1563207153-f403bf289163?w=600&q=80', '',     18, 0),
(3, 'Eiffel Tower 10307',            'eiffel-tower-10307',            'The tallest LEGO set ever at over 1.5 metres — 10,001 pieces recreating Gustave Eiffel\'s masterpiece. Features authentic wrought-iron aesthetic and lifts on the first and second floors.',      269.99,  319.99,  'https://images.unsplash.com/photo-1549144511-f099e773c147?w=600&q=80', 'NEW',  14, 1),
(3, 'Botanical Collection: Orchid',  'orchid-10311',                  'Gorgeous 608-piece orchid arrangement with two stems, multiple blooms, and a decorative pot — perfect for any room. Colours never fade, and no watering needed.',                                59.99,   NULL,    'https://images.unsplash.com/photo-1516912481808-3406841bd33c?w=600&q=80', '',     60, 0),
(4, 'Police Station 60316',          'police-station-60316',          '668-piece LEGO City Police Station with lock-up, evidence room, office, garage, and police helicopter. Comes with 5 minifigures including officers and crooks.',                                   199.99,  229.99,  'https://images.unsplash.com/photo-1587556869148-0d2c0b21d7e1?w=600&q=80', 'SALE', 35, 0),
(5, 'Hogwarts Castle 71043',         'hogwarts-castle-71043',         'The ultimate Harry Potter set with 6,020 pieces. Recreates iconic rooms — the Great Hall, Dumbledore\'s office, the Chamber of Secrets — with 27 minifigures and 4 micro-figures.',               469.99,  NULL,    'https://images.unsplash.com/photo-1441986300917-64674bd600d8?w=600&q=80', 'HOT',  7,  1),
(5, 'Diagon Alley 75978',            'diagon-alley-75978',            'Immersive 5,544-piece recreation of the famous wizarding shopping street, featuring Ollivanders, Weasleys\' Wizard Wheezes, Gringotts, and more with 14 minifigures.',                            399.99,  449.99,  'https://images.unsplash.com/photo-1472396961693-142e6e269027?w=600&q=80', '',     9,  0),
(6, 'Colosseum 10276',               'colosseum-10276',               'The world\'s largest LEGO set at the time of release — 9,036 pieces recreating Rome\'s iconic amphitheatre across three storeys with Doric, Ionic, and Corinthian columns.',                       629.99,  NULL,    'https://images.unsplash.com/photo-1552832230-c0197dd311b5?w=600&q=80', 'NEW',  6,  0),
(6, 'Taj Mahal 10256',               'taj-mahal-10256',               'Stunning 5,923-piece recreation of the Taj Mahal, featuring the iconic white marble facade, ornate details, four minarets, and lush garden approach.',                                            369.99,  419.99,  'https://images.unsplash.com/photo-1564507592333-c60657eea523?w=600&q=80', '',     11, 0);
