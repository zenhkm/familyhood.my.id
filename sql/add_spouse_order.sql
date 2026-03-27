ALTER TABLE `relations`
  ADD COLUMN `spouse_order` INT NULL DEFAULT NULL AFTER `relation_type`;

CREATE INDEX `idx_relations_spouse_order`
  ON `relations` (`person_id`, `relation_type`, `spouse_order`);

-- Opsional: set nilai default untuk data lama yang belum punya urutan
-- UPDATE `relations`
-- SET `spouse_order` = 1
-- WHERE `relation_type` = 'pasangan' AND (`spouse_order` IS NULL OR `spouse_order` = 0);