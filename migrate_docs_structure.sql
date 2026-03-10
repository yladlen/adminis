-- Миграция для улучшения структуры документации
-- Добавляем поддержку иерархической структуры

-- Добавляем новые поля в существующую таблицу
ALTER TABLE documentation
ADD COLUMN parent_id INT DEFAULT NULL,
ADD COLUMN order_index INT DEFAULT 0,
ADD COLUMN type ENUM('section', 'content') DEFAULT 'content',
ADD COLUMN description TEXT DEFAULT NULL,
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP;
-- Создаем индексы для оптимизации запросов
CREATE INDEX idx_docs_parent ON documentation(parent_id);
CREATE INDEX idx_docs_type ON documentation(type);
CREATE INDEX idx_docs_order ON documentation(order_index);

-- Обновляем существующие записи
UPDATE documentation SET
    type = 'content',
    order_index = id,
    updated_at = CURRENT_TIMESTAMP
WHERE id IN (1, 2);

-- Создаем процедуру для получения дерева документации
DELIMITER //

CREATE PROCEDURE GetDocumentationTree(IN root_id INT)
BEGIN
    WITH RECURSIVE doc_tree AS (
        SELECT 
            id,
            title,
            content,
            parent_id,
            type,
            order_index,
            description,
            0 as depth
        FROM documentation
        WHERE id = root_id
        UNION ALL
        SELECT 
            d.id,
            d.title,
            d.content,
            d.parent_id,
            d.type,
            d.order_index,
            d.description,
            dt.depth + 1
        FROM documentation d
        INNER JOIN doc_tree dt ON d.parent_id = dt.id
    )
    SELECT * FROM doc_tree ORDER BY order_index;
END //

DELIMITER ;

-- Создаем представление для плоского списка с уровнями вложенности
CREATE VIEW documentation_hierarchy AS
SELECT 
    d.id,
    d.title,
    d.content,
    d.parent_id,
    d.type,
    d.order_index,
    d.description,
    COUNT(p.id) as level
FROM documentation d
LEFT JOIN documentation p ON d.parent_id = p.id
GROUP BY d.id
ORDER BY d.order_index;