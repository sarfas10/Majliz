<?php
require_once __DIR__ . '/db_connection.php';
 = get_db_connection();
if (isset(['error'])) die(['error']);
 = \['conn'];

// Add custom_due to members and sahakari_members
->query(\"ALTER TABLE members ADD COLUMN IF NOT EXISTS custom_due DECIMAL(12,2) NOT NULL DEFAULT 0.00\");
->query(\"ALTER TABLE sahakari_members ADD COLUMN IF NOT EXISTS custom_due DECIMAL(12,2) NOT NULL DEFAULT 0.00\");

// Add category_name to mahal_additional_dues
->query(\"ALTER TABLE mahal_additional_dues ADD COLUMN IF NOT EXISTS category_name VARCHAR(150) NULL\");

echo 'Schema updated temporarily.';
?>
