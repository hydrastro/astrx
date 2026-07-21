-- Remove the leftover 'captcha-test' page: an unauthenticated, CSRF-less test
-- endpoint that was wired to the production captcha table. Idempotent — safe to
-- run repeatedly. After running this, delete the controller class file:
--   src/AstrX/Controller/CaptchaTestController.php
DELETE pc
  FROM `page_closure` pc
  JOIN `page` p ON (p.id = pc.ancestor OR p.id = pc.descendant)
 WHERE p.url_id = 'captcha-test';

DELETE FROM `page` WHERE url_id = 'captcha-test';
