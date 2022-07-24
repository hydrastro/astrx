CREATE TABLE IF NOT EXISTS `page`
(
    `id`          VARCHAR(20) NOT NULL PRIMARY KEY,
    `file_name`   VARCHAR(20) NOT NULL,
    `title`       VARCHAR(50) NULL,
    `description` TEXT        NULL,
    `index`       TINYINT(1)  NOT NULL,
    `follow`      TINYINT(1)  NOT NULL,
    `controller`  TINYINT(1)  NOT NULL,
    `hidden`      TINYINT(1)  NOT NULL
);

CREATE TABLE `page_closure`
(
    `ancestor`   VARCHAR(20) NOT NULL,
    `descendant` VARCHAR(20) NOT NULL,
    FOREIGN KEY (ancestor) REFERENCES page (id),
    FOREIGN KEY (descendant) REFERENCES page (id)
);

CREATE TABLE `keyword`
(
    `id`      INT(11)    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `keyword` VARCHAR(255),
    `i18n`    TINYINT(1) NOT NULL
);

CREATE TABLE `page_keyword`
(
    `page_id`    VARCHAR(20) NOT NULL,
    `keyword_id` INT(11)     NOT NULL,
    FOREIGN KEY (page_id) REFERENCES page (id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (keyword_id) REFERENCES keyword (id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE `page_i18n_id`
(
    `id` VARCHAR(20) NOT NULL PRIMARY KEY
);


INSERT INTO `page`
(`id`,
 `file_name`,
 `title`,
 `description`,
 `index`,
 `follow`,
 `controller`,
 `hidden`)
VALUES ("WORDING_MAIN",
        "main",
        "Main Page",
        "This is my website main page.",
        TRUE,
        TRUE,
        TRUE,
        FALSE);

INSERT INTO `page_i18n_id`(`id`)
VALUES ("WORDING_MAIN");
INSERT INTO `page_closure`(`ancestor`, `descendant`)
VALUES ("WORDING_MAIN", "WORDING_MAIN");
