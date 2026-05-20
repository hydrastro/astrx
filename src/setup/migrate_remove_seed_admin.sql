-- Remove the legacy seeded Administrator account if it was created by an older
-- setup SQL file. The setup wizard now creates the first administrator from the
-- submitted setup form. This is intentionally narrow: it deletes only the exact
-- historical seed account/hash, not a real admin whose password was changed.

DELETE FROM `user`
WHERE username = 'Administrator'
  AND password = '$argon2id$v=19$m=65536,t=4,p=1$b2Z2cnVLM0pSMy9xUVVicw$6KUaczD3Y6rGl28q61y6YXxriNmGqKv2I6xucl8rcSE'
  AND type = 1
  AND verified = 1
  AND deleted = 0;
