-- Миграция для добавления полей в таблицу teachers (сотрудники)
-- Для MariaDB/MySQL

ALTER TABLE teachers 
ADD COLUMN internal_phone VARCHAR(20) NULL COMMENT 'Внутренний телефон' AFTER full_name,
ADD COLUMN mobile_phone VARCHAR(20) NULL COMMENT 'Мобильный телефон' AFTER internal_phone,
ADD COLUMN room_id INT NULL COMMENT 'Кабинет' AFTER mobile_phone,
ADD COLUMN position VARCHAR(255) NULL COMMENT 'Должность' AFTER room_id,
ADD COLUMN email VARCHAR(255) NULL COMMENT 'Email' AFTER position,
ADD COLUMN comment TEXT NULL COMMENT 'Комментарий' AFTER email,
ADD FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL;
