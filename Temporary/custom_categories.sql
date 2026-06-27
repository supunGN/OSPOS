CREATE TABLE IF NOT EXISTS ospos_categories (
    id INT(11) NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ospos_subcategories (
    id INT(11) NOT NULL AUTO_INCREMENT,
    category_id INT(11) NOT NULL,
    name VARCHAR(255) NOT NULL,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    CONSTRAINT fk_category FOREIGN KEY (category_id) REFERENCES ospos_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert categories
INSERT INTO ospos_categories (id, name) VALUES (1, 'Tiles');
INSERT INTO ospos_categories (id, name) VALUES (2, 'Wash Basins');
INSERT INTO ospos_categories (id, name) VALUES (3, 'Water Closets');
INSERT INTO ospos_categories (id, name) VALUES (4, 'Accessories');

-- Insert subcategories for Tiles
INSERT INTO ospos_subcategories (category_id, name) VALUES 
(1, 'Floor Tiles'), 
(1, 'Wall Tiles'), 
(1, 'Outdoor Tiles'), 
(1, 'Mosaics');

-- Insert subcategories for Wash Basins
INSERT INTO ospos_subcategories (category_id, name) VALUES 
(2, 'Counter Top'), 
(2, 'Wall Hung Basin'), 
(2, 'Floor Standing Basin'), 
(2, 'Free Standing Basin'), 
(2, 'Under Counter Basin'), 
(2, 'Semi Recessed Basin'), 
(2, 'Above Counter Basin'), 
(2, 'Small Basin');

-- Insert subcategories for Water Closets
INSERT INTO ospos_subcategories (category_id, name) VALUES 
(3, 'Floor Mounted Water Closet'), 
(3, 'Wall Hung Water Closet'), 
(3, 'Smart Water Closet');

-- Insert subcategories for Accessories
INSERT INTO ospos_subcategories (category_id, name) VALUES 
(4, 'Faucets'), 
(4, 'Bath & Shower'), 
(4, 'Bathroom Accessories'), 
(4, 'Kitchen Sinks'), 
(4, 'Other Accessories');
