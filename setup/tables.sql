-- TABLE DEFINITIONS

CREATE TABLE `page`
(
    `id`         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `url_id`     VARCHAR(20) NOT NULL, # 255
    `i18n`       TINYINT     NOT NULL,
    `file_name`  VARCHAR(20) NOT NULL, # 255
    `template`   TINYINT     NOT NULL,
    `controller` TINYINT     NOT NULL,
    `hidden`     TINYINT     NOT NULL
);

CREATE TABLE `page_robots`
(
    `page_id` INT     NOT NULL PRIMARY KEY,
    `index`   TINYINT NOT NULL,
    `follow`  TINYINT NOT NULL,
    FOREIGN KEY (page_id) REFERENCES page (id)
);

CREATE TABLE `page_meta`
(
    `page_id`     INT          NOT NULL PRIMARY KEY,
    `title`       VARCHAR(64)  NOT NULL,
    `description` VARCHAR(155) NOT NULL,
    FOREIGN KEY (page_id) REFERENCES page (id)
);

CREATE TABLE `page_closure`
(
    `ancestor`   INT NOT NULL,
    `descendant` INT NOT NULL,
    PRIMARY KEY (ancestor, descendant),
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
    PRIMARY KEY (page_id, keyword_id),
    FOREIGN KEY (page_id) REFERENCES page (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (keyword_id) REFERENCES keyword (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);


CREATE TABLE `navigation_bar_ids`
(
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY
);

CREATE TABLE `navigation_bar_entry`
(
    `id`       INT         NOT NULL PRIMARY KEY,
    `internal` TINYINT     NOT NULL,
    `name`     VARCHAR(20) NOT NULL,
    `i18n`     TINYINT     NOT NULL,
    FOREIGN KEY (id) REFERENCES navigation_bar_ids (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE

);

CREATE TABLE `navigation_bar_internal`
(
    `id`      INT NOT NULL PRIMARY KEY,
    `page_id` INT NOT NULL,
    FOREIGN KEY (id) REFERENCES navigation_bar_entry (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES page (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

CREATE TABLE `navigation_bar_external`
(
    `id`  INT NOT NULL PRIMARY KEY,
    `url` VARCHAR(255),
    FOREIGN KEY (id) REFERENCES navigation_bar_entry (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);


CREATE TABLE `template`
(
    `id`        INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `file_name` VARCHAR(10) NOT NULL
);

CREATE TABLE `page_template`
(
    `page_id`     INT NOT NULL PRIMARY KEY,
    `template_id` INT NOT NULL,
    FOREIGN KEY (page_id) REFERENCES page (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES template (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
);

-- TABLE INSERTIONS

INSERT INTO `page` (url_id, i18n, file_name, template, controller, hidden)
VALUES ('WORDING_MAIN', 1, 'main', 1, 1, 0),
       ('WORDING_ERROR', 1, 'error', 1, 1, 0);

INSERT INTO `page_robots`(page_id, `index`, follow)
VALUES (1, 1, 1),
       (2, 0, 0);

INSERT INTO `page_closure` (ancestor, descendant)
VALUES (1, 1),
       (2, 2);

INSERT INTO `keyword`(keyword, i18n)
VALUES ('WORDING_MAIN_PAGE', 1),
       ('WORDING_ERROR', 2);

INSERT INTO `page_keyword`(page_id, keyword_id)
VALUES (1, 1),
       (2, 2);

INSERT INTO `navigation_bar_ids`() VALUE ();

INSERT INTO `navigation_bar_entry`(`id`, `internal`, `name`, `i18n`)
VALUES (1, 1, 'WORDING_MAIN', 1);

INSERT INTO `navigation_bar_internal`(`id`, `page_id`)
VALUES (1, 1);

-- INSERT INTO `template`();