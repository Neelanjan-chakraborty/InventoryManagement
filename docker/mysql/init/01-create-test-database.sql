-- Runs once, on first initialisation of the MySQL data volume.
-- The application database itself is created by MYSQL_DATABASE; this adds the
-- separate database the test suite migrates into, owned by the same app user.
CREATE DATABASE IF NOT EXISTS `inventory_test`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `inventory_test`.* TO 'inventory'@'%';
FLUSH PRIVILEGES;
