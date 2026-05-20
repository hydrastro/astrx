CREATE USER 'user'@'%' IDENTIFIED BY 'password';
CREATE DATABASE content_manager;
GRANT ALL PRIVILEGES ON content_manager.* TO 'user'@'%';
FLUSH PRIVILEGES;
