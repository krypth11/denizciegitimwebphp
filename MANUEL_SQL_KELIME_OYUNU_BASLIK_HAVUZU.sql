-- DENİZCİ EĞİTİM / KELİME OYUNU
-- Soru bağlantısını "başlık + yeterlilik" yapısından "yalnızca başlık" yapısına çevirir.
-- BU DOSYA BİR KEZ ÇALIŞTIRILMALIDIR.
-- İşlemden önce veritabanı yedeği alın.

-- 1) Bilgi amaçlı ön kontrol.
-- category_id NULL olan kayıt varsa önce admin panelden bir başlığa taşıyın.
SELECT COUNT(*) AS basliksiz_soru_sayisi
FROM word_game_questions
WHERE category_id IS NULL OR category_id = '';

-- 2) Eski yeterlilik bazlı benzersiz indeksi kaldır ve qualification_id alanını opsiyonel yap.
ALTER TABLE word_game_questions
  DROP INDEX uq_word_game_question_unique,
  MODIFY qualification_id varchar(36) NULL DEFAULT NULL;

-- 3) Eski soruların yeterlilik bağlantısını temizle.
-- Sorular category_id değerlerini korur ve kendi başlıklarının ortak havuzuna dönüşür.
UPDATE word_game_questions
SET qualification_id = NULL;

-- 4) Yeni başlık bazlı sorgular için indeksler.
CREATE INDEX idx_word_game_questions_category_answer
  ON word_game_questions (category_id, answer_normalized);

CREATE INDEX idx_word_game_questions_category_active_answer
  ON word_game_questions (category_id, is_active, answer_normalized);

-- 5) Aynı başlıkta mevcut tekrarları kontrol et.
-- Sonuç dönmüyorsa aynı başlıkta tekrar yoktur.
SELECT category_id, answer_normalized, COUNT(*) AS tekrar_sayisi
FROM word_game_questions
WHERE category_id IS NOT NULL
  AND answer_normalized IS NOT NULL
  AND answer_normalized <> ''
GROUP BY category_id, answer_normalized
HAVING COUNT(*) > 1
ORDER BY tekrar_sayisi DESC, category_id, answer_normalized;

-- OPSİYONEL:
-- Yukarıdaki tekrar sorgusu hiç satır döndürmüyorsa veritabanı seviyesinde de
-- aynı başlık + aynı normalize cevap tekrarını engellemek için aşağıdaki komutu çalıştırabilirsiniz.
-- ALTER TABLE word_game_questions
--   ADD UNIQUE KEY uq_word_game_question_category_answer (category_id, answer_normalized);
