ALTER TABLE `relations`
  ADD COLUMN `is_divorced` TINYINT(1) NOT NULL DEFAULT 0 AFTER `spouse_order`;
