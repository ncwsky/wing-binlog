-- 在mysql库中执行创建获取表字段信息存储过程

DROP PROCEDURE IF EXISTS `TableFields`;

DELIMITER //
CREATE DEFINER=`root`@`localhost` PROCEDURE `TableFields`(IN $schema varchar(50), IN $table varchar(50))
BEGIN
   SELECT
		COLUMN_NAME,
		COLLATION_NAME,
		CHARACTER_SET_NAME,
		COLUMN_COMMENT,
		COLUMN_TYPE,
		COLUMN_KEY
	FROM
		information_schema.COLUMNS
	WHERE
		table_schema = $schema AND table_name = $table;
END //
DELIMITER;