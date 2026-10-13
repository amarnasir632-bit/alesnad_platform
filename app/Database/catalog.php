<?php
declare(strict_types=1);

function list_class_subjects(int $limit = 12): array
{
    $limit = max(1, min($limit, 50));
    $sql = 'SELECT cs.id AS class_subject_id, s.name AS subject_name,
                   c.name AS class_name, st.name AS stage_name,
                   COUNT(DISTINCT l.id) AS lesson_count
            FROM class_subjects cs
            JOIN subjects s ON s.id = cs.subject_id AND s.is_active
            JOIN classes c ON c.id = cs.class_id AND c.is_active
            JOIN stages st ON st.id = c.stage_id AND st.is_active
            LEFT JOIN lessons l ON l.class_subject_id = cs.id AND l.is_published
            WHERE cs.is_active
            GROUP BY cs.id, s.name, c.name, st.name, st.sort_order, c.sort_order
            ORDER BY st.sort_order, c.sort_order, s.name
            LIMIT :limit';
    $statement = database_connection()->prepare($sql);
    $statement->bindValue('limit', $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll();
}

function find_class_subject(int $id): ?array
{
    $statement = database_connection()->prepare(
        'SELECT cs.id AS class_subject_id, s.name AS subject_name, s.description,
                c.name AS class_name, st.name AS stage_name
         FROM class_subjects cs
         JOIN subjects s ON s.id = cs.subject_id AND s.is_active
         JOIN classes c ON c.id = cs.class_id AND c.is_active
         JOIN stages st ON st.id = c.stage_id AND st.is_active
         WHERE cs.id = :id AND cs.is_active'
    );
    $statement->execute(['id' => $id]);
    $subject = $statement->fetch();
    return $subject ?: null;
}
