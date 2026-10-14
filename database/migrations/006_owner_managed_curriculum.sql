-- The owner chose to enter the launch curriculum manually. Remove the
-- provisional class seeds from migration 003 only when they have no content.
DELETE FROM classes c
USING stages st
WHERE st.id = c.stage_id
  AND ((st.code = 'PRIMARY' AND c.code = 'GRADE_6')
       OR (st.code = 'SECONDARY' AND c.code = 'GRADE_3_SECONDARY'))
  AND NOT EXISTS (SELECT 1 FROM class_subjects cs WHERE cs.class_id = c.id);

DELETE FROM stages st
WHERE st.code IN ('PRIMARY', 'MIDDLE', 'SECONDARY')
  AND NOT EXISTS (SELECT 1 FROM classes c WHERE c.stage_id = st.id);
