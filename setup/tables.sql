-- TABLE DEFINITIONS

CREATE TABLE `page`
(
    `id`         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `url_id`     VARCHAR(20) NOT NULL, # 255
    `i18n`       TINYINT     NOT NULL,
    `file_name`  VARCHAR(20) NOT NULL, # 255
    `controller` TINYINT     NOT NULL,
    `hidden`     TINYINT     NOT NULL
);

CREATE TABLE `page_robots`
(
    `page_id` INT     NOT NULL,
    `index`   TINYINT NOT NULL,
    `follow`  TINYINT NOT NULL,
    FOREIGN KEY (page_id) REFERENCES page (id)
);

CREATE TABLE `page_meta`
(
    `page_id`     INT          NOT NULL,
    `title`       VARCHAR(64)  NOT NULL,
    `description` VARCHAR(155) NOT NULL,
    FOREIGN KEY (page_id) REFERENCES page (id)
);

CREATE TABLE `page_closure`
(
    `ancestor`   INT NOT NULL,
    `descendant` INT NOT NULL,
    FOREIGN KEY (ancestor) REFERENCES page (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (descendant) REFERENCES page (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE `keyword`
(
    `id`      INT     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `keyword` VARCHAR(45),
    `i18n`    TINYINT NOT NULL
);

CREATE TABLE `page_keyword`
(
    `page_id`    INT NOT NULL,
    `keyword_id` INT NOT NULL,
    FOREIGN KEY (page_id) REFERENCES page (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (keyword_id) REFERENCES keyword (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);


-- TABLE INSERTIONS

-- ERROR PAGE IN POSITION 1

INSERT INTO `page` (id, url_id, i18n, file_name, controller, hidden)
VALUES (1, 'WORDING_MAIN', 1, 'main', 1, 0);

INSERT INTO `page_robots`(page_id, `index`, follow)
VALUES (1, 1, 1);

INSERT INTO `page_closure` (ancestor, descendant)
VALUES (1, 1);

INSERT INTO `keyword`(keyword, i18n)
VALUES ('ciao', 0),
       ('ASD', 1);
INSERT INTO `page_keyword`(page_id, keyword_id)
VALUES (1, 1),
       (1, 2);

