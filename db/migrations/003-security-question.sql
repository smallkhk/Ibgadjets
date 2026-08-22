-- =====================================================================
-- 003 — security question, for password recovery
--
-- There is no email and no SMS in this system, so a forgotten website
-- password has nowhere to send a reset link. A question the customer
-- answers themselves is the only recovery route that costs nothing and
-- needs no involvement from the operator.
--
-- The answer is hashed exactly like a password. It is a second secret,
-- and a leaked customers table should not hand out account access.
--
-- Both columns are NULL for existing customers. They have no question
-- yet, so they cannot use recovery — the dashboard prompts them to set
-- one, and until they do the operator has to reset by hand.
--
--   mysql -u USER -p DBNAME < db/migrations/003-security-question.sql
-- =====================================================================

ALTER TABLE customers
  ADD COLUMN security_question    VARCHAR(120) NULL AFTER password_hash,
  ADD COLUMN security_answer_hash VARCHAR(255) NULL AFTER security_question;
